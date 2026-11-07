<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\JustSerpNoActiveKeysException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * کلاینت JustSerp با مدیریت کلید از جدول justserp_keys.
 * فقط metadata نتایج SERP را می‌خواند و هیچ صفحه رقیبی را crawl نمی‌کند.
 *
 * فالبک‌ها:
 *  - search(): اگر همه‌ی کلیدهای فعال justserp_keys با خطا/اتمام سهمیه
 *    مواجه شوند (یا از ابتدا کلید فعالی نباشد)، به SerperDevApiClient
 *    (سرویس واسط Serper.dev) سوییچ می‌کند. ساختار خروجی دقیقاً یکسان
 *    نگه داشته می‌شود.
 *  - autocomplete(): همان الگو، اما فالبک آن SearchApiIoAutocompleteClient
 *    (سرویس واسط SearchAPI.io با engine=google_autocomplete) است.
 *
 * هر دو کلاینتِ فالبک از همان جدول مشترک serp_fallback_keys با
 * ستون provider ('serper' | 'searchapi_autocomplete') استفاده می‌کنند.
 */
final class JustSerpApiClient
{
    private ?array $lastSearchInfo = null;

    private readonly string $baseUrl;
    private readonly string $defaultLanguage;
    private readonly string $defaultCountry;
    private readonly int $timeout;

    /**
     * تمام پارامترها اختیاری‌اند و در صورت ندادن، از config('seobot.serp')
     * خوانده می‌شوند. به همین دلیل Laravel Service Container می‌تواند این
     * کلاس را بدون هیچ binding دستی در ServiceProvider، خودش با
     * reflection بسازد (auto-resolve) — نیازی به ثبت singleton نیست.
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $defaultLanguage = null,
        ?string $defaultCountry = null,
        ?int $timeout = null,
    ) {
        $cfg = config('seobot.serp', []);
        $cfg = is_array($cfg) ? $cfg : [];

        $this->baseUrl = rtrim((string) ($baseUrl ?? $cfg['base_url'] ?? 'https://api.justserpapi.com/api/v1'), '/');
        $this->defaultLanguage = (string) ($defaultLanguage ?? $cfg['language'] ?? 'en');
        $this->defaultCountry = (string) ($defaultCountry ?? $cfg['country'] ?? 'us');
        $this->timeout = (int) ($timeout ?? $cfg['timeout'] ?? 30);
    }

    /**
     * برای سازگاری با کدهای قبلی که صراحتاً fromConfig() را صدا می‌زدند.
     * دقیقاً معادل new self() است چون constructor خودش از config می‌خواند.
     */
    public static function fromConfig(): self
    {
        return new self();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, array $options = []): array
    {
        $keys = DB::table('justserp_keys')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('api_key')
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->values()
            ->all();

        if ($keys === []) {
            Log::warning('JustSerp has no active keys, falling back to SerperDev', [
                'query' => $query,
            ]);

            return $this->searchViaFallback($query, $options, 'No active JustSerp key in justserp_keys.');
        }

        $lastError = null;

        foreach ($keys as $apiKey) {
            try {
                $response = Http::withHeaders([
                    'X-API-Key' => $apiKey,
                ])->connectTimeout(10)->timeout($this->timeout)->get($this->baseUrl.'/google/search', [
                    'query' => $query,
                    'language' => $options['language'] ?? $this->defaultLanguage,
                    'country' => $options['country'] ?? $this->defaultCountry,
                    ...array_filter([
                        'location' => $options['location'] ?? null,
                    ], static fn ($value) => $value !== null && $value !== ''),
                ]);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                Log::warning('JustSerp request failed for active key', [
                    'query' => $query,
                    'error' => $lastError,
                ]);
                continue;
            }

            $body = strtolower($response->body());
            $quotaExhausted = in_array($response->status(), [401, 402, 429], true)
                || str_contains($body, 'quota')
                || str_contains($body, 'credit')
                || str_contains($body, 'balance')
                || str_contains($body, 'limit reached');

            if ($quotaExhausted) {
                DB::table('justserp_keys')
                    ->where('api_key', $apiKey)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'deactive',
                        'updated_at' => now(),
                    ]);

                Log::warning('JustSerp key deactivated after quota response', [
                    'status' => $response->status(),
                ]);
                $lastError = 'JustSerp key quota exhausted.';
                continue;
            }

