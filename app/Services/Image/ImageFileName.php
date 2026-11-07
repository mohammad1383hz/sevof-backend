<?php

namespace App\Services\Image;

use Illuminate\Support\Str;

/**
 * ساخت نام فایل سئو-فرندلی برای تصاویر (مثل: home-espresso-machine-guide).
 * فقط حروف لاتین کوچک، عدد و خط تیره؛ بدون پسوند.
 */
class ImageFileName
{
    protected const MAX_LENGTH = 60;

    protected const MAX_WORDS = 7;

    public const FALLBACK = 'image';

    /**
     * اولین کاندیدایی که slug لاتین معتبر بسازد برگردانده می‌شود؛ وگرنه null.
     */
    public static function from(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $slug = self::slugify($candidate);

            if ($slug !== null) {
                return $slug;
            }
        }

        return null;
    }

    public static function slugify(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // پسوند احتمالی (مثلاً AI نوشته باشد coffee-maker.webp) حذف می‌شود.
        $value = (string) preg_replace('/\.(jpe?g|png|webp|gif|avif)$/i', '', $value);

        // فقط ورودی لاتین قبول است؛ transliteration فارسی (مثل «قهوه» → qhoh) نام بی‌معنا می‌سازد.
        if (preg_match('/[^\x00-\x7F]/', $value)) {
            return null;
        }

        $slug = Str::slug(str_replace('_', ' ', $value));
        $words = array_slice(array_filter(explode('-', $slug)), 0, self::MAX_WORDS);
        $slug = implode('-', $words);

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
            $slug = str_contains($slug, '-') ? substr($slug, 0, strrpos($slug, '-')) : $slug;
        }

        // نام‌های کوتاه/عددیِ بی‌معنا (مثل "1" یا "a") قبول نیست.
        return strlen($slug) >= 3 && preg_match('/[a-z]/', $slug) ? $slug : null;
    }

    /**
     * نام فایل ذخیره‌شده‌ی قبلی (بدون پسوند و بدون شماره‌ی تکراری -2/-3) — برای تولید مجدد تصویر.
     */
    public static function fromStoredPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);

        // نام‌های قدیمی uniqid (img_65f1c2...) ارزش سئو ندارند.
        if (str_starts_with($name, 'img_')) {
            return null;
        }

        return self::slugify((string) preg_replace('/-\d+$/', '', $name));
    }
}
