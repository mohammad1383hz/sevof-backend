<?php

namespace App\Support;

use App\Models\Site;
use App\Services\SiteLanguageDetector;
use Throwable;

/**
 * منبع واحد «زبان خروجی AI» برای هر سایت.
 *
 * پرامپت‌های پروژه به فارسی نوشته شده‌اند و برای سایت‌های فارسی دست‌نخورده می‌مانند؛
 * برای هر زبان دیگری localize() اشاره‌های صریح به «فارسی/Persian» را با نام زبان سایت عوض
 * می‌کند و یک دستور زبان خروجی با بالاترین اولویت به انتهای پرامپت اضافه می‌کند.
 */
final class ContentLanguage
{
    /** Fallback when a site's language can't be detected: Sevof is an English-first product. */
    public const DEFAULT = 'en';

    /** زبان غیرفارسیِ ذخیره‌شده با اطمینان کمتر از این، قابل اتکا نیست و فارسی در نظر گرفته می‌شود. */
    public const MIN_TRUSTED_CONFIDENCE = 50;

    public const ENGLISH_NAMES = [
        'fa' => 'Persian', 'ar' => 'Arabic', 'ur' => 'Urdu', 'ps' => 'Pashto', 'ku' => 'Kurdish', 'he' => 'Hebrew',
        'en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian',
        'pt' => 'Portuguese', 'nl' => 'Dutch', 'tr' => 'Turkish', 'pl' => 'Polish', 'id' => 'Indonesian',
        'ru' => 'Russian', 'uk' => 'Ukrainian', 'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean',
        'el' => 'Greek', 'th' => 'Thai', 'hi' => 'Hindi', 'bn' => 'Bengali', 'hy' => 'Armenian', 'ka' => 'Georgian',
    ];

    /** کشور پیش‌فرض جست‌وجوی گوگل برای هر زبان (gl / geo). */
    protected const COUNTRIES = [
        'fa' => 'ir', 'ar' => 'sa', 'ur' => 'pk', 'ps' => 'af', 'ku' => 'iq', 'he' => 'il',
        'en' => 'us', 'de' => 'de', 'fr' => 'fr', 'es' => 'es', 'it' => 'it', 'pt' => 'br', 'nl' => 'nl',
        'tr' => 'tr', 'pl' => 'pl', 'id' => 'id', 'ru' => 'ru', 'uk' => 'ua', 'zh' => 'cn', 'ja' => 'jp',
        'ko' => 'kr', 'el' => 'gr', 'th' => 'th', 'hi' => 'in', 'bn' => 'bd', 'hy' => 'am', 'ka' => 'ge',
    ];

    /**
     * کد زبان سایت. سایت‌هایی که قبل از ستون language تحلیل شده‌اند از روی crawled_pages
     * ذخیره‌شده تشخیص داده و یک‌بار ذخیره می‌شوند؛ اگر هیچ نشانه‌ای نبود فارسی (رفتار قبلی).
     */
    public static function forSite(?Site $site): string
    {
        if (! $site) {
            return self::DEFAULT;
        }

        $detector = app(SiteLanguageDetector::class);
        $code = $detector->normalizeCode($site->language);
        if ($code !== null) {
            $confidence = $site->language_confidence;

            // تشخیص خودکار ضعیف (مثل سایت فارسی که با ۱۴٪ اطمینان انگلیسی ثبت شده) نباید خروجی را انگلیسی کند
            return $code !== self::DEFAULT && $confidence !== null && (int) $confidence < self::MIN_TRUSTED_CONFIDENCE
                ? self::DEFAULT
                : $code;
        }

        try {
            $detected = $detector->detect((array) ($site->crawled_pages ?? []), (string) ($site->navigation_tree ?? ''));
        } catch (Throwable) {
            return self::DEFAULT;
        }

        if ($detected['code'] === null) {
            return self::DEFAULT;
        }

        // ذخیره فقط برای کش است؛ اگر نشد (مثلاً migration ستون language اجرا نشده) همان تشخیص استفاده می‌شود.
        if ($site->exists) {
            try {
                $site->forceFill([
                    'language' => $detected['code'],
                    'language_confidence' => $detected['confidence'],
                    'language_source' => $detected['source'],
                ])->saveQuietly();
            } catch (Throwable) {
                $site->language = $detected['code'];
            }
        }

        return $detected['code'];
    }

    public static function isPersian(string $code): bool
    {
        return $code === 'fa';
    }

    public static function isRtl(string $code): bool
    {
        return in_array($code, ['fa', 'ar', 'ur', 'ps', 'ku', 'he'], true);
    }

    public static function name(string $code): string
    {
        return self::ENGLISH_NAMES[$code] ?? 'English';
    }

    public static function country(string $code): string
    {
        return self::COUNTRIES[$code] ?? 'us';
    }

    /**
     * پرامپت را برای زبان سایت آماده می‌کند. برای فارسی همان پرامپت قبلی برمی‌گردد.
     */
    public static function localize(string $prompt, string $code): string
    {
        if (self::isPersian($code)) {
            return $prompt;
        }

        $en = self::name($code);
        $fa = SiteLanguageDetector::NAMES[$code] ?? $en;

        $prompt = str_replace(
            ['Persian (Farsi)', 'Persian', 'Farsi', 'فارسی‌زبان (ایران)', 'فارسی'],
            [$en, $en, $en, $fa.'‌زبان', $fa],
            $prompt,
        );

        return rtrim($prompt)."\n\n".self::directive($code);
    }

    public static function directive(string $code): string
    {
        $en = self::name($code);

        return <<<TXT
=== OUTPUT LANGUAGE — highest priority, overrides every other instruction ===
The website's content language is {$en} ({$code}). Write EVERY human-readable value you output (titles, headings, body text, summaries, descriptions, niche, keywords, entity names, anchors, alt texts, meta fields, tags, suggestions) in natural, native-level {$en}, for this website's own audience and market.
- The instructions above are written in Persian only for internal convenience; that must never affect the output language.
- Wherever the instructions mention Persian/Farsi or Iranian users/market as the output language or audience, read it as {$en} and this website's own target market.
- Persian-only writing rules (نیم‌فاصله/half-spaces, writing brands or loanwords in Persian script, avoiding Latin letters, Persian digits) do NOT apply; follow the standard conventions of {$en}.
- Keep JSON keys, enum values, URLs, placeholders and slug/file_name rules exactly as specified.
TXT;
    }
}
