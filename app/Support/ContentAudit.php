<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Morilog\Jalali\Jalalian;

/**
 * بررسی سئوی یک مقاله بدون AI: لینک داخلی، سال‌های قدیمی، تازگی، طول و ساختار.
 * هم برای تشخیص «این مقاله آپدیت لازم دارد» در فهرست، هم برای گفتن ایرادها به AI هنگام بازنویسی.
 */
final class ContentAudit
{
    /** مقاله‌ای که بیشتر از این به‌روز نشده، از نظر تازگی قدیمی حساب می‌شود. */
    public const STALE_DAYS = 365;

    public const THIN_WORDS = 500;

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    /**
     * @return array{words:int, internal_links:int, external_links:int, headings:int, outdated_years:array<int, int>, internal_urls:array<int, string>}
     */
    public static function audit(string $content, string $format, ?string $siteDomain): array
    {
        $links = self::links($content, $format, $siteDomain);
        $text = self::plainText($content, $format);

        return [
            'words' => self::words($text),
            'internal_links' => count($links['internal']),
            'external_links' => $links['external'],
            'headings' => $format === RefreshBlocks::HTML
                ? preg_match_all('/<h[2-4][\s>]/i', $content)
                : preg_match_all('/^\s{0,3}#{2,4}\s/m', $content),
            'outdated_years' => self::outdatedYears($text),
            'internal_urls' => array_slice($links['internal'], 0, 100),
        ];
    }

    /**
     * لینک‌های یکتای داخلی (همین دامنه یا آدرس نسبی) و تعداد لینک‌های بیرونی؛ لینک فایل و تصویر حساب نمی‌شود.
     *
     * @return array{internal: array<int, string>, external: int}
     */
    public static function links(string $content, string $format, ?string $siteDomain): array
    {
        $pattern = $format === RefreshBlocks::HTML
            ? '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i'
            : '/\]\((\S+?)\)/u';
        preg_match_all($pattern, $content, $m);

        $domain = self::host($siteDomain);
        $internal = [];
        $external = 0;

        foreach ($m[1] as $href) {
            $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)
                || preg_match('/\.(jpe?g|png|gif|webp|svg|pdf|zip|mp4|mp3)(\?|$)/i', $href) || str_contains($href, '/wp-content/uploads/')) {
                continue;
            }

            $host = str_starts_with($href, '/') && ! str_starts_with($href, '//') ? $domain : self::host(parse_url($href, PHP_URL_HOST) ?: null);

