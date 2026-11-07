<?php

namespace App\Support;

/**
 * ویرایش «پاراگرافی» مقاله برای به‌روزرسانی: متن هر بخش (بعد از قفل شدن لینک‌ها و بلوک‌های حساس)
 * به پاراگراف‌های شماره‌دار شکسته می‌شود و AI فقط پاراگراف‌هایی را که باید عوض یا اضافه شوند
 * برمی‌گرداند. پس هزینه‌ی خروجی فقط به اندازه‌ی تغییرات است و بقیه‌ی متن بایت‌به‌بایت همان می‌ماند.
 *
 * دو قالب: markdown (مقاله‌ی یوتیکس، لینک‌ها با LINK_n) و html (پست قدیمی وردپرس، نشانه‌های {{KEEP_n}}/{{A_n}}).
 */
final class RefreshBlocks
{
    public const MARKDOWN = 'markdown';

    public const HTML = 'html';

    public const REPLACE = 'replace';

    public const INSERT_AFTER = 'insert_after';

    /** متن تازه‌ی یک پاراگراف بیشتر از این، یعنی AI به‌جای ویرایش، مقاله‌ی تازه نوشته. */
    public const MAX_NEW_TEXT = 2500;

    /**
     * بخش‌بندی بی‌کم‌وکاست روی خط خالی: اندیس زوج = متن، اندیس فرد = فاصله‌ی بین پاراگراف‌ها.
     *
     * @return array<int, string>
     */
    public static function split(string $masked): array
    {
        return preg_split('/(\n[ \t]*\n\s*)/', $masked, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$masked];
    }

    /**
     * @param  array<int, string>  $parts  خروجی split
     * @param  array<int, string>  $replace  اندیس قسمت => متن تازه
     * @param  array<int, array<int, string>>  $insertAfter  اندیس قسمت => پاراگراف‌های تازه بعد از آن
     */
    public static function rebuild(array $parts, array $replace, array $insertAfter): string
    {
        $out = '';

        foreach ($parts as $i => $part) {
            // فاصله‌ی قبل و بعد پاراگراف اصلی (مثلاً خط آخر بخش) همان می‌ماند
            preg_match('/^(\s*)(.*?)(\s*)$/su', $part, $m);
            $body = isset($replace[$i]) ? trim($replace[$i]) : $m[2];

            if (! empty($insertAfter[$i])) {
                $body .= "\n\n".implode("\n\n", array_map('trim', $insertAfter[$i]));
            }

            $out .= $m[1].$body.$m[3];
        }

        return $out;
    }

    /** @return array<int, string> نشانه‌های قفل‌شده به همان ترتیبی که در متن آمده‌اند */
    public static function tokens(string $text, string $format): array
    {
        preg_match_all($format === self::HTML ? '/\{\{(?:KEEP|A)_\d+\}\}/' : '/\bLINK_\d+\b/', $text, $m);

        return $m[0];
    }

    /** متن ساده (بدون نشانه و تگ) برای سنجش طول و نمایش. */
    public static function text(string $block, string $format): string
    {
        return $format === self::HTML
            ? PostHtml::text($block)
            : trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\]\(LINK_\d+\)/', ']', $block)));
    }

    /** پاراگرافی که متن دارد (نه فقط تصویر/تیتر قفل‌شده) قابل جایگزینی است. */
    public static function isEditable(string $block, string $format): bool
    {
        return self::text($block, $format) !== '';
    }

    /**
     * ایرادهای متن تازه‌ی یک پاراگراف؛ آرایه‌ی خالی یعنی امن است.
     *
     * @param  string|null  $original  برای replace متن قبلی پاراگراف، برای insert_after null
     * @return array<int, string>
     */
    public static function problems(?string $original, string $new, string $format): array
    {
        $problems = [];
        $newText = self::text($new, $format);

        if ($newText === '') {
            return ['empty'];
        }

        if (mb_strlen($newText) > self::MAX_NEW_TEXT) {
            $problems[] = 'too_long';
        }

        $newTokens = self::tokens($new, $format);

        if ($original === null) {
            // پاراگراف تازه نباید لینک، تصویر یا بلوک قفل‌شده‌ای را جابه‌جا یا تکرار کند
            if ($newTokens !== [] || ($format === self::HTML && stripos($new, '</a>') !== false)) {
                $problems[] = 'tokens_in_new_block';
            }
        } else {
            $oldTokens = self::tokens($original, $format);
            [$oldKeep, $newKeep] = [self::keepTokens($oldTokens), self::keepTokens($newTokens)];
            $oldLinks = array_values(array_diff($oldTokens, $oldKeep));
            $newLinks = array_values(array_diff($newTokens, $newKeep));
            sort($oldLinks);
            sort($newLinks);

            if ($oldKeep !== $newKeep || $oldLinks !== $newLinks) {
                $problems[] = 'tokens_changed';
            }

            if ($format === self::HTML && substr_count(strtolower($new), '</a>') !== substr_count(strtolower($original), '</a>')) {
                $problems[] = 'links_changed';
            }

            // ویرایش یعنی کامل‌تر کردن، نه حذف بخش بزرگی از متن قبلی
            if (mb_strlen($newText) < mb_strlen(self::text($original, $format)) * 0.6) {
                $problems[] = 'too_short';
            }
        }

        if ($format === self::HTML) {
            $problems = [...$problems, ...PostHtml::unsafe($new)];
        } elseif (preg_match('/^\s{0,3}#{1,2}\s/m', $new) || preg_match('/<\s*(script|style|iframe|object|embed|form)\b/i', $new)) {
            $problems[] = 'forbidden_markup';
        }

        return $problems;
    }

    /** @param  array<int, string>  $tokens */
    protected static function keepTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, fn ($t) => str_starts_with($t, '{{KEEP_')));
    }
}