            if (! $response->successful()) {
                $lastError = 'HTTP '.$response->status();
                Log::warning('JustSerp returned an error', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);
                continue;
            }

            $data = $response->json();

            // پاسخ JustSerp به‌صورت {"code":200,"message":"success","data":{...}}
            // برمی‌گردد؛ organic_results و search_information داخل کلید data هستند،
            // نه در ریشه‌ی JSON پاسخ. کد قبلی مستقیماً از ریشه می‌خواند و همیشه
            // آرایه‌ی خالی برمی‌گرداند (بدون هیچ خطا یا هشداری)، چون آن کلیدها
            // در ریشه وجود ندارند. اینجا هر دو حالت (پوشش‌دار و بدون پوشش) را
            // پشتیبانی می‌کنیم تا اگر اندپوینت دیگری بدون کلید data پاسخ داد،
            // باز هم کار کند.
            $payload = is_array(Arr::get($data, 'data')) ? $data['data'] : $data;

            $this->lastSearchInfo = Arr::get($payload, 'search_information');
            $organic = Arr::get($payload, 'organic_results', []);

            if (! is_array($organic)) {
                Log::warning('JustSerp organic_results was not an array', [
                    'query' => $query,
                    'raw_body_snippet' => substr($response->body(), 0, 500),
                ]);

                return [];
            }

            if ($organic === []) {
                // پاسخ موفق بود اما organic_results واقعاً خالی است (نه یک باگ
                // parsing). این لاگ کمک می‌کند این حالت را از حالت بالا تشخیص دهیم.
                Log::info('JustSerp returned zero organic results', [
                    'query' => $query,
                    'response_code' => Arr::get($data, 'code'),
                    'response_message' => Arr::get($data, 'message'),
                ]);
            }

            return collect($organic)
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => [
                    // JustSerp موقعیت هر نتیجه را در فیلد "rank" برمی‌گرداند
                    // (و گاهی "page_rank" برای شماره‌گذاری میان صفحات)؛
                    // فیلدی به‌نام "position" در پاسخ واقعی وجود ندارد.
                    'position' => (int) ($item['rank'] ?? $item['page_rank'] ?? $item['position'] ?? 0),
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

        // به‌جای throw مستقیم قبلی، اینجا به سرویس فالبک می‌رویم.
        Log::warning('JustSerp exhausted all active keys, falling back to SerperDev', [
            'query' => $query,
            'last_error' => $lastError,
        ]);

        return $this->searchViaFallback($query, $options, $lastError);
    }

