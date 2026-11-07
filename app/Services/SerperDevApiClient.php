<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * کلاینت سرویس واسط Serper.dev — فقط به‌عنوان fallback برای
 * JustSerpApiClient::search() استفاده می‌شود، وقتی همه‌ی کلیدهای
 * justserp_keys با خطا یا اتمام سهمیه مواجه شوند.
 *
 * نکته‌ی حیاتی: ساختار خروجی search() اینجا دقیقاً همان ساختار
 * JustSerpApiClient::search() است:
 * ['position','title','link','snippet','source','highlighted_keywords']
 * تا سرویس‌های بالادستی (مثل CompetitorGapResearchService) بدون هیچ
 * تغییری روی این داده کار کنند.
 */
final class SerperDevApiClient
{
    private const DEFAULT_BASE_URL = 'https://google.serper.dev';
    private const PROVIDER = 'serper';

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
    public function search(string $query, array $options = []): array
    {
        $keys = DB::table('serp_fallback_keys')
            ->where('provider', self::PROVIDER)
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['api_key', 'base_url']);

        if ($keys->isEmpty()) {
            throw new RuntimeException('No active Serper.dev key in serp_fallback_keys.');
        }

        $lastError = null;

        foreach ($keys as $row) {
            $apiKey = trim((string) $row->api_key);
            if ($apiKey === '') {
                continue;
            }

            $baseUrl = rtrim((string) ($row->base_url ?: self::DEFAULT_BASE_URL), '/');

            try {
                $response = Http::withHeaders([
                    'X-API-KEY' => $apiKey,
                    'Content-Type' => 'application/json',
                ])->connectTimeout(10)->timeout($this->timeout)->post($baseUrl.'/search', array_filter([
                    'q' => $query,
                    'gl' => $options['country'] ?? 'us',
                    'hl' => $options['language'] ?? 'en',
                ], static fn ($value) => $value !== null && $value !== ''));
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                Log::warning('SerperDev request failed for active key', [
                    'query' => $query,
                    'error' => $lastError,
                ]);
                continue;
            }

            // --- DEBUG: لاگ کامل پاسخ خام برای بررسی علت deactive شدن کلید ---
            Log::info('SerperDev DEBUG raw response', [
                'query' => $query,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body_raw' => substr($response->body(), 0, 2000),
            ]);
            // --- END DEBUG ---

            // نکته: قبلاً اینجا کل body با str_contains برای کلماتی مثل
            // "quota" / "credit" اسکن می‌شد که باعث false-positive روی
            // پاسخ‌های موفق (status 200) می‌شد؛ چون Serper.dev در پاسخ
            // موفق هم ممکن است متادیتای مصرف کردیت برگرداند.
            // به همین دلیل فقط status code و فیلد صریح خطا در JSON چک می‌شود.
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
                    || str_contains($errorMessage, 'not enough credits')
                    || str_contains($errorMessage, 'limit reached')
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

                Log::warning('SerperDev key deactivated after quota response', [
                    'status' => $response->status(),
                ]);
                $lastError = 'SerperDev key quota exhausted.';
                continue;
            }

            if (! $response->successful()) {
                $lastError = 'HTTP '.$response->status();
                Log::warning('SerperDev returned an error', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);
                continue;
            }

            $data = $response->json();
            $organic = is_array($data) ? ($data['organic'] ?? []) : [];

            if (! is_array($organic)) {
                Log::warning('SerperDev organic field was not an array', [
                    'query' => $query,
                    'raw_body_snippet' => substr($response->body(), 0, 500),
                ]);

                return [];
            }

            if ($organic === []) {
                Log::info('SerperDev returned zero organic results', [
                    'query' => $query,
                ]);
            }

            return collect($organic)
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => [
                    // ساختار خروجی عیناً مطابق JustSerpApiClient::search()
                    // نگه داشته می‌شود؛ در پاسخ Serper.dev فیلد "position"
                    // از قبل با همین نام موجود است (برخلاف JustSerp که "rank" بود).
                    'position' => (int) ($item['position'] ?? 0),
                    'title' => (string) ($item['title'] ?? ''),
                    'link' => (string) ($item['link'] ?? ''),
                    'snippet' => (string) ($item['snippet'] ?? ''),
                    'source' => (string) ($item['source'] ?? ''),
                    'highlighted_keywords' => is_array($item['highlighted_keywords'] ?? null) ? $item['highlighted_keywords'] : [],
                ])
                ->filter(fn (array $item) => $item['link'] !== '' && $item['position'] > 0)
                ->values()
                ->all();
        }

        throw new RuntimeException('All active Serper.dev keys failed or ran out of quota.'.($lastError ? ' '.$lastError : ''));
    }
}
