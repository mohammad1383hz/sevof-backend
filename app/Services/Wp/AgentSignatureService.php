<?php

namespace App\Services\Wp;

use App\Models\SiteConnection;

/**
 * مسئولیت این سرویس فقط تولید و بررسی امضای HMAC است؛
 * هم برای درخواست‌های ورودی از پلاگین (verify) و هم درخواست‌های
 * خروجی از Laravel به پلاگین (headers) استفاده می‌شود.
 *
 * نکته‌ی مهم: امضا اکنون شامل method و path هم می‌شود تا دو درخواست
 * مختلف (مثلاً GET /agent/dashboard و GET /agent/keywords) که به‌صورت
 * موازی و با یک timestamp یکسان ارسال شده‌اند، امضای یکسانی تولید نکنند
 * و به‌اشتباه به‌عنوان Replay رد نشوند.
 */
class AgentSignatureService
{
    public function sign(string $clientId, string $secret, string $timestamp, string $body, string $method = '', string $path = ''): string
    {
        $payload = $clientId.$timestamp.strtoupper($method).$path.$body;

        return hash_hmac('sha256', $payload, $secret);
    }

    public function verify(SiteConnection $connection, string $timestamp, string $signature, string $body, string $method = '', string $path = ''): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('wordpress_agent.timestamp_tolerance_seconds', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = $this->sign($connection->client_id, $connection->client_secret, $timestamp, $body, $method, $path);

        return hash_equals($expected, $signature);
    }

    /**
     * هدرهای امضاشده برای درخواست‌های خروجی Laravel -> پلاگین وردپرس
     */
    public function buildOutboundHeaders(SiteConnection $connection, string $body, string $method = 'POST', string $path = ''): array
    {
        $timestamp = (string) time();

        return [
            'X-Agent-Key' => $connection->api_key,
            'X-Timestamp' => $timestamp,
            'X-Signature' => $this->sign($connection->client_id, $connection->client_secret, $timestamp, $body, $method, $path),
        ];
    }
}