            if ($domain !== null && $host === $domain) {
                // www، اسلش آخر و # فرقی در صفحه‌ی مقصد نمی‌سازد
                $key = PageUrl::hash(str_starts_with($href, '/') ? 'https://'.$domain.$href : $href) ?? $href;
                $internal[$key] ??= strtok($href, '#');
            } elseif ($host !== null) {
                $external++;
            }
        }

        return ['internal' => array_values($internal), 'external' => $external];
    }

    /** لینک داخلی پیشنهادی برای این طول متن: حدوداً یکی برای هر ۴۰۰ کلمه، بین ۲ تا ۸. */
    public static function recommendedLinks(int $words): int
    {
        return max(2, min(8, (int) ceil($words / 400)));
    }

    /**
     * سال‌های گذشته‌ای که در متن آمده (هجری شمسی ۱۳۹۰ به بعد، میلادی ۲۰۱۰ به بعد).
     *
     * @return array<int, int>
     */
    public static function outdatedYears(string $text, ?CarbonInterface $now = null): array
    {
        ['jalali_year' => $jy, 'gregorian_year' => $gy] = self::today($now);
        preg_match_all('/(?<!\d)(1[34]\d\d|20\d\d)(?!\d)/', self::latin($text), $m);

        $years = array_filter(array_map('intval', $m[1]), fn (int $y) => ($y >= 1390 && $y < $jy) || ($y >= 2010 && $y < $gy));
        $years = array_values(array_unique($years));
        rsort($years);

        return $years;
    }

    /**
     * @return array{jalali:string, gregorian:string, jalali_year:int, gregorian_year:int}
     */
    public static function today(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $jalali = Jalalian::fromCarbon($now->copy());

        return [
            'jalali' => $jalali->format('j F Y'),
            'gregorian' => $now->format('Y-m-d'),
            'jalali_year' => $jalali->getYear(),
            'gregorian_year' => (int) $now->format('Y'),
        ];
    }

    /**
     * ایرادهای سئو برای نمایش و برای AI. audit خالی یعنی متن هنوز خوانده نشده؛ فقط ایرادهای عنوان و تاریخ گفته می‌شود.
     *
     * @param  array<string, mixed>|null  $audit  خروجی audit
     * @return array<int, array{code:string, severity:string, label:string, detail:string}>
     */
    public static function issues(?array $audit, string $title, ?CarbonInterface $updatedAt, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $issues = [];
        $titleYears = self::outdatedYears($title, $now);
        $today = self::today($now);

        if ($titleYears !== []) {
            $issues[] = self::issue('outdated_year_title', self::HIGH, 'Outdated year in title',
                'The title mentions '.$titleYears[0].', so users and Google see the article as old (current year: '.($titleYears[0] >= 2000 ? $today['gregorian_year'] : $today['jalali_year']).').');
        }

        if ($audit !== null) {
            $words = (int) ($audit['words'] ?? 0);
            $links = (int) ($audit['internal_links'] ?? 0);
            $recommended = self::recommendedLinks($words);

            if ($links === 0) {
                $issues[] = self::issue('no_internal_links', self::HIGH, 'No internal links',
                    'This article links to no other page on the site; for its length it needs about '.$recommended.' relevant internal links.');
            } elseif ($links < $recommended) {
                $issues[] = self::issue('weak_internal_links', self::MEDIUM, 'Few internal links',
                    'It has '.$links.' internal links; about '.$recommended.' relevant links would suit its length.');
            }

            $textYears = array_values(array_diff((array) ($audit['outdated_years'] ?? []), $titleYears));
            if ($textYears !== []) {
                $issues[] = self::issue('outdated_year_text', self::MEDIUM, 'Mentions past years',
                    'The text mentions '.implode(', ', array_slice($textYears, 0, 3)).'; update it if it describes the current situation.');
            }

            if ($words > 0 && $words < self::THIN_WORDS) {
                $issues[] = self::issue('thin_content', self::MEDIUM, 'Thin content',
                    "Only {$words} words; it probably doesn't answer everything searchers want to know.");
            }

            if ($words >= 600 && (int) ($audit['headings'] ?? 0) === 0) {
                $issues[] = self::issue('no_headings', self::MEDIUM, 'No headings',
                    'Long text without headings is hard for users and Google to scan.');
            }
        }

        if ($updatedAt && $updatedAt->diffInDays($now) > self::STALE_DAYS) {
            $months = (int) floor($updatedAt->diffInMonths($now));
            $issues[] = self::issue('stale', self::MEDIUM, 'Not updated in a long time',
                "This article hasn't changed in {$months} months; Google prefers fresh content.");
        }

        return $issues;
    }

    /**
     * «آپدیت لازم دارد»: یک ایراد جدی یا دو ایراد متوسط.
     *
     * @param  array<int, array{severity:string}>  $issues
     */
    public static function needsUpdate(array $issues): bool
    {
        $high = count(array_filter($issues, fn ($i) => $i['severity'] === self::HIGH));

        return $high > 0 || count($issues) - $high >= 2;
    }

    public static function plainText(string $content, string $format): string
    {
        $text = $format === RefreshBlocks::HTML
            ? strip_tags((string) preg_replace('/<(script|style)\b[\s\S]*?<\/\1>|\[\/?[A-Za-z][\w-]*(?:\s[^\]]*)?\]/i', ' ', $content))
            : (string) preg_replace('/!\[[^\]]*\]\([^)]*\)|\]\([^)]*\)|[#*_>`\[\]]/u', ' ', $content);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected static function fa(int|string $value): string
    {
        return AutopilotScheduleText::toPersianDigits($value);
    }

    protected static function latin(string $text): string
    {
        return strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    protected static function words(string $text): int
    {
        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    protected static function host(?string $host): ?string
    {
        if (! $host) {
            return null;
        }

        $host = mb_strtolower(trim((string) (parse_url(str_contains($host, '//') ? $host : '//'.$host, PHP_URL_HOST) ?: $host)));

        return preg_replace('/^www\./', '', $host);
    }

    /** @return array{code:string, severity:string, label:string, detail:string} */
    protected static function issue(string $code, string $severity, string $label, string $detail): array
    {
        return compact('code', 'severity', 'label', 'detail');
    }
}
