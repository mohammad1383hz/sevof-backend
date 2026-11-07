<?php

namespace App\Services\AI\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

trait UsesGapgptApiKey
{
    /**
     * درخواست chat/completions رو با یکی از کلیدهای فعال جدول gapgpt می‌زنه.
     */
    protected function callGapgptChat(array $payload, int $timeout = 90): Response
    {
        $timeout = max(1, $timeout);

        $keys = DB::table('gapgpt')
            ->where('status', 'active')
            ->orderByRaw('active_requests ASC, RAND()')
            ->get(['id', 'api_key', 'base_url']);

        if ($keys->isEmpty()) {
            throw new RuntimeException('Server error');
        }

        $lastResponse = null;
        $lastException = null;
        $deadline = microtime(true) + $timeout;
        $skippedHosts = [];

        foreach ($keys as $row) {
            $remaining = (int) ceil($deadline - microtime(true));
            if ($remaining <= 0) {
                break;
            }

            $requestTimeout = min($timeout, $remaining);
            $apiKey = $row->api_key;
            $baseUrl = rtrim((string) $row->base_url, '/');

            if ($baseUrl === '') {
                Log::warning('[Gapgpt] کلید API فاقد base_url است، رد شد', [
                    'api_key_suffix' => substr($apiKey, -6),
                ]);
                continue;
            }

            $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            if (isset($skippedHosts[$host])) {
                continue;
            }

            DB::table('gapgpt')->where('id', $row->id)->increment('active_requests');

            try {
                // اتصال ۳ ثانیه‌ای با کمی کندی شبکه کل مقاله را متوقف می‌کرد؛ ۱۰ ثانیه + یک تلاش دوباره فقط برای خطای اتصال
                $response = Http::withToken($apiKey)
                    ->connectTimeout(min(10, $requestTimeout))
                    ->timeout($requestTimeout)
                    ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false)
                    ->post($baseUrl.'/chat/completions', $payload);
            } catch (ConnectionException $e) {
                Log::warning('[Gapgpt] خطای اتصال/timeout، تلاش با کلید بعدی', [
                    'host' => $host,
                    'api_key_suffix' => substr($apiKey, -6),
                    'error' => $e->getMessage(),
                ]);

                $lastException = $e;
                continue;
            } finally {
                DB::table('gapgpt')
                    ->where('id', $row->id)
                    ->where('active_requests', '>', 0)
                    ->decrement('active_requests');
            }

            if ($response->successful()) {
                return $response;
            }

            // 🛡 از اینجا به بعد response ناموفقه؛ جزئیات واقعی رو لاگ می‌کنیم
            // ولی چیزی که برمی‌گردونیم (یا caller نمایش می‌ده) رو sanitize می‌کنیم.
            $lastResponse = $this->sanitizeErrorResponse($response, $host, $apiKey);

            if ($this->isUnsupportedParamError($response)) {
                Log::warning('[Gapgpt] پروایدر پارامتر payload را نپذیرفت، تلاش با کلید پروایدر دیگر', [
                    'host' => $host,
                    'api_key_suffix' => substr($apiKey, -6),
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                $skippedHosts[$host] = true;
                continue;
            }

            if ($this->isQuotaExhausted($response)) {
                Log::warning('[Gapgpt] کلید API غیرفعال شد (اعتبار تمام)', [
                    'api_key_suffix' => substr($apiKey, -6),
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);

                DB::table('gapgpt')->where('api_key', $apiKey)->update([
                    'status' => 'deactive',
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ($response->status() === 429) {
                Log::warning('[Gapgpt] rate limit موقت، تلاش با کلید بعدی (کلید غیرفعال نشد)', [
                    'host' => $host,
                    'api_key_suffix' => substr($apiKey, -6),
                    'body' => substr($response->body(), 0, 300),
                ]);

                continue;
            }

            // خطای دیگه‌ای بود که کلید بعدی حلش نمی‌کنه → همینِ نسخه‌ی sanitize شده رو برگردون
            return $lastResponse;
        }

        if ($lastResponse) {
            return $lastResponse;
        }

        if ($lastException) {
            // پیام خطای connection/timeout هم نباید عیناً به کاربر نهایی برسه
            throw new RuntimeException(
                config('app.debug')
                    ? $lastException->getMessage()
                    : 'خطا در ارتباط با سرویس هوش مصنوعی، لطفاً دوباره تلاش کنید.'
            );
        }

        throw new RuntimeException('None of the active keys has a valid base_url.');
    }

    /**
     * بدنه‌ی خام خطای پروایدر رو با یک پیام عمومی جایگزین می‌کنه تا جزئیات
     * داخلی (ساختار پروایدر، پیام‌های فنی و...) به کاربر نهایی درز نکنه.
     * جزئیات واقعی همیشه لاگ می‌شن و فقط وقتی APP_DEBUG=true باشه عیناً
     * در response برگردونده می‌شن.
     */
    protected function sanitizeErrorResponse(Response $response, string $host, string $apiKey): Response
    {
        if (config('app.debug')) {
            return $response;
        }

        $status = $response->status();

        Log::error('[Gapgpt] پاسخ ناموفق (نسخه‌ی خام برای کاربر مخفی شد)', [
            'host' => $host,
            'api_key_suffix' => substr($apiKey, -6),
            'status' => $status,
            'raw_body' => substr($response->body(), 0, 1000),
        ]);

        $safeMessage = match (true) {
            $status === 429 => 'محدودیت درخواست، لطفاً کمی بعد دوباره امتحان کنید.',
            $status >= 500 => 'سرویس هوش مصنوعی موقتاً در دسترس نیست، لطفاً دوباره تلاش کنید.',
            default => 'خطا در پردازش درخواست، لطفاً دوباره تلاش کنید.',
        };

        $safeBody = json_encode([
            'error' => [
                'message' => $safeMessage,
                'code' => 'ai_service_error',
            ],
        ]);

        $psrResponse = new \GuzzleHttp\Psr7\Response(
            $status,
            ['Content-Type' => 'application/json'],
            $safeBody
        );

        return new Response($psrResponse);
    }

    protected function isUnsupportedParamError(Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        $decoded = json_decode($response->body(), true);
        $message = strtolower((string) ($decoded['error']['message'] ?? $response->body()));

        $needles = [
            'unsupported value',
            'unsupported parameter',
            'does not support',
            'unknown parameter',
        ];

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function isQuotaExhausted(Response $response): bool
    {
        if (in_array($response->status(), [401, 402], true)) {
            return true;
        }

        $body = $response->body();
        $decoded = json_decode($body, true);
        $code = $decoded['error']['code'] ?? null;

        $quotaCodes = [
            'insufficient_quota',
            'insufficient_user_quota',
            'insufficient_balance',
            'quota_exceeded',
            'gap_api_error',
        ];

        if ($code !== null && in_array($code, $quotaCodes, true)) {
            return true;
        }

        $lower = strtolower($body);

        $needles = [
            'insufficient_quota',
            'insufficient_user_quota',
            'exceeded your current quota',
            'pre-consume quota failed',
            'remaining user quota',
            'quota',
            'credit',
            'balance',
        ];

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
