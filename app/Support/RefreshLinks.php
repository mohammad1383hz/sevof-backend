<?php

namespace App\Support;

/**
 * لینک داخلی تازه در به‌روزرسانی مقاله: AI فقط نشانه‌ی [[Ln|متن لینک]] می‌نویسد (Ln یکی از صفحه‌های پیشنهادی)،
 * آدرس را خود کد می‌گذارد. نشانه تا آخرِ همه‌ی بررسی‌های ایمنی متن ساده است و فقط در خروجی نهایی لینک می‌شود.
 * لینک نامعتبر (صفحه‌ی ناشناخته، تکراری، داخل تیتر، متن لینک کلی مثل «اینجا») به متن ساده برمی‌گردد.
 */
final class RefreshLinks
{
    /** حداکثر لینک داخلی تازه در یک به‌روزرسانی؛ بیشتر از این، متن را تبلیغاتی و بی‌کیفیت می‌کند. */
    public const MAX_NEW = 5;

    protected const MARKER = '/\[\[L(\d+)\|([^\[\]|<>\n]{2,90})\]\]/u';

    protected const GENERIC_ANCHORS = ['اینجا', 'کلیک', 'این مقاله', 'این لینک', 'این صفحه', 'بیشتر بخوانید', 'ادامه مطلب', 'ادامه‌ی مطلب', 'مطالعه کنید', 'لینک', 'here', 'click', 'read more', 'this article'];

    /**
     * @param  array<int, array{url:string, title:string}>  $candidates  شماره => صفحه
     * @param  array<int, int>  $used  شماره‌هایی که تغییرهای قبلی همین به‌روزرسانی مصرف کرده‌اند
     * @return array{0: string, 1: array<int, int>} [متن با نشانه‌های معتبر، شماره‌های تازه‌ی مصرف‌شده در همین متن]
     */
    public static function sanitize(string $content, string $format, array $candidates, array $used): array
    {
        // لینک داخل تیتر نه برای خواننده مفید است نه برای گوگل
        $headings = $format === RefreshBlocks::HTML ? '/<(h[1-6])\b[^>]*>.*?<\/\1\s*>/isu' : '/^\s{0,3}#{1,6}\s.*$/mu';
        $content = (string) preg_replace_callback($headings, fn ($m) => self::strip($m[0]), $content);

        $new = [];
        $content = (string) preg_replace_callback(self::MARKER, function ($m) use ($candidates, $used, &$new) {
            $n = (int) $m[1];
            $anchor = trim($m[2]);
            $words = count(preg_split('/\s+/u', $anchor));
            $ok = isset($candidates[$n])
                && ! in_array($n, $used, true) && ! in_array($n, $new, true)
                && count($used) + count($new) < self::MAX_NEW
                && $words >= 2 && $words <= 8
                && ! self::isGeneric($anchor);

            if (! $ok) {
                return $anchor;
            }

            $new[] = $n;

            return "[[L{$n}|{$anchor}]]";
        }, $content);

        return [$content, $new];
    }

    /** @return array<int, string> شماره => متن لینک، به ترتیب آمدن در متن */
    public static function markers(string $text): array
    {
        preg_match_all(self::MARKER, $text, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $match) {
            $out[(int) $match[1]] = trim($match[2]);
        }

        return $out;
    }

    /** نشانه‌ها به متن لینکشان برمی‌گردند (برای سنجش شباهت و متن ساده). */
    public static function strip(string $text): string
    {
        return (string) preg_replace(self::MARKER, '$2', $text);
    }

    /**
     * نشانه‌ها به لینک واقعی تبدیل می‌شوند؛ $mark فقط برای پیش‌نمایش است تا لینک تازه برجسته دیده شود.
     *
     * @param  array<int, array{url:string}>  $candidates
     */
    public static function render(string $text, string $format, array $candidates, bool $mark = false): string
    {
        return (string) preg_replace_callback(self::MARKER, function ($m) use ($format, $candidates, $mark) {
            $url = $candidates[(int) $m[1]]['url'] ?? null;
            $anchor = trim($m[2]);

            if (! $url) {
                return $anchor;
            }

            return $format === RefreshBlocks::HTML
                ? '<a href="'.htmlspecialchars($url, ENT_QUOTES).'"'.($mark ? ' data-yx-link="new"' : '').'>'.$anchor.'</a>'
                : '['.$anchor.']('.str_replace([' ', ')'], ['%20', '%29'], $url).')';
        }, $text);
    }

    protected static function isGeneric(string $anchor): bool
    {
        $normalized = mb_strtolower(trim(str_replace("\u{200c}", ' ', $anchor)));

        foreach (self::GENERIC_ANCHORS as $generic) {
            if ($normalized === $generic || str_starts_with($normalized, $generic.' ')) {
                return true;
            }
        }

        return false;
    }
}
