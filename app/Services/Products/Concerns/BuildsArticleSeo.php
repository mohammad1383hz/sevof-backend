<?php

namespace App\Services\Products\Concerns;

use App\Models\ProductArticle;
use App\Models\SiteProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * سئوی مقاله‌ی محصول بدون AI: اسکیما (ItemList / FAQPage)، متا، اسلاگ، هدینگ‌ها و گزارش سئو.
 *
 * از helperهای ProductArticleWriterService و FormatsProductData استفاده می‌کند
 * (plainText، loadProducts، price، name، compactName) و از ثابت‌های کلاس
 * (HEADINGS، HEADING_KEYWORD_TEMPLATES، MAX_HEADING_CHARS، HARD_MAX_HEADING_CHARS، DANGLING_WORDS، ...).
 *
 * ⚠️ در این فایل هرگز trim/rtrim با لیست کاراکتر فارسی نزنید (بایت‌محور است و UTF-8 را خراب می‌کند)؛
 *    از trimPunctuation() / trimDangling() استفاده کنید.
 */
trait BuildsArticleSeo
{
    // ===============================================================
    //  اسکیما
    // ===============================================================

    /** ItemList از محصولات واقعی مقاله به ترتیب نمایش در صفحه (فقط داده‌ی دیتابیس). */
    protected function buildProductSchema(ProductArticle $article): ?array
    {
        $items = [];

        foreach ($this->productsInPageOrder($article) as $i => $p) {
            $item = ['@type' => 'Product', 'name' => trim((string) $p->name)];

            if ($p->url) {
                $item['url'] = $p->url;
            }

            if ($image = $this->productImage($p)) {
                $item['image'] = $image;
            }

            if ($description = $this->schemaDescription($p)) {
                $item['description'] = $description;
            }

            $sku = trim((string) ($p->sku ?? ''));
            if ($sku !== '') {
                $item['sku'] = $sku;
            }

            if ($this->price($p) > 0) {
                [$price, $currency] = $this->schemaPrice($p);

                $item['offers'] = array_filter([
                    '@type' => 'Offer',
                    'price' => $price,
                    'priceCurrency' => $currency,
                    'availability' => $this->schemaAvailability($p),
                    'url' => $p->url ?: null,
                ]);
            }

            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => $item];
        }