    /**
     * وقتی JustSerp (چه به‌خاطر نبود کلید فعال، چه به‌خاطر اتمام سهمیه/خطا)
     * جواب نمی‌دهد، این متد سراغ سرویس واسط جایگزین (Serper.dev) می‌رود.
     * خروجی این متد دقیقاً همان ساختاری‌ست که search() برمی‌گرداند، چون
     * SerperDevApiClient::search() خودش با همان shape نرمالایز شده است.
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchViaFallback(string $query, array $options, ?string $justSerpError): array
    {
        try {
            return SerperDevApiClient::fromConfig()->search($query, $options);
        } catch (\Throwable $fallbackException) {
            Log::error('SerperDev fallback also failed', [
                'query' => $query,
                'error' => $fallbackException->getMessage(),
            ]);

            throw new RuntimeException(
                'All active JustSerp keys and the Serper.dev fallback failed or ran out of quota.'
                .($justSerpError ? ' JustSerp: '.$justSerpError : '')
                .' | Serper: '.$fallbackException->getMessage()
            );
        }
    }

    /**
     * دریافت پیشنهادات Google Autocomplete.
     * از همان جدول justserp_keys و همان منطق چرخش/غیرفعال‌سازی کلید
     * استفاده می‌کند که در search() به‌کار رفته است.
     *
     * فراخوانی‌کننده (مثل EntityKeywordSuggestionService) باید خودش
     * RuntimeException احتمالی (نبود کلید فعال / اتمام تمام کلیدها) را
     * در صورت نیاز catch کند.
     *
     * @return array<int, array<string, mixed>>
     */
    public function autocomplete(string $query, array $options = []): array
    {
        // توجه: برخلاف search() که ترتیب کلیدها را با orderBy('id') ثابت
        // نگه می‌دارد، اینجا عمداً از inRandomOrder() استفاده شده تا با
        // رفتار پیاده‌سازی اصلی (قبل از تجمیع در این کلاینت) یکسان بماند؛
        // این کار فشار درخواست‌ها را بین کلیدها توزیع می‌کند به‌جای اینکه
        // همیشه اول یک کلید ثابت را امتحان کند.
        $keys = DB::table('justserp_keys')
            ->where('status', 'active')
            ->inRandomOrder()
            ->pluck('api_key')
            ->filter(fn ($key) => is_string($key) && trim($key) !== '')
            ->values()
            ->all();

        if ($keys === []) {
            Log::warning('JustSerp has no active keys for autocomplete, falling back to SearchAPI.io', [
                'query' => $query,
            ]);

            try {
                return SearchApiIoAutocompleteClient::fromConfig()->autocomplete($query, $options);
            } catch (\Throwable $fallbackException) {
                Log::error('SearchApiIo autocomplete fallback also failed (no JustSerp keys)', [
                    'query' => $query,
                    'error' => $fallbackException->getMessage(),
                ]);

                // نوع اکسپشن قبلی عمداً حفظ می‌شود، چون فراخوانی‌کننده
                // (طبق داکیومنت این متد) این تایپ را جدا catch می‌کند و
                // نباید silent-fallback به یک RuntimeException عمومی شود.
                throw new JustSerpNoActiveKeysException(
                    'The suggestions API key (JustSerpAPI) is not set and the fallback did not respond: '
                    .$fallbackException->getMessage()
                );
            }
        }

        $lastError = null;

        foreach ($keys as $apiKey) {
            try {
                $response = Http::withHeaders([
                    'X-API-Key' => $apiKey,
                ])->connectTimeout(10)->timeout($this->timeout)->retry(2, 1000)->get($this->baseUrl.'/google/autocomplete', [
                    'query' => $query,
                    'language' => $options['language'] ?? $this->defaultLanguage,
                    'country' => $options['country'] ?? $this->defaultCountry,
                ]);
            } catch (\Throwable $exception) {
                $lastError = $exception->getMessage();
                Log::warning('JustSerp autocomplete request failed for active key', [
                    'query' => $query,
                    'error' => $lastError,
                ]);
                continue;
            }

            Log::info("JustSerpAPI autocomplete RAW response for [{$query}]:", [
                'api_key_suffix' => substr($apiKey, -6),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $body = strtolower($response->body());
            $quotaExhausted = in_array($response->status(), [401, 402, 429], true)
                || str_contains($body, 'quota')
                || str_contains($body, 'credit')
                || str_contains($body, 'balance')
                || str_contains($body, 'limit reached')
                || str_contains($body, 'no active subscription')
                || str_contains($body, 'insufficient_quota');

            if ($quotaExhausted) {
                DB::table('justserp_keys')
                    ->where('api_key', $apiKey)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'deactive',
                        'updated_at' => now(),
                    ]);

                Log::warning('JustSerp key deactivated after quota response (autocomplete)', [
                    'status' => $response->status(),
                ]);
                $lastError = 'JustSerp key quota exhausted.';
                continue;
            }

            if (! $response->successful()) {
                $lastError = 'HTTP '.$response->status();
                Log::warning('JustSerp autocomplete returned an error', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);
                continue;
            }

            $suggestions = $response->json('data.suggestions', []);

            return is_array($suggestions) ? $suggestions : [];
        }

        // به‌جای throw مستقیم قبلی، اینجا به سرویس فالبک می‌رویم.
        Log::warning('JustSerp exhausted all active keys for autocomplete, falling back to SearchAPI.io', [
            'query' => $query,
            'last_error' => $lastError,
        ]);

        try {
            return SearchApiIoAutocompleteClient::fromConfig()->autocomplete($query, $options);
        } catch (\Throwable $fallbackException) {
            Log::error('SearchApiIo autocomplete fallback also failed (JustSerp keys exhausted)', [
                'query' => $query,
                'error' => $fallbackException->getMessage(),
            ]);

            throw new RuntimeException(
                'All active JustSerp keys and the SearchAPI.io fallback failed or ran out of quota.'
                .($lastError ? ' JustSerp: '.$lastError : '')
                .' | SearchAPI.io: '.$fallbackException->getMessage()
            );
        }
    }

    public function lastSearchInformation(): ?array
    {
        return $this->lastSearchInfo;
    }
}
