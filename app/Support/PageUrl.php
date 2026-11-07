<?php

namespace App\Support;

/**
 * کلید یکسان برای یک صفحه، تا آدرس سرچ کنسول با wp_permalink مقاله جور شود.
 * سرچ کنسول آدرس فارسی را گاهی encode‌شده و گاهی خام می‌دهد، با/بدون www و اسلش پایانی؛
 * همه‌ی این حالت‌ها یک کلید می‌گیرند. کوئری‌استرینگ و # نادیده گرفته می‌شوند.
 */
final class PageUrl
{
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url(str_contains($url, '://') ? $url : 'https://'.$url);

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = (string) preg_replace('/^www\./', '', mb_strtolower($parts['host']));
        $path = rtrim(rawurldecode($parts['path'] ?? ''), '/');

        return mb_strtolower($host.$path);
    }

    public static function hash(?string $url): ?string
    {
        $normalized = self::normalize($url);

        return $normalized === null ? null : sha1($normalized);
    }
}
