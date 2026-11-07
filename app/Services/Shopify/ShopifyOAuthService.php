<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Shopify OAuth (authorization code grant) for a public app.
 * Docs: https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/authorization-code-grant
 */
class ShopifyOAuthService
{
    private const STATE_TTL_MINUTES = 15;

    /** "my-store", "my-store.myshopify.com" or a full admin URL → "my-store.myshopify.com"; null if invalid. */
    public function normalizeShop(?string $shop): ?string
    {
        $shop = mb_strtolower(trim((string) $shop));
        $shop = preg_replace('#^https?://#', '', $shop) ?? '';
        $shop = explode('/', $shop)[0];

        if ($shop !== '' && ! str_contains($shop, '.')) {
            $shop .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/', $shop) ? $shop : null;
    }

    public function isConfigured(): bool
    {
        return (string) config('shopify.client_id') !== '' && (string) config('shopify.client_secret') !== '';
    }

    public function authorizationUrl(Site $site, string $shop): string
    {
        $nonce = Str::random(40);
        Cache::put("shopify:oauth:{$nonce}", true, now()->addMinutes(self::STATE_TTL_MINUTES));

        $state = Crypt::encryptString(json_encode([
            'site' => $site->id,
            'user' => $site->user_id,
            'shop' => $shop,
            'nonce' => $nonce,
        ]));

        return "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => config('shopify.client_id'),
            'scope' => config('shopify.scopes'),
            'redirect_uri' => config('shopify.redirect_uri'),
            'state' => $state,
        ]);
    }

    /**
     * Validates the callback (HMAC, shop, one-time state), exchanges the code and stores the connection.
     */
    public function complete(array $query): ShopifyConnection
    {
        if (! $this->verifyQueryHmac($query)) {
            throw new InvalidArgumentException('Invalid Shopify signature.');
        }

        $shop = $this->normalizeShop($query['shop'] ?? null);

        try {
            $state = json_decode(Crypt::decryptString((string) ($query['state'] ?? '')), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid OAuth state.');
        }

        if (! $shop || $shop !== ($state['shop'] ?? null) || ! Cache::pull('shopify:oauth:'.($state['nonce'] ?? ''))) {
            throw new InvalidArgumentException('The Shopify authorization expired or does not match. Please try again.');
        }

        $site = Site::whereKey($state['site'] ?? 0)->where('user_id', $state['user'] ?? 0)->firstOrFail();

        $response = Http::asJson()->acceptJson()->timeout(20)->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => config('shopify.client_id'),
            'client_secret' => config('shopify.client_secret'),
            'code' => (string) ($query['code'] ?? ''),
        ]);

        $token = (string) $response->json('access_token');

        if (! $response->successful() || $token === '') {
            throw new InvalidArgumentException('Shopify did not return an access token.');
        }

        $connection = ShopifyConnection::updateOrCreate(
            ['site_id' => $site->id],
            [
                'shop_domain' => $shop,
                'access_token' => $token,
                'scopes' => (string) $response->json('scope'),
                'status' => 'connected',
                'last_error' => null,
            ],
        );

        $this->finishSetup($connection);

        return $connection;
    }

    /** Stores the storefront domain + default blog and subscribes to app/uninstalled. Best effort. */
    public function finishSetup(ShopifyConnection $connection): void
    {
        $client = new ShopifyClient($connection);

        try {
            $shop = $client->shop();
            $connection->update([
                'shop_name' => $shop['name'] ?? null,
                'shop_url' => data_get($shop, 'primaryDomain.url') ?: 'https://'.$connection->shop_domain,
                'blog_id' => $connection->blog_id ?: $client->defaultBlogId(),
            ]);
        } catch (Throwable $e) {
            Log::warning('shopify.setup_failed', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
        }

        try {
            $client->mutate('webhookSubscriptionCreate', 'mutation($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
                webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) { webhookSubscription { id } userErrors { field message } }
            }', ['topic' => 'APP_UNINSTALLED', 'sub' => ['callbackUrl' => config('shopify.webhook_uri'), 'format' => 'JSON']]);
        } catch (Throwable $e) {
            // Already subscribed ("address for this topic has already been taken") is fine.
            Log::info('shopify.webhook_subscribe', ['connection_id' => $connection->id, 'error' => $e->getMessage()]);
        }
    }

    /** Checks a live call with the stored token. */
    public function test(ShopifyConnection $connection): array
    {
        try {
            $shop = (new ShopifyClient($connection))->shop();

            return ['ok' => true, 'message' => null, 'shop_name' => $shop['name'] ?? null];
        } catch (ShopifyApiException $e) {
            return [
                'ok' => false,
                'message' => $e->isUnauthorized()
                    ? 'Shopify revoked access for this store. Please reconnect.'
                    : $e->getMessage(),
            ];
        }
    }

    /** Uninstalls our app from the store (revokes the token) and forgets the connection. */
    public function disconnect(ShopifyConnection $connection): void
    {
        try {
            (new ShopifyClient($connection))->query('mutation { appUninstall { userErrors { message } } }');
        } catch (Throwable) {
            // token may already be revoked; deleting the row is what matters
        }

        $connection->delete();
    }

    /** HMAC of the OAuth/app-launch query string (hex, all params except hmac/signature, sorted). */
    public function verifyQueryHmac(array $query): bool
    {
        $hmac = (string) ($query['hmac'] ?? '');
        unset($query['hmac'], $query['signature']);
        ksort($query);

        $message = collect($query)
            ->map(fn ($value, $key) => $key.'='.(is_array($value) ? json_encode($value) : $value))
            ->implode('&');

        $secret = (string) config('shopify.client_secret');

        return $secret !== '' && $hmac !== '' && hash_equals(hash_hmac('sha256', $message, $secret), $hmac);
    }

    /** HMAC of a webhook body (base64). */
    public function verifyWebhookHmac(string $body, ?string $header): bool
    {
        $secret = (string) config('shopify.client_secret');

        return $secret !== '' && $header
            && hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $header);
    }
}
