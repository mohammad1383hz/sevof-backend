<?php

namespace App\Services\Products\PageProduct;

/**
 * پاک‌سازی و نرمال‌سازی مشترک محتوای محصول.
 * هم خروجی AI (generator)، هم ویرایش کاربر (controller) و هم payload نهایی (publish) از همین‌جا می‌گذرند
 * تا یک قاعده‌ی واحد برای HTML، تگ‌ها، FAQ و عددها داشته باشیم.
 */
class ProductContentSanitizer
{
    /** نشانگرهایی که بلوک FAQ را در description مشخص می‌کنند (پلاگین هم همین‌ها را می‌نویسد). */
    public const FAQ_START = '<!--sevof-faq-->';
    public const FAQ_END = '<!--/sevof-faq-->';
    public const FAQ_HEADING = 'سوالات متداول';

    private const ALLOWED_TAGS = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'br'];

    /** این تگ‌ها با محتوایشان حذف می‌شوند؛ بقیه‌ی تگ‌های غیرمجاز فقط باز می‌شوند (متن می‌ماند). */
    private const DROP_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form'];

    /* ------------------------------------------------------------------ */
    /*  متن                                                                 */
    /* ------------------------------------------------------------------ */

    /** متن تک‌خطی: بدون تگ، فاصله‌ها یکدست، بدون < و >. */
    public static function plainLine(string $value): string
    {
        $value = str_replace(['<', '>'], '', strip_tags($value));

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * HTML ساده با whitelist (DOM-based): فقط تگ‌های مجاز، بدون هیچ attribute (onclick/style/href/...).
     * تگ‌های غیرمجاز باز می‌شوند و متنشان می‌ماند؛ script/style و مشابه با محتوا حذف می‌شوند.
     */
    public static function html(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $map = [0x80, 0x10FFFF, 0, 0x1FFFFF];
        $encoded = mb_encode_numericentity($html, $map, 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<body>'.$encoded.'</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $loaded ? $dom->getElementsByTagName('body')->item(0) : null;

        if (! $body) {
            return self::plainLine($html);
        }

        self::cleanNode($body);

        $out = '';

        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        $out = mb_decode_numericentity($out, $map, 'UTF-8');
        $out = (string) preg_replace('#<(p|li|h2|h3|strong|em|b|i)>\s*</\1>#u', '', $out);

        return trim($out);
    }

    private static function cleanNode(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);

                if (in_array($tag, self::DROP_TAGS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                self::cleanNode($child);

                if (in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->attributes && $child->attributes->length > 0) {
                        $child->removeAttributeNode($child->attributes->item(0));
                    }
                } else {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }

                    $node->removeChild($child);
                }
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    }

    /** کوتاه‌کردن روی مرز کلمه (نه وسط کلمه). */
    public static function limitAtWord(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $cut = mb_substr($value, 0, $max);

        // اگر کاراکتر بعدی فاصله باشد، برش دقیقاً سر مرز کلمه است
        if (! preg_match('/\s/u', mb_substr($value, $max, 1))) {
            $pos = mb_strrpos($cut, ' ');

            if ($pos !== false && $pos > $max * 0.6) {
                $cut = mb_substr($cut, 0, $pos);
            }
        }

        return (string) preg_replace('/[\s\-–—|،,:؛;]+$/u', '', $cut);
    }

    /* ------------------------------------------------------------------ */
    /*  تگ محصول                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, mixed>  $tags
     * @param  list<string>  $existing  برچسب‌های فعلی؛ تکراری‌ها حذف می‌شوند
     * @return list<string>
     */
    public static function tags(array $tags, array $existing = [], int $max = 6): array
    {
        $seen = [];

        foreach ($existing as $e) {
            $seen[PageProductGapDetectionService::norm((string) $e)] = true;
        }

        $out = [];

        foreach ($tags as $tag) {
            if (! is_scalar($tag)) {
                continue;
            }

            $tag = self::plainLine((string) $tag);
            $tag = (string) preg_replace('/^[\s#،,;؛]+|[\s#،,;؛]+$/u', '', $tag);
            $key = PageProductGapDetectionService::norm($tag);

            if ($key === '' || mb_strlen($tag) < 2 || mb_strlen($tag) > 40 || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $tag;

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  FAQ                                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{question:string,answer:string}>
     */
    public static function faq(array $rows, int $max = 5): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $q = self::plainLine((string) ($row['question'] ?? $row['q'] ?? ''));
            $a = self::plainLine((string) ($row['answer'] ?? $row['a'] ?? ''));
            $key = PageProductGapDetectionService::norm($q);

            if ($q === '' || $a === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if (! preg_match('/[?؟]$/u', $q)) {
                $q .= '؟';
            }

            $out[] = ['question' => $q, 'answer' => $a];

            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    /** HTML بلوک FAQ (بدون نشانگر؛ نشانگر را پلاگین/withFaq اضافه می‌کند). */
    public static function faqHtml(array $faq): string
    {
        $items = '';

        foreach (self::faq($faq, 8) as $row) {
            $items .= '<h3>'.self::esc($row['question']).'</h3><p>'.self::esc($row['answer']).'</p>';
        }

        return $items === '' ? '' : '<h2>'.self::FAQ_HEADING.'</h2>'.$items;
    }

    /* ------------------------------------------------------------------ */
    /*  لینک دسته‌بندی                                                      */
    /* ------------------------------------------------------------------ */

    /** تگ‌هایی که متن داخلشان لینک‌دار نمی‌شود (لینک تو در تو، تیتر، اسکریپت). */
    private const NO_LINK_INSIDE = 'a|h[1-6]|script|style|button|figcaption|textarea';

    /**
     * توضیحات را به صفحه‌ی دسته‌بندی محصول لینک می‌کند:
     *  - اگر از قبل لینکی به همان دسته دارد، دست نمی‌خورد.
     *  - وگرنه اولین اشاره به نام دسته در متن (خارج از لینک/تیتر) لینک می‌شود.
     *  - اگر نام دسته در متن نبود، یک جمله‌ی کوتاه لینک‌دار به انتها اضافه می‌شود.
     * فقط همان یک نقطه تغییر می‌کند و بقیه‌ی HTML (حتی محتوای خود فروشنده) بایت‌به‌بایت حفظ می‌شود.
     */
    public static function withCategoryLink(string $html, string $name, string $url, bool $persian = true): string
    {
        $name = trim($name);

        if ($name === '' || ! preg_match('#^https?://#i', $url) || self::hasLinkTo($html, $url)) {
            return $html;
        }

        $anchor = static fn (string $text): string => '<a href="'.self::esc($url).'">'.$text.'</a>';

        // متن و تگ‌ها یکی‌درمیان: اندیس زوج متن، اندیس فرد تگ/کامنت
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $skip = 0;

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                if (preg_match('#^<(/?)('.self::NO_LINK_INSIDE.')\b#i', $part, $m) && ! str_ends_with($part, '/>')) {
                    $skip = max(0, $skip + ($m[1] === '/' ? -1 : 1));
                }

                continue;
            }

            $pos = $skip === 0 ? mb_stripos($part, $name) : false;

            if ($pos !== false) {
                $length = mb_strlen($name);
                $parts[$i] = mb_substr($part, 0, $pos).$anchor(mb_substr($part, $pos, $length)).mb_substr($part, $pos + $length);

                return implode('', $parts);
            }
        }

        $link = $anchor(self::esc($name));
        $sentence = $persian ? "برای دیدن محصولات مشابه، به دسته‌ی {$link} سر بزنید." : $link;

        return trim(rtrim($html)."\n<p>{$sentence}</p>");
    }

    /** آیا HTML لینکی به این آدرس دارد؟ (http/https، www، اسلش انتها، encode فارسی و لینک نسبی یکسان حساب می‌شوند) */
    public static function hasLinkTo(string $html, string $url): bool
    {
        $target = self::comparableUrl($url);
        $targetPath = (string) preg_replace('#^[^/]*#', '', $target);

        preg_match_all('/href\s*=\s*(["\'])(.*?)\1/is', $html, $m);

        foreach ($m[2] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $candidate = str_starts_with($href, '/') && ! str_starts_with($href, '//')
                ? rtrim(mb_strtolower(rawurldecode((string) preg_replace('/[?#].*$/s', '', $href))), '/')
                : self::comparableUrl($href);

            if ($candidate !== '' && ($candidate === $target || ($targetPath !== '' && $candidate === $targetPath))) {
                return true;
            }
        }

        return false;
    }

    private static function comparableUrl(string $url): string
    {
        $url = mb_strtolower(rawurldecode(trim($url)));
        $url = (string) preg_replace('#^(https?:)?//(www\.)?#', '', $url);

        return rtrim((string) preg_replace('/[?#].*$/s', '', $url), '/');
    }

    /** بلوک FAQ قبلی (بین نشانگرها) حذف می‌شود. */
    public static function stripFaqBlock(string $descriptionHtml): string
    {
        $pattern = '/'.preg_quote(self::FAQ_START, '/').'.*?'.preg_quote(self::FAQ_END, '/').'/su';

        return trim((string) preg_replace($pattern, '', $descriptionHtml));
    }

    /** FAQ را (جایگزین بلوک قبلی) به انتهای توضیحات اضافه می‌کند؛ همان کاری که پلاگین سمت وردپرس می‌کند. */
    public static function withFaq(string $descriptionHtml, string $faqHtml): string
    {
        $base = self::stripFaqBlock($descriptionHtml);

        if (trim($faqHtml) === '') {
            return $base;
        }

        return trim($base."\n".self::FAQ_START.$faqHtml.self::FAQ_END);
    }

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }

    /* ------------------------------------------------------------------ */
    /*  عددها (هشدار «عدد اثبات‌نشده»)                                       */
    /* ------------------------------------------------------------------ */

    public static function latinDigits(string $v): string
    {
        return strtr($v, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    public static function persianDigits(string $v): string
    {
        return strtr($v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** @return list<string> عددهای ۲رقمی به بالا */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d{2,}/', self::latinDigits(strip_tags($text)), $m);

        return array_values(array_unique($m[0]));
    }

    /** همه‌ی متن‌هایی که ممکن است عدد ادعایی داشته باشند: توضیحات، FAQ و مقدار مشخصات. */
    public static function textForNumbers(array $result): string
    {
        $parts = [(string) ($result['short_description'] ?? ''), (string) ($result['description'] ?? '')];

        foreach ((array) ($result['faq'] ?? []) as $row) {
            if (is_array($row)) {
                $parts[] = (string) ($row['question'] ?? '');
                $parts[] = (string) ($row['answer'] ?? '');
            }
        }

        foreach ((array) ($result['attributes'] ?? []) as $a) {
            if (is_array($a)) {
                $parts[] = (string) ($a['value'] ?? '');
            }
        }

        return implode(' ', $parts);
    }
}
