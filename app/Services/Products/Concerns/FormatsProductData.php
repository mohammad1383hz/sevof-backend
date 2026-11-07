<?php

namespace App\Services\Products\Concerns;

use App\Models\ProductArticle;
use App\Models\SiteProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * قیمت، فرمت‌دهی داده‌ی محصول و متن‌سازی کمکی.
 * متدها از ثابت‌ها و helperهای ProductArticleWriterService (مثل compactName و productsTable)
 * و از BuildsArticleSeo (مثل trimDangling و endsWithDanglingWord) استفاده می‌کنند.
 *
 * ⚠️ productsTable() عمداً اینجا نیست؛ نسخه‌ی اصلی در ProductArticleWriterService است.
 */
trait FormatsProductData
{
    protected function normalizeForMatch(string $text): string
    {
        return str_replace(['ي', 'ك', "\u{200C}", "\u{00A0}", ' '], ['ی', 'ک', '', '', ''], $text);
    }

    /** ستون json (cast شده یا رشته) را همیشه به آرایه برمی‌گرداند. */
    protected function jsonField(SiteProduct $p, string $column): array
    {
        $v = $p->{$column} ?? null;

        return is_array($v = is_string($v) ? json_decode($v, true) : $v) ? $v : [];
    }

    /** متن ساده: تصویر حذف، لینک → انکرتکست، تگ‌ها و علائم Markdown حذف. */
    protected function plainText(string $content): string
    {
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', $content);
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', (string) $text);
        $text = preg_replace('#</(?:p|div|li|h[1-6]|tr|td|th)>|<br\s*/?>#iu', ' ', (string) $text);
        $text = preg_replace('/[#*`>|]+/u', '', strip_tags((string) $text));

        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * نام محصول؛ اگر از سقف بلندتر بود هرگز وسط کلمه بریده نمی‌شود و شماره‌ی مدل حفظ می‌شود
     * (compactName در سرویس: برش روی مرز کلمه + حذف حرف اضافه‌ی آخر + نگه داشتن توکن مدل).
     */
    protected function name(SiteProduct $p, int $limit = 90): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $p->name));

        return mb_strlen($name) <= $limit ? $name : $this->compactName($p, $limit);
    }

    protected function price(SiteProduct $p): int
    {
        return (int) round((float) $p->price);
    }

    /** کلید مرتب‌سازی قیمت: محصولِ بدون قیمت همیشه آخر لیست می‌رود (نه ارزان‌ترین). */
    protected function sortPrice(?SiteProduct $p): int
    {
        return $p && $this->price($p) > 0 ? $this->price($p) : PHP_INT_MAX;
    }

    /** محصولات از ارزان به گران (ایندکس 0..n). */
    protected function rankByPrice(Collection $products): Collection
    {
        return $products->sortBy(fn (SiteProduct $p) => $this->sortPrice($p))->values();
    }

    protected function formatNumber(int $n): string
    {
        return number_format($n, 0, '.', ',');
    }

    /** Price unit shown to the model: the product's ISO currency code (e.g. USD, EUR, AED). */
    protected function unitFor(SiteProduct $p): string
    {
        $code = strtoupper(trim((string) ($p->currency ?? '')));

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : static::PRICE_UNIT;
    }

    protected function formatPrice(SiteProduct $p, ?int $amount = null): string
    {
        $amount ??= $this->price($p);

        return $amount > 0 ? rtrim($this->formatNumber($amount).' '.$this->unitFor($p)) : 'price on request';
    }

    /**
     * برچسب ترتیب قیمتی بدون عدد رتبه (تا «رتبه ۱ از ۵» هرگز وارد متن مقاله نشود).
     * $index از صفر و بر اساس ترتیب ارزان به گران است.
     */
    protected function priceOrderLabel(int $index, int $total): string
    {
        return match (true) {
            $total < 2 => 'only product',
            $index === 0 => 'cheapest of the group',
            $index === $total - 1 => 'most expensive of the group',
            default => 'middle of the range',
        };
    }

    /** جایگاه قیمتی نسبت به همسایه‌ها؛ اختلاف‌ها با کد محاسبه می‌شوند تا مدل حدس نزند. */
    protected function pricePosition(SiteProduct $p, Collection $ranked): string
    {
        $idx = $ranked->search(fn (SiteProduct $x) => $x->id === $p->id);

        if ($idx === false) {
            return '';
        }

        $mine = $this->price($p);
        $unit = $this->unitFor($p);
        $lines = ['PRICE POSITION (computed; express it in plain words, never as a number or rank): '.$this->priceOrderLabel($idx, $ranked->count())];

        $cheaper = $idx > 0 ? $ranked[$idx - 1] : null;
        $pricier = $ranked[$idx + 1] ?? null;

        if ($mine > 0 && $cheaper && $this->price($cheaper) > 0) {
            $lines[] = "next cheaper: {$this->name($cheaper, 70)} — {$this->formatPrice($cheaper)}; this product costs "
                .$this->formatNumber($mine - $this->price($cheaper))." {$unit} more";
        }

        if ($mine > 0 && $pricier && $this->price($pricier) > 0) {
            $lines[] = "next pricier: {$this->name($pricier, 70)} — {$this->formatPrice($pricier)}; it costs "
                .$this->formatNumber($this->price($pricier) - $mine)." {$unit} more than this one";
        }

        return implode("\n", $lines);
    }

    /** آمار قیمت کل لیست (محاسبه‌ی کد). */
    protected function priceFacts(Collection $ranked): string
    {
        $priced = $ranked->filter(fn ($p) => $this->price($p) > 0)->values();

        if ($priced->count() < 2) {
            return '';
        }

        [$min, $max] = [$priced->first(), $priced->last()];
        $unit = $this->unitFor($min);
        $lines = [
            'PRICE FACTS (computed by code, exact):',
            "cheapest: {$this->name($min, 70)} — {$this->formatPrice($min)}",
            "most expensive: {$this->name($max, 70)} — {$this->formatPrice($max)}",
            'spread: '.$this->formatNumber($this->price($max) - $this->price($min))." {$unit}",
        ];

        for ($i = 1; $i < $priced->count(); $i++) {
            $lines[] = "step {$i}: {$this->name($priced[$i - 1], 40)} → {$this->name($priced[$i], 40)} = +"
                .$this->formatNumber($this->price($priced[$i]) - $this->price($priced[$i - 1]))." {$unit}";
        }

        return implode("\n", $lines);
    }

    /** مشخصات محصول از raw_data['attributes'] (فرمت ووکامرس: name + options). */
    protected function attributeLines(SiteProduct $p, int $max = 12): array
    {
        $lines = [];

        foreach ((array) ($this->jsonField($p, 'raw_data')['attributes'] ?? []) as $k => $v) {
            [$name, $val] = is_array($v)
                ? [$v['name'] ?? $v['key'] ?? null, $v['options'] ?? $v['value'] ?? null]
                : [is_string($k) ? $k : null, $v];

            if (is_array($val)) {
                $val = implode('، ', array_filter(array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $val)));
            }

            if (! is_string($name) || ! is_scalar($val) || trim((string) $val) === '') {
                continue;
            }

            $lines[] = trim($name).': '.Str::limit(trim((string) $val), 80, '');

            if (count($lines) >= $max) {
                break;
            }
        }

        return $lines;
    }

    /** id → نام دسته از categories خود مقاله. */
    protected function categoryMap(ProductArticle $article): array
    {
        return collect((array) $article->categories)
            ->filter(fn ($c) => is_array($c) && isset($c['id'], $c['name']))
            ->pluck('name', 'id')
            ->all();
    }

    protected function productFacts(SiteProduct $p, array $categoryMap = []): string
    {
        $lines = ['name: '.trim($p->name)];

        if ($this->price($p) > 0) {
            $lines[] = 'price: '.$this->formatPrice($p);

            // فقط اگر در دیتابیس واقعاً تخفیف ثبت شده باشد
            if ((float) $p->regular_price > (float) $p->price) {
                $lines[] = 'regular price before discount (data says this product is discounted): '
                    .$this->formatPrice($p, (int) round((float) $p->regular_price));
            }
        }

        foreach (array_merge([$p->category_id], $this->jsonField($p, 'category_ids')) as $cid) {
            if ($cid !== null && isset($categoryMap[$cid])) {
                $lines[] = 'category: '.$categoryMap[$cid];
                break;
            }
        }

        foreach ($this->attributeLines($p) as $attr) {
            $lines[] = "attribute — {$attr}";
        }

        $desc = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(
            strip_tags(trim(($p->short_description ?? '').' '.($p->description ?? ''))),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )));

        if ($desc !== '') {
            $lines[] = 'description: '.Str::limit($desc, 1200, '…');
        }

        return implode("\n", $lines);
    }

    /**
     * angle خام AI را برای ستون «مشخصات» جدول تمیز می‌کند:
     * - «مناسب برای» ابتدای جمله حذف می‌شود؛
     * - متن روی «؛» و «.» (فقط نقطه‌ی پایان جمله، نه اعشار مثل 2.4GHz) به بخش‌ها شکسته می‌شود؛
     * - بخش کوتاه، دارای واژه‌ی ممنوع یا ناقص (تمام‌شده با «برای/با/از/...») حذف می‌شود.
     * اگر چیزی نماند null برمی‌گردد (و جایگاه قیمتی با کد جایگزین می‌شود).
     */
    protected function cleanAngle(?string $angle): ?string
    {
        $kept = [];

        foreach (preg_split('/[؛;\n]+|\.(?=\s|$)/u', trim((string) $angle)) ?: [] as $segment) {
            $segment = (string) preg_replace('/^\s*مناسب\s+برای\s+/u', '', $segment);
            // trim بایت‌محور با کاراکتر فارسی UTF-8 را خراب می‌کند؛ با regex و /u
            $segment = trim((string) preg_replace('/^[\s،,:\-—]+|[\s،,:\-—]+$/u', '', $segment));
            $haystack = $this->normalizeForMatch($segment);

            if (
                mb_strlen($segment) >= 6
                && ! $this->endsWithDanglingWord($segment)
                && ! array_filter(static::ANGLE_BANNED, fn ($w) => str_contains($haystack, $this->normalizeForMatch($w)))
            ) {
                $kept[] = $segment;
            }
        }

        return $kept ? implode('؛ ', $kept) : null;
    }

    /** جایگاه قیمتی با کد؛ اگر همه هم‌قیمت یا قیمت نامشخص باشد خالی برمی‌گردد. */
    protected function priceTierNote(int $price, Collection $prices): string
    {
        if ($price <= 0 || $prices->count() < 2 || $prices->min() === $prices->max()) {
            return '';
        }

        return match (true) {
            $price === $prices->min() => $this->t('ارزان‌ترین گزینه‌ی این مقایسه'),
            $price === $prices->max() => $this->t('گران‌ترین گزینه‌ی این مقایسه'),
            default => $this->t('قیمت در رده‌ی میانی'),
        };
    }

    /** دُم متن (برای پیوستگی بین بخش‌ها). */
    protected function tail(string $markdown, int $chars): string
    {
        $text = $this->plainText($markdown);

        return mb_strlen($text) <= $chars ? $text : '…'.ltrim(mb_substr($text, -$chars));
    }
}
