<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Jobs\Products\SyncSiteProductsJob;
use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Services\Shopify\ShopifyOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ShopifyConnectionController extends Controller
{
    public function __construct(private readonly ShopifyOAuthService $oauth)
    {
    }

    public function show(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = $site->shopifyConnection;

        return response()->json([
            'configured' => $this->oauth->isConfigured(),
            'data' => $connection ? $this->payload($connection) : null,
        ]);
    }

    /** Step 1: returns the Shopify consent URL; the front redirects the browser there. */
    public function install(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $data = $request->validate(['shop' => ['required', 'string', 'max:255']]);
        $shop = $this->oauth->normalizeShop($data['shop']);

        if (! $shop) {
            return response()->json([
                'message' => 'Enter your store address, e.g. your-store.myshopify.com.',
                'errors' => ['shop' => ['Enter your store address, e.g. your-store.myshopify.com.']],
            ], 422);
        }

        if (! $this->oauth->isConfigured()) {
            return response()->json(['message' => 'Shopify is not configured on the server yet.'], 503);
        }

        return response()->json(['authorize_url' => $this->oauth->authorizationUrl($site, $shop)]);
    }

    /** Step 2: Shopify redirects the browser here after the merchant approves. */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $connection = $this->oauth->complete($request->query());
            $site = $connection->site;

            // Pull the catalog right away so product SEO works without waiting for the nightly sync.
            SyncSiteProductsJob::dispatch($site->id);

            return redirect()->away($this->front().'/sites/'.$site->id.'/dashboard?shopify=connected');
        } catch (Throwable $e) {
            report($e);

            // Back to the site picker so the merchant can retry; only our own validation messages are shown.
            return redirect()->away($this->front().'/shopify/connect?'.http_build_query([
                'shop' => $this->oauth->normalizeShop($request->query('shop')) ?? '',
                'error' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Could not connect the Shopify store. Please try again.',
            ]));
        }
    }

    /**
     * App URL configured in the Partner dashboard (install from the Shopify App Store / admin).
     * We can't know which Sevof site the store belongs to, so the front asks the user to pick one.
     */
    public function entry(Request $request): RedirectResponse
    {
        $shop = $this->oauth->normalizeShop($request->query('shop'));

        if (! $shop || ! $this->oauth->verifyQueryHmac($request->query())) {
            abort(400, 'Invalid Shopify request.');
        }

        // Already connected → straight to that site's dashboard.
        $connection = ShopifyConnection::where('shop_domain', $shop)->latest('id')->first();

        if ($connection?->site) {
            return redirect()->away($this->front().'/sites/'.$connection->site->id.'/dashboard');
        }

        return redirect()->away($this->front().'/shopify/connect?shop='.rawurlencode($shop));
    }

    public function test(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = $this->findConnectionOrFail($site);
        $result = $this->oauth->test($connection);

        if (! $result['ok']) {
            $connection->update(['last_error' => $result['message']]);
        }

        return response()->json([
            'message' => $result['ok'] ? 'Connected to Shopify.' : $result['message'],
            'data' => $result,
        ], $result['ok'] ? 200 : 422);
    }

    public function disconnect(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $this->oauth->disconnect($this->findConnectionOrFail($site));

        return response()->json(['message' => 'Shopify store disconnected.', 'data' => null]);
    }

    /**
     * app/uninstalled + mandatory GDPR compliance webhooks.
     * We store no customer data, so customer requests need no action.
     */
    public function webhook(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (! $this->oauth->verifyWebhookHmac($body, $request->header('X-Shopify-Hmac-Sha256'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $topic = (string) $request->header('X-Shopify-Topic');
        $shop = $this->oauth->normalizeShop($request->header('X-Shopify-Shop-Domain'));

        if ($shop && in_array($topic, ['app/uninstalled', 'shop/redact'], true)) {
            ShopifyConnection::where('shop_domain', $shop)->delete();
        }

        return response()->json(['ok' => true]);
    }

    private function payload(ShopifyConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'shop_domain' => $connection->shop_domain,
            'shop_name' => $connection->shop_name,
            'shop_url' => $connection->storefrontUrl(),
            'status' => $connection->status,
            'last_error' => $connection->last_error,
            'connected_at' => $connection->created_at,
        ];
    }

    private function findConnectionOrFail(Site $site): ShopifyConnection
    {
        $connection = $site->shopifyConnection;

        abort_if(! $connection, 404, 'This site is not connected to Shopify.');

        return $connection;
    }

    private function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    private function front(): string
    {
        return rtrim((string) config('app.front_url'), '/');
    }
}
