<?php

namespace App\Services\Wp;

use App\Models\Site;
use App\Models\SiteConnection;
use App\Models\SiteConnectionLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WordPressConnectionService
{
    public function __construct(
        private readonly AgentSignatureService $signer,
    ) {
    }

    public function connect(Site $site): array
    {
        $credentials = $this->generateCredentials();

        $connection = SiteConnection::updateOrCreate(
            ['site_id' => $site->id],
            [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'api_key' => $credentials['api_key'],
                'status' => SiteConnection::STATUS_PENDING,
                'wp_url' => null,
                'wp_version' => null,
                'plugin_version' => null,
                'connected_at' => null,
                'disconnected_at' => null,
                'last_error' => null,
            ]
        );

        $this->log($site, 'connect_initiated', 'اتصال جدید ایجاد شد و منتظر handshake از پلاگین است.');

        return [
            'connection' => $connection,
            'client_secret' => $credentials['client_secret'],
        ];
    }

    private function generateCredentials(): array
    {
        return [
            'client_id' => (string) Str::uuid(),
            'client_secret' => Str::random(64),
            'api_key' => Str::random(40),
        ];
    }

    public function reconnect(SiteConnection $connection): array
    {
        $credentials = $this->generateCredentials();

        $connection->update([
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'api_key' => $credentials['api_key'],
            'status' => SiteConnection::STATUS_PENDING,
            'connected_at' => null,
            'last_error' => null,
        ]);

        $this->log($connection->site, 'reconnect_initiated', 'اطلاعات اتصال بازتولید شد و منتظر handshake جدید است.');

        return [
            'connection' => $connection->fresh(),
            'client_secret' => $credentials['client_secret'],
        ];
    }

    public function disconnect(SiteConnection $connection): SiteConnection
    {
        $connection->update([
            'status' => SiteConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ]);

        $this->log($connection->site, 'disconnected', 'اتصال به‌صورت دستی قطع شد.');

        return $connection->fresh();
    }

    public function markConnected(SiteConnection $connection, array $wpInfo): SiteConnection
    {
        $connection->update([
            'status' => SiteConnection::STATUS_CONNECTED,
            'wp_url' => $wpInfo['wp_url'] ?? $connection->wp_url,
            'wp_version' => $wpInfo['wp_version'] ?? null,
            'plugin_version' => $wpInfo['plugin_version'] ?? null,
            'connected_at' => now(),
            'last_error' => null,
        ]);

        $this->log($connection->site, 'connected', 'پلاگین وردپرس با موفقیت متصل شد.', $wpInfo);

        return $connection->fresh();
    }

    public function recordHealthCheck(SiteConnection $connection, string $status, array $meta = []): SiteConnection
    {
        $connection->update([
            'last_health_check_at' => now(),
            'last_health_status' => $status,
        ]);

        $this->log($connection->site, 'health_check', "Health status: {$status}", $meta);

        return $connection->fresh();
    }

    /**
     * Manual connection test from the panel: pings the plugin's health endpoint.
     */
    public function testConnection(SiteConnection $connection): array
    {
        if (! $connection->wp_url) {
            return ['ok' => false, 'message' => 'The WordPress site URL is not set yet.'];
        }

        $path = (string) config('wordpress_agent.plugin_routes.health', '/index.php?rest_route=/sevof-connector/v1/health');
        $url = rtrim($connection->wp_url, '/') . '/' . ltrim($path, '/');
        $method = 'GET';
        $body = '';

        // This must match the WordPress plugin REST namespace exactly.
        $signPath = ltrim(
            (string) config('wordpress_agent.signing_paths.health', 'sevof-connector/v1/health'),
            '/'
        );

        $headers = $this->signer->buildOutboundHeaders($connection, $body, $method, $signPath);

        Log::info('wp_agent.test_connection.request', [
            'site_id' => $connection->site_id,
            'connection_id' => $connection->id,
            'url' => $url,
            'method' => $method,
            'path' => $path,
            'sign_path' => $signPath,
            'client_id' => $connection->client_id,
            'timestamp' => $headers['X-Timestamp'] ?? null,
            'signature' => $headers['X-Signature'] ?? null,
        ]);

        try {
            [$statusCode, $responseBody, $transportError] = $this->sendDirect($url, $method, $headers, $body);

            if ($transportError !== null) {
                Log::error('wp_agent.test_connection.exception', [
                    'site_id' => $connection->site_id,
                    'connection_id' => $connection->id,
                    'url' => $url,
                    'error' => $transportError,
                ]);

                $this->recordHealthCheck($connection, 'unreachable', ['error' => $transportError]);
                $connection->update(['last_error' => $transportError]);

                return ['ok' => false, 'message' => 'The WordPress site is unreachable: ' . $transportError];
            }

            $decodedBody = json_decode((string) $responseBody, true);
            $ok = $statusCode >= 200 && $statusCode < 300;

            Log::info('wp_agent.test_connection.response', [
                'site_id' => $connection->site_id,
                'connection_id' => $connection->id,
                'status_code' => $statusCode,
                'ok' => $ok,
                'body' => $decodedBody,
            ]);

            $this->recordHealthCheck($connection, $ok ? 'healthy' : 'unhealthy', [
                'status_code' => $statusCode,
            ]);

            if (! $ok) {
                $message = is_array($decodedBody)
                    ? ($decodedBody['message'] ?? "The plugin responded with code {$statusCode}.")
                    : "The plugin responded with code {$statusCode}.";

                $connection->update(['last_error' => (string) $message]);
            } else {
                $connection->update(['last_error' => null]);
            }

            return [
                'ok' => $ok,
                'status_code' => $statusCode,
                'body' => $decodedBody,
            ];
        } catch (Throwable $e) {
            Log::error('wp_agent.test_connection.exception', [
                'site_id' => $connection->site_id,
                'connection_id' => $connection->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            $this->recordHealthCheck($connection, 'unreachable', ['error' => $e->getMessage()]);
            $connection->update(['last_error' => $e->getMessage()]);

            return ['ok' => false, 'message' => 'The WordPress site is unreachable: ' . $e->getMessage()];
        }
    }

    /**
     * Sends the signed request to WordPress.
     * Returns [status_code, raw_body, transport_error|null].
     */
    private function sendDirect(string $url, string $method, array $headers, string $body): array
    {
        try {
            $request = Http::withHeaders($headers)
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(10)
                ->timeout((int) config('wordpress_agent.request_timeout', 20));

            if ($body !== '') {
                $request = $request->withBody($body, 'application/json');
            }

            $response = $request->send(strtoupper($method), $url);

            return [$response->status(), $response->body(), null];
        } catch (Throwable $e) {
            return [0, null, $e->getMessage()];
        }
    }

    private function log(Site $site, string $event, ?string $message = null, array $meta = []): void
    {
        SiteConnectionLog::create([
            'site_id' => $site->id,
            'event' => $event,
            'message' => $message,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
