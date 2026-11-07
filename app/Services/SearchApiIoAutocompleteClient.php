<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * کلاینت سرویس واسط SearchAPI.io (engine=google_autocomplete) — فقط
 * به‌عنوان fallback برای JustSerpApiClient::autocomplete() استفاده
 * می‌شود، وقتی هیچ کلید فعال JustSerp نباشد یا همه‌ی کلیدهای فعال با
 * خطا/اتمام سهمیه مواجه شوند.
 *
 * از همان جدول serp_fallback_keys استفاده می‌کند که SerperDevApiClient
 * هم از آن استفاده می‌کند؛ فقط با provider متفاوت ('searchapi_autocomplete')
 * تا کلیدهای این دو سرویس با هم قاطی نشوند.
 *
 * نکته‌ی حیاتی: خروجی این متد دقیقاً همان چیزی است که
 * JustSerpApiClient::autocomplete() برمی‌گرداند: آرایه‌ی خام
 * suggestions، بدون هیچ remap یا تغییر شکلی — چون مصرف‌کننده‌های فعلی
 * (مثل EntityKeywordSuggestionService) دقیقاً همان shape را انتظار دارند.
 */
final class SearchApiIoAutocompleteClient
{
    private const DEFAULT_BASE_URL = 'https://www.searchapi.io/api/v1';
    private const PROVIDER = 'searchapi_autocomplete';

    private readonly int $timeout;

    public function __construct(?int $timeout = null)
    {
        $cfg = config('seobot.serp', []);
        $cfg = is_array($cfg) ? $cfg : [];

        $this->timeout = (int) ($timeout ?? $cfg['timeout'] ?? 30);
    }

    public static function fromConfig(): self
    {
        return new self();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function autocomplete(string $query, array $options = []): array
    {
        $keys = DB::table('serp_fallback_keys')
            ->where('provider', self::PROVIDER)
            ->where('status', 'active')
            ->inRandomOrder()
            ->get(['api_key', 'base_url']);

        if ($keys->isEmpty()) {
            throw new RuntimeException('No active SearchAPI.io key in serp_fallback_keys.');
        }

        $lastError = null;

        foreach ($keys as $row) {
            $apiKey = trim((string) $row->api_key);
            if ($apiKey === '') {
                continue;
            }

            $baseUrl = rtrim((string) ($row->base_url ?: self::DEFAULT_BASE_URL), '/');

            try {
                $response = Http::connectTimeout(10)->timeout($this->timeout)->get($baseUrl.'/search', array_filter([
                    'engine' => 'google_autocomplete',
                    'q' => $query,
                    'api_key' => $apiKey,
                    // gl/hl معادل country/language در JustSerpApiClient::autocomplete هستند
                    'gl' => $options['country'] ?? null,
                    'hl' => $options['language'] ?? null,
                ], static fn ($value) => $value !== null && $value !== ''));
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                Log::warning('SearchApiIo autocomplete request failed for active key', [
                    'query' => $query,
                    'error' => $lastError,
                ]);
                continue;
            }

            // --- DEBUG: لاگ کامل پاسخ خام برای بررسی رفتار quota detection ---
            Log::info('SearchApiIo autocomplete DEBUG raw response', [
                'query' => $query,
                'status' => $response->status(),
                'body_raw' => substr($response->body(), 0, 2000),
            ]);
            // --- END DEBUG ---

            // نکته: قبلاً اینجا کل body با str_contains برای کلماتی مثل
            // "quota" / "credit" / "insufficient" اسکن می‌شد که باعث
            // false-positive روی پاسخ‌های موفق (status 200) می‌شد؛ دقیقاً
            // همان مشکلی که در SerperDevApiClient پیدا و فیکس شد. اینجا هم
            // فقط status code و فیلد صریح خطا در JSON چک می‌شود.
            $quotaStatusCodes = [401, 402, 403, 429];
            $quotaExhausted = false;

            if (in_array($response->status(), $quotaStatusCodes, true)) {
                $quotaExhausted = true;
            } elseif (! $response->successful()) {
                $errorPayload = $response->json();
                $errorMessage = is_array($errorPayload)
                    ? strtolower((string) ($errorPayload['message'] ?? $errorPayload['error'] ?? ''))
                    : '';

                if ($errorMessage !== '' && (
                    str_contains($errorMessage, 'quota')
                    || str_contains($errorMessage, 'insufficient')
                    || str_contains($errorMessage, 'limit reached')
                    || str_contains($errorMessage, 'not enough credits')
                )) {
                    $quotaExhausted = true;
                }
            }

            if ($quotaExhausted) {
                DB::table('serp_fallback_keys')
                    ->where('provider', self::PROVIDER)
                    ->where('api_key', $apiKey)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'deactive',
                        'updated_at' => now(),
                    ]);

                Log::warning('SearchApiIo autocomplete key deactivated after quota response', [
                    'status' => $response->status(),
                ]);
                $lastError = 'SearchApiIo key quota exhausted.';
                continue;
            }

            if (! $response->successful()) {
                $lastError = 'HTTP '.$response->status();
                Log::warning('SearchApiIo autocomplete returned an error', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);
                continue;
            }

            // پاسخ SearchAPI.io مستقیماً در ریشه‌ی JSON کلید "suggestions"
            // دارد (طبق داکیومنت رسمی)، برخلاف JustSerp که زیر "data" است.
            $suggestions = $response->json('suggestions', []);

            return is_array($suggestions) ? $suggestions : [];
        }

        throw new RuntimeException('All active SearchAPI.io keys failed or ran out of quota.'.($lastError ? ' '.$lastError : ''));
    }
}
