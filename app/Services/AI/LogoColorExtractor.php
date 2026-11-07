<?php
// app/Services/AI/LogoColorExtractor.php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * استخراجِ رنگ‌های اصلیِ یک لوگو از روی بایت‌های خودِ تصویر — نه با حدسِ
 * AI. این کلاس مسئولِ حلِ این مشکل است: «AI فقط یه URL از لوگو می‌بینه،
 * رنگِ واقعیِ برند رو نمی‌شناسه». با این کلاس، رنگِ واقعی رو با کد
 * استخراج می‌کنیم و به‌صورتِ هگزِ دقیق در اختیارِ پرامپت می‌گذاریم.
 *
 * روش:
 *  - PNG/JPG/GIF/WEBP: با GD پیکسل‌ها sample می‌شوند (نه همه‌ی پیکسل‌ها،
 *    برایِ سرعت یک grid با فاصله)، پیکسل‌های نزدیک‌به‌سفید/نزدیک‌به‌سیاه/
 *    شفاف کنار گذاشته می‌شوند (چون معمولاً پس‌زمینه‌اند نه رنگِ برند)،
 *    بقیه quantize و histogram می‌شوند، و پررنگ‌ترین‌ها برگردانده می‌شوند.
 *  - SVG: چون GD نمی‌تواند SVG را rasterize کند، رنگ‌ها مستقیماً از
 *    attributeهای fill/stroke و مقادیرِ hex/rgb() داخلِ کدِ SVG با regex
 *    استخراج می‌شوند.
 *
 * اگر لوگو تک‌رنگ (مثلاً فقط مشکی/سفید/خاکستری) باشد یا استخراج ممکن
 * نباشد، آرایه‌ی خالی برمی‌گردد — و کدِ صدازننده باید در این حالت اجازه
 * بدهد AI خودش پالت را طبقِ style directive انتخاب کند (fallback امن).
 */
class LogoColorExtractor
{
    protected const NEAR_WHITE_THRESHOLD = 245;
    protected const NEAR_BLACK_THRESHOLD = 12;
    protected const GRAY_SATURATION_THRESHOLD = 14; // اختلافِ کم بینِ R/G/B یعنی خاکستری/بی‌رنگ
    protected const QUANTIZE_STEP = 24;
    protected const SAMPLE_GRID = 60; // تقریباً 60x60 نقطه‌ی نمونه‌برداری، صرف‌نظر از ابعادِ واقعیِ عکس

    /**
     * @return string[] لیستِ رنگ‌های هگز به ترتیبِ غالب‌بودن، مثلاً ['#2E9E8C', '#1B6F63']
     */
    public function extract(string $imageBytes, string $contentType, int $maxColors = 3): array
    {
        try {
            if (str_contains($contentType, 'svg') || str_starts_with(ltrim($imageBytes), '<svg') || str_starts_with(ltrim($imageBytes), '<?xml')) {
                return $this->extractFromSvg($imageBytes, $maxColors);
            }

            return $this->extractFromRaster($imageBytes, $maxColors);
        } catch (Throwable $e) {
            Log::warning('ai.logo_color_extractor.failed', [
                'content_type' => $contentType,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return string[]
     */
    protected function extractFromRaster(string $imageBytes, int $maxColors): array
    {
        $image = @imagecreatefromstring($imageBytes);

        if (! $image) {
            return [];
        }

        imagepalettetotruecolor($image);
        imagesavealpha($image, true);

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 2 || $height < 2) {
            imagedestroy($image);
            return [];
        }

        $stepX = max(1, (int) floor($width / self::SAMPLE_GRID));
        $stepY = max(1, (int) floor($height / self::SAMPLE_GRID));

        $counts = [];

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgba = imagecolorat($image, $x, $y);
                $colors = imagecolorsforindex($image, $rgba);

                // شفافیت: در GD مقدارِ alpha بینِ 0 (کاملاً مات) تا 127 (کاملاً شفاف) است
                if ($colors['alpha'] > 40) {
                    continue;
                }

                $r = $colors['red'];
                $g = $colors['green'];
                $b = $colors['blue'];

                if ($this->isNearWhiteOrBlack($r, $g, $b) || $this->isGrayish($r, $g, $b)) {
                    continue;
                }

                $key = $this->quantize($r, $g, $b);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        imagedestroy($image);

        if (empty($counts)) {
            // لوگو احتمالاً تک‌رنگ/مشکی/سفید است؛ رنگِ برندِ قابل‌استخراجی نداریم
            return [];
        }

        arsort($counts);
        $topKeys = array_slice(array_keys($counts), 0, $maxColors);

        return array_map(fn ($key) => $this->keyToHex($key), $topKeys);
    }

    /**
     * @return string[]
     */
    protected function extractFromSvg(string $svg, int $maxColors): array
    {
        $found = [];

        // fill="#RRGGBB" یا stroke="#RRGGBB"
        if (preg_match_all('/(?:fill|stroke)\s*=\s*["\'](#[0-9a-fA-F]{3,6})["\']/', $svg, $m)) {
            $found = array_merge($found, $m[1]);
        }

        // fill:#RRGGBB; داخلِ attributeِ style
        if (preg_match_all('/(?:fill|stroke)\s*:\s*(#[0-9a-fA-F]{3,6})/', $svg, $m)) {
            $found = array_merge($found, $m[1]);
        }

        // rgb(r,g,b) یا rgba(r,g,b,a)
        if (preg_match_all('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $svg, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $found[] = sprintf('#%02X%02X%02X', $match[1], $match[2], $match[3]);
            }
        }

        $counts = [];

        foreach ($found as $hex) {
            $hex = $this->normalizeHex($hex);

            if ($hex === null) {
                continue;
            }

            [$r, $g, $b] = $this->hexToRgb($hex);

            if ($this->isNearWhiteOrBlack($r, $g, $b) || $this->isGrayish($r, $g, $b)) {
                continue;
            }

            $counts[$hex] = ($counts[$hex] ?? 0) + 1;
        }

        if (empty($counts)) {
            return [];
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, $maxColors);
    }

    protected function isNearWhiteOrBlack(int $r, int $g, int $b): bool
    {
        $avg = ($r + $g + $b) / 3;

        return $avg >= self::NEAR_WHITE_THRESHOLD || $avg <= self::NEAR_BLACK_THRESHOLD;
    }

    /**
     * تشخیصِ خاکستری/بی‌رنگ: اگر بیشترین و کمترینِ کانال‌ها خیلی به هم
     * نزدیک باشند (یعنی saturation پایین است)، این رنگ به‌عنوانِ «رنگِ
     * برند» مفید نیست (مثلاً خاکستریِ متنِ لوگو).
     */
    protected function isGrayish(int $r, int $g, int $b): bool
    {
        return (max($r, $g, $b) - min($r, $g, $b)) < self::GRAY_SATURATION_THRESHOLD;
    }

    protected function quantize(int $r, int $g, int $b): string
    {
        $step = self::QUANTIZE_STEP;
        $qr = intdiv($r, $step) * $step;
        $qg = intdiv($g, $step) * $step;
        $qb = intdiv($b, $step) * $step;

        return "{$qr},{$qg},{$qb}";
    }

    protected function keyToHex(string $key): string
    {
        [$r, $g, $b] = array_map('intval', explode(',', $key));

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    protected function normalizeHex(string $hex): ?string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return '#'.strtoupper($hex);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
