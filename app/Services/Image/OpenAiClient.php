<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * تولید تصویر مستقیم با API رسمی OpenAI (gpt-image-2).
 * هیچ‌وقت از پروکسی‌هایی مثل gapgpt.app/avalai.org رد نمی‌شود.
 */
class OpenAiClient
{
    protected const BASE_URL = 'https://api.openai.com/v1';

    public const MODEL = 'gpt-image-2';


    protected const SIZE = '1280x720';
    protected const QUALITY = 'low';

    // خروجی پیش‌فرض PNG (~۱.۵-۳MB) است؛ WebP با فشرده‌سازی ۸۰ حدود ۱۰ برابر سبک‌تر است.
    protected const OUTPUT_FORMAT = 'webp';
    protected const OUTPUT_COMPRESSION = 80;

protected function apiKey(): string
    {
        return (string) config('seobot.openai_image.api_key');
    }

public function generate(string $prompt, int $timeoutSeconds): array
{
    if (trim($this->apiKey()) === '' || ! str_starts_with($this->apiKey(), 'sk-')) {
        throw new RuntimeException('OpenAI: OPENAI_IMAGE_API_KEY is not configured.');
    }

    $response = Http::withToken($this->apiKey())
        ->timeout(max(10, $timeoutSeconds))
        ->post(self::BASE_URL.'/images/generations', [
            'model' => self::MODEL,
            'prompt' => $prompt,
            'size' => self::SIZE,
            'quality' => self::QUALITY,
            'output_format' => self::OUTPUT_FORMAT,
            'output_compression' => self::OUTPUT_COMPRESSION,
            'n' => 1,
        ]);

    $data = $response->json();

    Log::info('OpenAI image generation response', [
        'status' => $response->status(),
        'body' => $this->redactBase64($data),
    ]);

    if (! $response->successful()) {
        $message = $data['error']['message'] ?? $response->body();
        throw new RuntimeException("OpenAI [http_status:{$response->status()}]: {$message}");
    }

    $img = $data['data'][0] ?? null;
    $format = $data['output_format'] ?? self::OUTPUT_FORMAT;
    $url = $img['url'] ?? (isset($img['b64_json']) ? "data:image/{$format};base64,".$img['b64_json'] : null);

    if (! $url) {
        throw new RuntimeException('OpenAI: no image in the response.');
    }

    return ['url' => $url, 'usage' => $data['usage'] ?? null];
}

/**
 * برای لاگ: b64_json (حجیم و باینری) رو حذف می‌کنه تا لاگ سنگین/بی‌فایده نشه.
 */
protected function redactBase64(?array $data): ?array
{
    if (! $data) {
        return $data;
    }

    if (isset($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as &$item) {
            if (isset($item['b64_json'])) {
                $item['b64_json'] = '[REDACTED base64, length='.strlen($item['b64_json']).']';
            }
        }
        unset($item);
    }

    return $data;
}
}
