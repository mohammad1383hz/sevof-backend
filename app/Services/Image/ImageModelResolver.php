<?php

namespace App\Services\Image;

/**
 * منبع حقیقتِ نهایی برای انتخاب مدل/تعداد تصویر.
 *  - کاربر رایگان: همیشه flux (Cloudflare) و ۱ تصویر.
 *  - کاربر پولی: همیشه gpt-image-2 (OpenAI)، صرف‌نظر از مدل درخواستی.
 *    فقط تعداد تصویر از درخواست خوانده می‌شود (۱ تا MAX_IMAGE_COUNT).
 */
class ImageModelResolver
{
    public const FREE_MODEL = 'flux';
    public const PAID_MODEL = 'gpt-image-2';
    public const FREE_IMAGE_COUNT = 1;
    public const MAX_IMAGE_COUNT = 5;

    /**
     * @return array{0:string,1:int} [modelId, imageCount]
     */
    public static function resolve(?string $requestedModelId, ?int $requestedCount, bool $isProUser): array
    {
        if (! $isProUser) {
            return [self::FREE_MODEL, self::FREE_IMAGE_COUNT];
        }

        $count = (int) ($requestedCount ?: self::FREE_IMAGE_COUNT);
        $count = max(1, min($count, self::MAX_IMAGE_COUNT));

        return [self::PAID_MODEL, $count];
    }
}
