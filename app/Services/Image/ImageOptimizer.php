<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * فشرده‌سازی تصویر قبل از ذخیره: تغییر اندازه تا حداکثر ۱۲۸۰ پیکسل و تبدیل به WebP.
 * PNG خروجی مدل‌ها (~۱.۵ تا ۳ مگابایت) معمولاً به ۸۰ تا ۲۰۰ کیلوبایت می‌رسد.
 * اگر GD/Imagick نبود یا خروجی بزرگ‌تر شد، همان فایل اصلی برمی‌گردد.
 */
class ImageOptimizer
{
    public const MAX_DIMENSION = 1280;

    // هدف حجم هر تصویر؛ اگر کیفیت اول بیشتر بود، کیفیت کمتر امتحان می‌شود.
    public const TARGET_BYTES = 220_000;

    protected const QUALITIES = [82, 74, 66, 58];

    /**
     * @return array{0:string,1:string} [binary, extension]
     */
    public function optimize(string $binary, string $fallbackExtension = 'jpg'): array
    {
        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        // GIF ممکن است متحرک باشد؛ دست نمی‌خورد.
        if ($mime === 'image/gif') {
            return [$binary, 'gif'];
        }

        $converted = $this->withGd($binary) ?? $this->withImagick($binary);

        if ($converted !== null && strlen($converted) < strlen($binary)) {
            Log::info('Image optimized', [
                'original_bytes' => strlen($binary),
                'optimized_bytes' => strlen($converted),
                'original_mime' => $mime ?: null,
            ]);

            return [$converted, 'webp'];
        }

        return [$binary, $fallbackExtension];
    }

    /** تبدیل اجباری به WebP (حتی اگر حجم کم نشود)؛ برای دانلود کاربر. */
    public function toWebp(string $binary): ?string
    {
        return $this->withGd($binary) ?? $this->withImagick($binary);
    }

    protected function withGd(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_DIMENSION / max($width, $height, 1));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        $best = null;

        foreach (self::QUALITIES as $quality) {
            ob_start();
            imagewebp($canvas, null, $quality);
            $candidate = (string) ob_get_clean();

            if ($candidate !== '' && ($best === null || strlen($candidate) < strlen($best))) {
                $best = $candidate;
            }

            if ($best !== null && strlen($best) <= self::TARGET_BYTES) {
                break;
            }
        }

        imagedestroy($canvas);

        return $best;
    }

    protected function withImagick(string $binary): ?string
    {
        if (! class_exists('Imagick')) {
            return null;
        }

        try {
            $image = new \Imagick();
            $image->readImageBlob($binary);
            $image->setIteratorIndex(0);
            if (max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_DIMENSION) {
                $image->thumbnailImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true, false);
            }
            $image->stripImage();
            $image->setImageFormat('webp');

            $best = null;

            foreach (self::QUALITIES as $quality) {
                $image->setImageCompressionQuality($quality);
                $candidate = (string) $image->getImageBlob();

                if ($candidate !== '' && ($best === null || strlen($candidate) < strlen($best))) {
                    $best = $candidate;
                }

                if ($best !== null && strlen($best) <= self::TARGET_BYTES) {
                    break;
                }
            }

            $image->clear();
            $image->destroy();

            return $best;
        } catch (Throwable $e) {
            Log::warning('Image optimize via Imagick failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
