<?php

namespace App\Services\Trends;

use App\Models\SiteKeywordTrend;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleTrendsService
{
    protected int $maxRetries           = 3;
    protected int $rateLimitWaitSeconds = 60;
    protected int $delayBetweenRequests = 5;

    // ─────────────────────────────────────────────────────────────────
    // متد اصلی: fetch + ذخیره در DB
    // ─────────────────────────────────────────────────────────────────

    /**
     * ترند یک کلمه کلیدی رو از API می‌گیره و در site_keyword_trends ذخیره می‌کنه.
     *
     * @return SiteKeywordTrend  رکورد ذخیره/آپدیت‌شده
     */
    public function single(
        int    $siteId,
        string $keyword,
        string $geo       = 'IR',
        string $timeframe = 'now 7-d',
    ): SiteKeywordTrend {
        // رکورد رو pending بساز (یا همونی که هست رو بگیر)
        $record = SiteKeywordTrend::updateOrCreate(
            ['site_id' => $siteId, 'keyword' => $keyword],
            ['geo' => $geo, 'timeframe' => $timeframe, 'status' => 'pending', 'error_message' => null],
        );

        try {
            $data = $this->singlefetch($keyword, $geo, $timeframe);

            $record->update([
                'interest_over_time' => $data['interest_over_time'] ?? [],
                'top_queries'        => $data['top_queries']        ?? [],
                'rising_queries'     => $data['rising_queries']     ?? [],
                'status'             => 'fetched',
                'fetched_at'         => now(),
                'error_message'      => null,
            ]);

            Log::info("GoogleTrendsService: '{$keyword}' (site={$siteId}) با موفقیت ذخیره شد.");
        } catch (RuntimeException $e) {
            $record->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error("GoogleTrendsService: خطا برای '{$keyword}' (site={$siteId}): " . $e->getMessage());

            throw $e; // به job اطلاع بده که این keyword شکست خورد
        }

        return $record->fresh();
    }

    // ─────────────────────────────────────────────────────────────────
    // fetch خام از API (بدون ذخیره)
    // ─────────────────────────────────────────────────────────────────

    /**
     * دریافت ترند یک کلمه کلیدی از سرویس پایتون
     *
     * Response ساختار:
     * [
     *   'keyword'            => 'پرستار',
     *   'interest_over_time' => ['2025-07-06 00:00:00' => 8, ...],
     *   'top_queries'        => [[...], ...],
     *   'rising_queries'     => [[...], ...],
     *   'error'              => null,
     * ]
     */
    public function singlefetch(string $keyword, string $geo = 'IR', string $timeframe = 'now 7-d'): array
    {
        $baseUrl = $this->baseUrl();
        $attempt = 0;

        while (true) {
            $attempt++;

            $response = Http::timeout(30)->get("{$baseUrl}/trends/single", [
                'keyword'   => $keyword,
                'geo'       => $geo,
                'timeframe' => $timeframe,
            ]);

            if ($this->isRateLimited($response)) {
                if ($attempt >= $this->maxRetries) {
                    throw new RuntimeException(
                        "Rate Limit گوگل ترندز برای '{$keyword}' - تعداد تلاش‌ها تمام شد."
                    );
                }

                Log::warning("Rate limit برای '{$keyword}'، تلاش {$attempt}. صبر {$this->rateLimitWaitSeconds} ثانیه...");
                sleep($this->rateLimitWaitSeconds);
                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "خطا در دریافت ترند برای '{$keyword}': " . $response->body()
                );
            }

            $data = $response->json();

            // اگه سرویس پایتون خودش error برگردوند
            if (! empty($data['error'])) {
                throw new RuntimeException("Trends service error for '{$keyword}': " . $data['error']);
            }

            return $data;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    protected function baseUrl(): string
    {
        return rtrim(config('services.trends.base_url', 'http://localhost:8001'), '/');
    }

    protected function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }

        if ($response->status() === 502) {
            $body = strtolower($response->body());

            return str_contains($body, '429')
                || str_contains($body, 'rate limit')
                || str_contains($body, 'too many requests');
        }

        return false;
    }
}