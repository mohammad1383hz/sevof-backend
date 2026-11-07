<?php

namespace Tests\Unit;

use App\Services\Billing\StripeService;
use App\Services\Shopify\ShopifyOAuthService;
use Tests\TestCase;

class SevofIntegrationsTest extends TestCase
{
    public function test_stripe_webhook_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $stripe = app(StripeService::class);
        $payload = '{"type":"checkout.session.completed"}';
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test');

        $this->assertTrue($stripe->verifyWebhook($payload, "t={$t},v1={$sig}"));
        $this->assertFalse($stripe->verifyWebhook($payload.' ', "t={$t},v1={$sig}"), 'tampered body');
        $this->assertFalse($stripe->verifyWebhook($payload, "t=".($t - 1000).",v1=".hash_hmac('sha256', ($t - 1000).".{$payload}", 'whsec_test')), 'replayed');
        $this->assertFalse($stripe->verifyWebhook($payload, null));
    }

    public function test_shopify_shop_normalization(): void
    {
        $oauth = app(ShopifyOAuthService::class);

        $this->assertSame('my-store.myshopify.com', $oauth->normalizeShop('my-store'));
        $this->assertSame('my-store.myshopify.com', $oauth->normalizeShop('https://My-Store.myshopify.com/admin'));
        $this->assertNull($oauth->normalizeShop('evil.com'));
        $this->assertNull($oauth->normalizeShop('my-store.myshopify.com.evil.com'));
        $this->assertNull($oauth->normalizeShop(''));
    }

    public function test_shopify_hmacs(): void
    {
        config(['shopify.client_secret' => 'shh']);
        $oauth = app(ShopifyOAuthService::class);

        $query = ['shop' => 'a.myshopify.com', 'code' => 'abc', 'timestamp' => '1700000000', 'state' => 'x'];
        ksort($query);
        $query['hmac'] = hash_hmac('sha256', http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'shh');

        $this->assertTrue($oauth->verifyQueryHmac($query));
        $this->assertFalse($oauth->verifyQueryHmac(['shop' => 'b.myshopify.com'] + $query));

        $body = '{"id":1}';
        $this->assertTrue($oauth->verifyWebhookHmac($body, base64_encode(hash_hmac('sha256', $body, 'shh', true))));
        $this->assertFalse($oauth->verifyWebhookHmac($body, 'nope'));
    }
}
