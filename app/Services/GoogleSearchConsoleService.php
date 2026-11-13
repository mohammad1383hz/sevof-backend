<?php

namespace App\Services;

use App\Exceptions\SearchConsoleQuotaException;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use App\Models\User;
use App\Support\PageUrl;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleSearchConsoleService
{
    private const READONLY_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE_URL = 'https://www.googleapis.com/webmasters/v3';
    private const INSPECTION_URL = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    /** @param  string|null  $returnTo  صفحه‌ای که بعد از اتصال به آن برمی‌گردیم (فقط مقدارهای مجاز کنترلر) */
    public function authorizationUrl(User $user, Site $site, ?string $returnTo = null): string
    {
        $state = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'site_id' => $site->id,
            'return_to' => $returnTo,
            'nonce' => Str::random(40),
            'issued_at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            // openid email: only to show the user which Google account is connected
            'scope' => self::READONLY_SCOPE.' openid email',
            'access_type' => 'offline',
            // select_account: let the user pick a different Google account
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function completeAuthorization(string $code, string $state): array
    {
        $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);

        if (! isset($payload['user_id'], $payload['site_id'], $payload['issued_at'])
            || now()->timestamp - (int) $payload['issued_at'] > 600) {
            throw new RuntimeException('The Search Console connection request expired or is invalid. Please try again.');
        }

        $user = User::findOrFail($payload['user_id']);
        $site = Site::whereKey($payload['site_id'])->where('user_id', $user->id)->firstOrFail();

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException('Could not get a Google Search Console token.');
        }

        $existing = GoogleSearchConsoleConnection::where('user_id', $user->id)->first();
        $connection = GoogleSearchConsoleConnection::updateOrCreate(
            ['user_id' => $user->id],
            [
                'google_email' => $this->emailFromIdToken((string) $response->json('id_token')),
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?: $existing?->refresh_token,
                'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
                'scope' => $response->json('scope', self::READONLY_SCOPE),
                'status' => 'connected',
                'last_error' => null,
            ],
        );

        $returnTo = $payload['return_to'] ?? null;

        return compact('site', 'connection', 'returnTo');
    }

    public function properties(GoogleSearchConsoleConnection $connection): array
    {
        $response = Http::withToken($this->accessToken($connection))
            ->timeout(30)
            ->get(self::API_BASE_URL.'/sites');

        if (! $response->successful()) {
            $this->markConnectionError($connection, $response->body());
            throw new RuntimeException('Could not load your Google Search Console sites.');
        }

        return collect($response->json('siteEntry', []))
            ->filter(fn (array $property) => ($property['permissionLevel'] ?? '') !== 'siteUnverifiedUser')
            ->map(fn (array $property) => [
                'property_url' => $property['siteUrl'],
                'permission_level' => $property['permissionLevel'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function assignProperty(User $user, Site $site, string $propertyUrl): SiteSearchConsoleProperty
    {
        $connection = GoogleSearchConsoleConnection::where('user_id', $user->id)
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            throw new RuntimeException('Connect your Google Search Console account first.');
        }

        $property = collect($this->properties($connection))
            ->firstWhere('property_url', $propertyUrl);

        if (! $property) {
            throw new RuntimeException('This property isn\'t accessible in your Google Search Console account.');
        }

        return SiteSearchConsoleProperty::updateOrCreate(
            ['site_id' => $site->id],
            [
                'google_search_console_connection_id' => $connection->id,
                'property_url' => $property['property_url'],
                'permission_level' => $property['permission_level'],
                'sync_status' => 'idle',
                'last_sync_error' => null,
            ],
        );
    }

    /**
     * داده‌ی ۲۸ روز اخیر (تا ۳ روز قبل، چون داده‌ی نهایی گوگل دیر می‌رسد) و ۲۸ روز قبل از آن.
     * سه نوع داده گرفته می‌شود: کوئری+صفحه (برای ایده و لینک)، فقط صفحه (آمار دقیق هر مقاله،
     * شامل کوئری‌های ناشناس که در بُعد کوئری نمی‌آیند) و جمع کل سایت.
     */
    public function sync(SiteSearchConsoleProperty $property): int
    {
        $property->load(['connection', 'site']);
        $connection = $property->connection;

        if (! $connection || $connection->status !== 'connected') {
            throw new RuntimeException('Google Search Console connection is unavailable.');
        }

        $currentStart = now()->subDays(31)->toDateString();
        $currentEnd = now()->subDays(3)->toDateString();
        $previousStart = now()->subDays(59)->toDateString();
        $previousEnd = now()->subDays(32)->toDateString();
        $endpoint = self::API_BASE_URL.'/sites/'.rawurlencode($property->property_url).'/searchAnalytics/query';

        $property->update(['sync_status' => 'syncing', 'last_sync_error' => null]);

        try {
            $token = $this->accessToken($connection);
            $current = fn (array $dimensions) => $this->queryAnalytics($token, $endpoint, $currentStart, $currentEnd, $dimensions);
            $previous = fn (array $dimensions) => $this->queryAnalytics($token, $endpoint, $previousStart, $previousEnd, $dimensions);

            $currentRows = $current(['query', 'page']);
            $previousByKey = $this->keyRows($previous(['query', 'page']));
            $currentPages = $current(['page']);
            $previousPagesByKey = $this->keyRows($previous(['page']));
            $totals = [
                'start_date' => $currentStart,
                'end_date' => $currentEnd,
                'current' => $this->totalsOf($current([])),
                'previous' => $this->totalsOf($previous([])),
            ];
            $now = now();
            $base = [
                'site_id' => $property->site_id,
                'site_search_console_property_id' => $property->id,
                'start_date' => $currentStart,
                'end_date' => $currentEnd,
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $queryRecords = collect($currentRows)
                ->filter(fn (array $row) => ! empty($row['keys'][0]))
                ->map(function (array $row) use ($previousByKey, $base) {
                    $previous = $previousByKey[implode('|', $row['keys'])] ?? null;
                    $impressions = (int) ($row['impressions'] ?? 0);
                    $previousImpressions = $previous ? (int) ($previous['impressions'] ?? 0) : null;

                    return $base + [
                        'query' => Str::limit((string) $row['keys'][0], 1000, ''),
                        'page' => $row['keys'][1] ?? null,
                        'page_hash' => PageUrl::hash($row['keys'][1] ?? null),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'previous_clicks' => $previous ? (int) ($previous['clicks'] ?? 0) : null,
                        'impressions' => $impressions,
                        'previous_impressions' => $previousImpressions,
                        'ctr' => (float) ($row['ctr'] ?? 0),
                        'previous_ctr' => $previous ? (float) ($previous['ctr'] ?? 0) : null,
                        'position' => isset($row['position']) ? (float) $row['position'] : null,
                        'previous_position' => $previous && isset($previous['position']) ? (float) $previous['position'] : null,
                        'trend_percentage' => $previousImpressions ? (int) round((($impressions - $previousImpressions) / $previousImpressions) * 100) : null,
                    ];
                });

            $pageRecords = collect($currentPages)
                ->filter(fn (array $row) => PageUrl::hash($row['keys'][0] ?? null) !== null)
                ->map(function (array $row) use ($previousPagesByKey, $base) {
                    $previous = $previousPagesByKey[$row['keys'][0]] ?? null;

                    return $base + [
                        'page' => Str::limit((string) $row['keys'][0], 2000, ''),
                        'page_hash' => PageUrl::hash($row['keys'][0]),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'previous_clicks' => $previous ? (int) ($previous['clicks'] ?? 0) : null,
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'previous_impressions' => $previous ? (int) ($previous['impressions'] ?? 0) : null,
                        'ctr' => (float) ($row['ctr'] ?? 0),
                        'position' => isset($row['position']) ? (float) $row['position'] : null,
                        'previous_position' => $previous && isset($previous['position']) ? (float) $previous['position'] : null,
                    ];
                });

            DB::transaction(function () use ($property, $queryRecords, $pageRecords, $totals) {
                DB::table('search_console_query_metrics')->where('site_search_console_property_id', $property->id)->delete();
                DB::table('search_console_page_metrics')->where('site_search_console_property_id', $property->id)->delete();

                foreach ($queryRecords->chunk(500) as $chunk) {
                    DB::table('search_console_query_metrics')->insert($chunk->values()->all());
                }

                foreach ($pageRecords->chunk(500) as $chunk) {
                    DB::table('search_console_page_metrics')->insert($chunk->values()->all());
                }

                $property->update([
                    'sync_status' => 'done',
                    'last_synced_at' => now(),
                    'last_sync_error' => null,
                    'totals' => $totals,
                ]);
            });

            return count($currentRows);
        } catch (\Throwable $exception) {
            $property->update([
                'sync_status' => 'failed',
                'last_sync_error' => Str::limit($exception->getMessage(), 2000),
            ]);

            throw $exception;
        }
    }

    /** @param array<int, string> $dimensions خالی = جمع کل سایت (یک ردیف) */
    protected function queryAnalytics(string $accessToken, string $endpoint, string $startDate, string $endDate, array $dimensions): array
    {
        $body = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'type' => 'web',
            'dataState' => 'final',
        ];

        if ($dimensions !== []) {
            $body['dimensions'] = $dimensions;
            $body['rowLimit'] = 5000;
        }

        $response = Http::withToken($accessToken)->timeout(45)->post($endpoint, $body);

        if (! $response->successful()) {
            throw new RuntimeException('Fetching Search Console data failed: '.Str::limit($response->body(), 500));
        }

        $rows = $response->json('rows', []);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, array> کلید = keys به هم چسبیده */
    protected function keyRows(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[implode('|', $row['keys'] ?? [])] = $row;
        }

        return $keyed;
    }

    /** @return array{clicks:int, impressions:int, ctr:float, position:float|null} */
    protected function totalsOf(array $rows): array
    {
        $row = $rows[0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round((float) ($row['ctr'] ?? 0), 6),
            'position' => isset($row['position']) ? round((float) $row['position'], 2) : null,
        ];
    }

    /**
     * وضعیت ایندکس یک آدرس در گوگل (URL Inspection API). پاسخ به انگلیسی گرفته می‌شود تا مقدارها ثابت بمانند.
     * سهمیه: ۲۰۰۰ بررسی در روز و ۶۰۰ در دقیقه برای هر property.
     *
     * @return array{verdict:?string, coverage_state:?string, indexing_state:?string, robots_txt_state:?string, page_fetch_state:?string, last_crawl_time:?string, google_canonical:?string, inspection_link:?string}
     */
    public function inspectUrl(SiteSearchConsoleProperty $property, string $url): array
    {
        $connection = $property->connection;

        if (! $connection || $connection->status !== 'connected') {
            throw new RuntimeException('Google Search Console connection is unavailable.');
        }

        $response = Http::withToken($this->accessToken($connection))
            ->timeout(30)
            ->post(self::INSPECTION_URL, [
                'inspectionUrl' => $url,
                'siteUrl' => $property->property_url,
                'languageCode' => 'en-US',
            ]);

        if ($response->status() === 429) {
            throw new SearchConsoleQuotaException;
        }

        if (! $response->successful()) {
            throw new RuntimeException('Search Console URL inspection failed: '.Str::limit((string) ($response->json('error.message') ?? $response->body()), 300));
        }

        $result = (array) $response->json('inspectionResult.indexStatusResult', []);

        return [
            'verdict' => $result['verdict'] ?? null,
            'coverage_state' => $result['coverageState'] ?? null,
            'indexing_state' => $result['indexingState'] ?? null,
            'robots_txt_state' => $result['robotsTxtState'] ?? null,
            'page_fetch_state' => $result['pageFetchState'] ?? null,
            'last_crawl_time' => $result['lastCrawlTime'] ?? null,
            'google_canonical' => $result['googleCanonical'] ?? null,
            'inspection_link' => $response->json('inspectionResult.inspectionResultLink'),
        ];
    }

    /** آدرس باید زیرمجموعه‌ی property باشد، وگرنه گوگل خطا می‌دهد (و سهمیه هدر می‌رود). */
    public static function urlBelongsToProperty(string $propertyUrl, string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_starts_with($propertyUrl, 'sc-domain:')) {
            $domain = mb_strtolower(substr($propertyUrl, strlen('sc-domain:')));

            return $host === $domain || str_ends_with($host, '.'.$domain);
        }

        return str_starts_with(mb_strtolower($url), mb_strtolower(rtrim($propertyUrl, '/')).'/')
            || mb_strtolower(rtrim($url, '/')) === mb_strtolower(rtrim($propertyUrl, '/'));
    }

    protected function accessToken(GoogleSearchConsoleConnection $connection): string
    {
        if ($connection->expires_at && $connection->expires_at->isAfter(now()->addMinutes(2))) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            $this->markConnectionError($connection, 'Refresh token موجود نیست.');
            throw new RuntimeException('Your Google connection expired; please reconnect your account.');
        }

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            $this->markConnectionError($connection, $response->body());
            throw new RuntimeException('Refreshing Google access failed; please reconnect your account.');
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
            'status' => 'connected',
            'last_error' => null,
        ]);

        return $connection->fresh()->access_token;
    }

    /** The id_token came straight from Google's token endpoint over HTTPS, so no signature check is needed (per Google docs). */
    public function emailFromIdToken(string $idToken): ?string
    {
        $payload = explode('.', $idToken)[1] ?? '';
        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims) && is_string($claims['email'] ?? null) ? $claims['email'] : null;
    }

    /** Fully disconnect the user's Google account: revoke the token (best effort) and delete the connection and every site's property */
    public function disconnectAccount(GoogleSearchConsoleConnection $connection): void
    {
        try {
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', [
                'token' => $connection->refresh_token ?: $connection->access_token,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $connection->delete();
    }

    protected function markConnectionError(GoogleSearchConsoleConnection $connection, string $error): void
    {
        $connection->update([
            'status' => 'failed',
            'last_error' => Str::limit($error, 4000),
        ]);
    }

    protected function clientId(): string
    {
        return (string) config('seobot.google.search_console_client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('seobot.google.search_console_client_secret');
    }

    protected function redirectUri(): string
    {
        return (string) config('seobot.google.search_console_redirect_uri');
    }
}