        if ($items === []) {
            return null;
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $this->cleanLine($article->title),
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ]);
    }

    /** محصولات به ترتیب بخش‌های product در صفحه (جایگاه ListItem باید با صفحه یکی باشد). */
    protected function productsInPageOrder(ProductArticle $article): Collection
    {
        $products = $this->loadProducts($article);

        $order = $article->sections()
            ->where('type', 'product')
            ->orderBy('position')
            ->get(['id', 'product_ids'])
            ->map(function ($s) {
                $ids = $s->product_ids;
                $ids = is_string($ids) ? (json_decode($ids, true) ?: []) : $ids;

                return (int) (((array) $ids)[0] ?? 0);
            })
            ->filter()
            ->unique()
            ->values();

        $ordered = $order->map(fn ($id) => $products->get($id))->filter();
        $rest = $products->reject(fn ($p) => $order->contains((int) $p->id));

        return $ordered->concat($rest)->values();
    }

    /** اولین آدرس تصویر معتبر (ستون‌های متداول یا لیست images). */
    protected function productImage(SiteProduct $p): ?string
    {
        foreach ([$p->image ?? null, $p->image_url ?? null, $p->featured_image ?? null, $p->thumbnail ?? null] as $single) {
            if (is_string($single) && preg_match('#^https?://#i', $single)) {
                return $single;
            }
        }

        foreach ($this->jsonField($p, 'images') as $img) {
            $img = is_array($img) ? ($img['src'] ?? $img['url'] ?? null) : $img;

            if (is_string($img) && preg_match('#^https?://#i', $img)) {
                return $img;
            }
        }

        return null;
    }

    /** توضیح کوتاه و تمیز محصول برای schema (متن خود فروشگاه، بدون HTML). */
    protected function schemaDescription(SiteProduct $p, int $max = 300): ?string
    {
        $raw = trim(($p->short_description ?? '').' '.($p->description ?? ''));
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : $this->fitLength($text, $max);
    }

    /** موجودی فقط وقتی در دیتابیس مشخص است؛ حدس نمی‌زنیم (ادعای ناصحیح موجودی خطر دارد). */
    protected function schemaAvailability(SiteProduct $p): ?string
    {
        return match (strtolower(trim((string) ($p->stock_status ?? '')))) {
            'instock', 'in_stock' => 'https://schema.org/InStock',
            'outofstock', 'out_of_stock' => 'https://schema.org/OutOfStock',
            'onbackorder', 'on_backorder' => 'https://schema.org/BackOrder',
            default => null,
        };
    }

    /** @return array{0:string,1:string} قیمت و ارز برای schema.org */
    protected function schemaPrice(SiteProduct $p): array
    {
        $currency = strtoupper(trim((string) ($p->currency ?? '')));
        $price = $this->price($p);

        return match (true) {
            in_array($currency, ['', 'IRT', 'TOMAN', 'TMN'], true) => [(string) ($price * static::PRICE_SCHEMA_MULTIPLIER), 'IRR'],
            $currency === 'IRR' => [(string) $price, 'IRR'],
            default => [rtrim(rtrim(number_format((float) $p->price, 2, '.', ''), '0'), '.'), $currency],
        };
    }

    /** FAQPage از بخش «سوالات متداول» (H2) و سؤال‌های H3 آن. */
    protected function buildFaqSchema(string $content): ?array
    {
        $content = str_replace("\r\n", "\n", $content);
        $heading = preg_quote(static::HEADINGS['faq'], '/');

        if (! preg_match('/^##(?!#)[^\n]*(?:'.$heading.'|سؤالات متداول|پرسش‌های متداول)[^\n]*$/mu', $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $rest = substr($content, $m[0][1] + strlen($m[0][0]));

        if (preg_match('/^#{1,2}(?!#)[ \t]*\S/mu', $rest, $next, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, $next[0][1]);
        }

        preg_match_all('/^###[ \t]+(.+?)[ \t]*\n(.*?)(?=^###[ \t]|\z)/msu', $rest, $pairs, PREG_SET_ORDER);

        $items = [];
        $seen = [];

        foreach ($pairs as [, $q, $a]) {
            [$q, $a] = [$this->plainText($q), $this->plainText($a)];
            $key = $this->seoNormalize($q);

            if ($q === '' || mb_strlen($a) < 20 || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];

            if (count($items) >= 10) {
                break;
            }
        }

        return $items === [] ? null : ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
    }

    // ===============================================================
    //  متن و عبارت کلیدی
    // ===============================================================

    /** حذف تگ، کاراکترهای خطرناک، حروف عربی و فاصله‌های اضافه. */
    protected function cleanLine(mixed $value): string
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(['ي', 'ك', '<', '>', '"', '“', '”'], ['ی', 'ک', '', '', '', '', ''], $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** حذف علائم نگارشی ابتدا و انتهای متن (مخصوص UTF-8؛ جایگزین trim بایت‌محور). */
    protected function trimPunctuation(string $text): string
    {
        return trim((string) preg_replace('/^[\s\-–—,.،:;؛]+|[\s\-–—,.،:;؛]+$/u', '', $text));
    }

    /** آیا آخرین کلمه‌ی متن یک حرف اضافه/ربط است (یعنی عبارت ناقص است)؟ */
    protected function endsWithDanglingWord(string $text): bool
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words !== [] && in_array(end($words), static::DANGLING_WORDS, true);
    }

    /** علائم و حرف‌های اضافه‌ی انتهای عبارت را برمی‌دارد (UTF-8 safe). «؟» دست نمی‌خورد. */
    protected function trimDangling(string $text): string
    {
        $text = trim($text);

        do {
            $before = $text;
            $text = trim((string) preg_replace('/[\s\.\-–—:،؛,]+$/u', '', $text));
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if (count($words) > 1 && in_array(end($words), static::DANGLING_WORDS, true)) {
                array_pop($words);
                $text = implode(' ', $words);
            }
        } while ($text !== $before);

        return $text;
    }

    /** کوتاه‌سازی روی مرز کلمه؛ هرگز با حرف اضافه («برای»، «با»، ...) یا علامت نگارشی تمام نمی‌شود. */
    protected function fitLength(string $text, int $max): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);

        // اگر وسط یک کلمه بریده شده، تا آخرین فاصله عقب برو
        if (mb_substr($text, $max, 1) !== ' ') {
            $lastSpace = mb_strrpos($cut, ' ');

            if ($lastSpace !== false && $lastSpace > 0) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
        }

        return $this->trimDangling($cut);
    }

    /** نرمال‌سازی برای تطبیق: یکدست کردن ی/ک، حذف نیم‌فاصله و فاصله، حروف کوچک. */
    protected function seoNormalize(string $text): string
    {
        $text = mb_strtolower(str_replace(['ي', 'ك'], ['ی', 'ک'], $text));

        return str_replace(["\u{200C}", "\u{200F}", "\u{00A0}", ' ', "\t", "\n", "\r"], '', $text);
    }

    /** آیا عبارت کلیدی عیناً (مستقل از نیم‌فاصله/فاصله/حروف) در متن هست؟ */
    protected function keywordIn(string $text, string $keyword): bool
    {
        $needle = $this->seoNormalize(trim($keyword));

        return $needle !== '' && str_contains($this->seoNormalize($text), $needle);
    }

    protected function keywordCount(string $text, string $keyword): int
    {
        $needle = $this->seoNormalize(trim($keyword));

        return $needle === '' ? 0 : substr_count($this->seoNormalize($text), $needle);
    }

    /** آیا عبارت کلیدی در ۳۰۰ کاراکتر اول متن (جمله‌ی اول و دوم) هست؟ */
    protected function keywordInOpening(string $content, string $keyword, int $chars = 300): bool
    {
        return $this->keywordIn(mb_substr($this->plainText($content), 0, $chars), $keyword);
    }

    /** عبارت کلیدی اضطراری از روی عنوان (چهار کلمه‌ی اول). */
    protected function keywordFromTitle(string $title): string
    {
        $words = preg_split('/\s+/u', $this->cleanLine($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, 4));
    }

    /** متن ساده‌ی بخش اول (مقدمه) مقاله؛ اگر H2 نبود کل متن. */
    protected function introText(string $content): string
    {
        $content = str_replace("\r\n", "\n", $content);

        if (preg_match('/^##(?!#)[^\n]*\n(.*?)(?=^##(?!#)|\z)/msu', $content, $m)) {
            return $this->plainText($m[1]);
        }

        return $this->plainText($content);
    }

    /** آیا متن عدد قیمت (عدد + واحد پول) دارد؟ */
    protected function containsPrice(string $text): bool
    {
        return (bool) preg_match('/[\d۰-۹][\d۰-۹٬,\.]*\s*(?:(?:هزار|میلیون|میلیارد)\s*)?(?:تومان|ریال|دلار|یورو)/u', $text);
    }

    /**
     * اگر عبارت کلیدی در متن نبود، به ابتدای آن اضافه می‌شود؛ هنگام کوتاه‌سازی انتهای متن بریده می‌شود، نه عبارت کلیدی.
     */
    protected function ensureKeywordAtStart(string $text, string $keyword, int $max): string
    {
        [$text, $keyword] = [trim($text), trim($keyword)];

        if ($keyword === '') {
            return $this->fitLength($text, $max);
        }

        if ($text === '') {
            return $this->fitLength($keyword, $max);
        }

        $fit = $this->fitLength($text, $max);

        if (! $this->keywordIn($fit, $keyword)) {
            $fit = $this->fitLength($keyword.': '.$text, $max);
        }

        return $fit;
    }

    /** سازگاری با نسخه‌ی قدیمی. */
    protected function ensureKeywordPresent(string $text, string $keyword, int $max): string
    {
        return $this->ensureKeywordAtStart($text, $keyword, $max);
    }

    /** اسلاگ: Unicode-safe، stop-word لاتین حذف، سقف کاراکتر و سقف طول URL-encoded (ستون post_name وردپرس ۲۰۰ کاراکتر است). */
    protected function slugify(string $value, int $max = 60): string
    {
        $value = mb_strtolower($this->cleanLine($value));
        $value = str_replace(["\u{200C}", '_'], '-', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $value);
        $parts = array_values(array_filter(explode('-', trim($value, '-')), fn ($w) => $w !== ''));

        $stop = ['a', 'an', 'the', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'with', 'is', 'are'];
        $filtered = array_values(array_filter($parts, fn ($w) => ! in_array($w, $stop, true)));

        if (count($filtered) >= 2) {
            $parts = $filtered;
        }

        $slug = '';

        foreach ($parts as $word) {
            $candidate = $slug === '' ? $word : $slug.'-'.$word;

            if (mb_strlen($candidate) > $max || strlen(rawurlencode($candidate)) > 180) {
                break;
            }

            $slug = $candidate;
        }

        if ($slug === '' && $parts !== []) {
            $slug = mb_substr($parts[0], 0, min($max, 25));
        }

        return $slug;
    }

    /** سرفصل‌های H2/H3 به‌صورت لیست فشرده. */
    protected function extractHeadings(string $content, int $max = 30): string
    {
        $content = str_replace("\r\n", "\n", $content);
        preg_match_all('/^(#{2,3})[ \t]+(.+)$/mu', $content, $matches, PREG_SET_ORDER);

        $lines = [];

        foreach ($matches as [, $hashes, $title]) {
            $title = trim((string) preg_replace('/[*`_#]+/u', '', $title));

            if ($title !== '') {
                $lines[] = (strlen($hashes) === 2 ? '- ' : '  - ').$title;
            }
        }

        return implode("\n", array_slice($lines, 0, $max));
    }

    // ===============================================================
    //  هدینگ‌ها
    // ===============================================================

    /**
     * سئوی هدینگ‌ها مستقل از رفتار AI: پاک‌سازی، یکتا بودن، سؤال‌های FAQ با «؟»،
     * و گذاشتن عبارت کلیدی در هدینگ مقایسه/سؤالات/جمع‌بندی (در صورت نبودن).
     * هدینگ محصول‌ها بعداً با applyProductHeadings() ساخته می‌شود.
     */
    protected function applySeoHeadings(array $assignments, string $keyword, Collection $products): array
    {
        $seen = [];

        foreach ($assignments as &$a) {
            $heading = $this->cleanHeading((string) $a['heading']);
            $key = $this->seoNormalize($heading);

            // هدینگ محصول خالی/تکراری → نام فشرده (هدینگ نهایی را بعداً applyProductHeadings می‌سازد)
            if ($a['type'] === 'product' && ($heading === '' || isset($seen[$key]))) {
                $p = $products->get((int) ($a['product_ids'][0] ?? 0));
                $heading = $p ? $this->cleanHeading($this->compactName($p, static::MAX_HEADING_CHARS)) : $this->t('بررسی');
                $key = $this->seoNormalize($heading);
            }

            $seen[$key] = true;
            $a['heading'] = $heading !== '' ? $heading : $this->t('بررسی');
            $a['subheadings'] = $a['type'] === 'faq'
                ? $this->cleanQuestions((array) $a['subheadings'])
                : array_values(array_filter(array_map(fn ($h) => $this->cleanHeading((string) $h), (array) $a['subheadings'])));
        }
        unset($a);

        $keyword = trim($keyword);

        if ($keyword === '' || mb_strlen($keyword) > 40) {
            return $assignments;
        }

        foreach ($assignments as &$a) {
            $template = static::HEADING_KEYWORD_TEMPLATES[$a['type']] ?? null;

            if ($template && ! $this->keywordIn($a['heading'], $keyword)) {
                $a['heading'] = $this->cleanHeading(sprintf($this->t($template), $keyword));
            }
        }
        unset($a);

        return $assignments;
    }

    /**
     * پاک‌سازی هدینگ. سقف پیش‌فرض HARD_MAX_HEADING_CHARS فقط شبکه‌ی ایمنی است؛
     * هدینگ‌های معمولی دست نمی‌خورند و هدینگ نهایی هیچ‌وقت با حرف اضافه یا علامت تمام نمی‌شود.
     */
    protected function cleanHeading(string $heading, ?int $max = null): string
    {
        $max ??= static::HARD_MAX_HEADING_CHARS;

        $h = strip_tags($heading);
        $h = str_replace(['ي', 'ك'], ['ی', 'ک'], $h);
        $h = (string) preg_replace('/\s+vs\.?\s+/iu', $this->t(' در برابر '), $h);
        $h = (string) preg_replace('/\s*&\s*/u', $this->t(' و '), $h);
        $h = (string) preg_replace('/[#*`"“”«»]+/u', '', $h);
        $h = $this->trimPunctuation((string) preg_replace('/\s+/u', ' ', $h));

        if (mb_strlen($h) > $max) {
            $h = $this->shortenHeading($h, $max);
        }

        return $this->trimDangling($h);
    }

    /** هدینگ بیش از سقف: اول روی آخرین مرز عبارت («؛» «:» «،» « - ») می‌برد، وگرنه روی مرز کلمه. */
    protected function shortenHeading(string $heading, int $max): string
    {
        $window = mb_substr($heading, 0, $max + 1);
        $pos = 0;

        foreach (['؛', ':', '،', ' - ', ' – ', ' — '] as $sep) {
            $p = mb_strrpos($window, $sep);

            if ($p !== false && $p > $pos) {
                $pos = $p;
            }
        }

        if ($pos >= (int) ($max * 0.4)) {
            $cut = $this->trimDangling(mb_substr($heading, 0, $pos));

            if ($cut !== '') {
                return $cut;
            }
        }

        return $this->fitLength($heading, $max);
    }

    /** سؤال‌های FAQ: پرسشی کامل با «؟»، بدون تکرار؛ سؤال خیلی بلند حذف می‌شود (نه بریده). */
    protected function cleanQuestions(array $questions): array
    {
        $out = [];
        $seen = [];

        foreach ($questions as $q) {
            $q = $this->cleanHeading((string) $q, 400);

            if (mb_strlen($q) > 160) {
                continue;
            }

            $q = trim((string) preg_replace('/[?؟\s]+$/u', '', $q)).'؟';
            $key = $this->seoNormalize($q);

            if ($q === '؟' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $q;
        }

        return array_slice($out, 0, 6);
    }

    // ===============================================================
    //  گزارش سئو (فقط لاگ)
    // ===============================================================

    /** @return array<string, mixed> */
    protected function seoStats(string $content, string $keyword): array
    {
        $content = str_replace("\r\n", "\n", $content);
        $plain = $this->plainText($content);
        $words = max(1, count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $kwWords = max(1, count(preg_split('/\s+/u', trim($keyword), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $hits = $this->keywordCount($plain, $keyword);

        preg_match_all('/^##(?!#)[ \t]+(.+)$/mu', $content, $m);
        $h2 = $m[1] ?? [];

        return [
            'total_words' => $words,
            'keyword_hits' => $hits,
            'density_pct' => round($hits * $kwWords / $words * 100, 2),
            'in_intro' => $this->keywordInOpening($this->introText($content), $keyword),
            'h2_total' => count($h2),
            'h2_with_keyword' => count(array_filter($h2, fn ($h) => $this->keywordIn($h, $keyword))),
            'h2_incomplete' => count(array_filter($h2, fn ($h) => $this->endsWithDanglingWord((string) $h))),
        ];
    }

    protected function logSeoReport(ProductArticle $article, array $meta, string $content): void
    {
        $keyword = (string) ($meta['focus_keyword'] ?? '');
        $stats = $this->seoStats($content, $keyword);
        $titleLen = mb_strlen((string) ($meta['meta_title'] ?? ''));
        $descLen = mb_strlen((string) ($meta['meta_description'] ?? ''));
        $warnings = [];

        if ($keyword === '') {
            $warnings[] = 'keyword_empty';
        }
        if ($stats['density_pct'] > 2.5) {
            $warnings[] = 'keyword_density_high';
        }
        if ($keyword !== '' && $stats['density_pct'] < 0.4) {
            $warnings[] = 'keyword_density_low';
        }
        if ($keyword !== '' && ! $stats['in_intro']) {
            $warnings[] = 'keyword_missing_in_intro';
        }
        if ($stats['h2_total'] > 0) {
            $ratio = $stats['h2_with_keyword'] / $stats['h2_total'];
            if ($ratio < 0.25) {
                $warnings[] = 'keyword_in_too_few_h2';
            } elseif ($ratio > 0.6) {
                $warnings[] = 'keyword_in_too_many_h2';
            }
        }
        if ($stats['h2_incomplete'] > 0) {
            $warnings[] = 'heading_incomplete';
        }
        if ($titleLen < 30 || $titleLen > 60) {
            $warnings[] = 'meta_title_length';
        }
        if ($descLen < 100 || $descLen > 160) {
            $warnings[] = 'meta_description_length';
        }
        if (empty($meta['faq_schema'])) {
            $warnings[] = 'faq_schema_missing';
        }
        if (empty($meta['product_schema'])) {
            $warnings[] = 'product_schema_missing';
        }

        $level = $warnings ? 'warning' : 'info';

        Log::$level('ai.product_article_writer.meta.seo_report', [
            'article_id' => $article->id,
            'keyword' => $keyword,
            'stats' => $stats,
            'meta_title_len' => $titleLen,
            'meta_description_len' => $descLen,
            'slug' => $meta['slug'] ?? null,
            'warnings' => $warnings,
        ]);
    }
}
