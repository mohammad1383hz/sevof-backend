<?php

namespace App\Services\Shopify;

use App\Contracts\ProductArticlePublisher;
use App\Models\AgentSetting;
use App\Models\ProductArticle;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyProductArticlePublishService implements ProductArticlePublisher
{
    public function __construct(
        private readonly ShopifyProductArticleBuilder $builder,
        private readonly ShopifyPublishService $articles,
    ) {
    }

    public function target(): string
    {
        return 'Shopify';
    }

    public function hasRemoteCopy(ProductArticle $article): bool
    {
        return (bool) $article->shopify_article_id;
    }

    public function createProductArticle(ProductArticle $article): array
    {
        if ($article->status !== ProductArticle::STATUS_COMPLETED) {
            return $this->fail($article, 'publish', 'The product article is still being generated.');
        }

        $published = $this->shouldBePublished($article);

        return $this->execute(
            $article,
            'publish',
            fn (ShopifyClient $client) => $this->articles->createWithFallback($client, $this->builder->build($article, $client, $published, forCreate: true)),
            fn (array $remote, ShopifyClient $client) => $this->markSynced($article, $remote, $client, $published, publishing: true)
        );
    }

    public function updateProductArticle(ProductArticle $article): array
    {
        if (! $article->shopify_article_id) {
            return $this->fail($article, 'update', 'This article has not been published to Shopify yet.');
        }

        $published = $this->shouldBePublished($article);

        $result = $this->execute(
            $article,
            'update',
            fn (ShopifyClient $client) => $this->articles->updateWithFallback($client, $article->shopify_article_id, $this->builder->build($article, $client, $published, forCreate: false)),
            fn (array $remote, ShopifyClient $client) => $this->markSynced($article, $remote, $client, $published, publishing: false)
        );

        if (! $result['ok'] && ($result['status_code'] ?? 0) === 404) {
            $article->update(['shopify_article_id' => null, 'shopify_url' => null, 'wp_sync_status' => 'not_synced']);

            return $this->createProductArticle($article);
        }

        return $result;
    }

    public function deleteProductArticle(ProductArticle $article): array
    {
        if (! $article->shopify_article_id) {
            return $this->fail($article, 'delete', 'This article is not on Shopify.');
        }

        return $this->execute(
            $article,
            'delete',
            function (ShopifyClient $client) use ($article) {
                try {
                    $client->deleteArticle($article->shopify_article_id);
                } catch (ShopifyApiException $e) {
                    if (! $e->isNotFound()) {
                        throw $e;
                    }
                }

                return [];
            },
            fn () => $article->update([
                'wp_sync_status' => 'not_synced',
                'publish_status' => null,
                'shopify_article_id' => null,
                'shopify_url' => null,
                'publish_error' => null,
                'published_at' => null,
                'last_synced_at' => now(),
            ])
        );
    }

    public function syncProductArticleStatus(ProductArticle $article): array
    {
        if (! $article->shopify_article_id) {
            return $this->fail($article, 'sync_status', 'This article is not on Shopify.');
        }

        $result = $this->execute(
            $article,
            'sync_status',
            fn (ShopifyClient $client) => $client->getArticle($article->shopify_article_id),
            fn (array $remote, ShopifyClient $client) => $article->update([
                'wp_sync_status' => 'synced',
                'publish_status' => ($remote['isPublished'] ?? false) ? 'published' : 'draft',
                'shopify_url' => $client->articleUrl($remote) ?? $article->shopify_url,
                'publish_error' => null,
                'last_synced_at' => now(),
            ])
        );

        // Deleted in Shopify: don't keep a dead "synced" link forever.
        if (! $result['ok'] && ($result['status_code'] ?? 0) === 404) {
            $article->update([
                'shopify_article_id' => null,
                'shopify_url' => null,
                'wp_sync_status' => 'not_synced',
                'publish_status' => null,
                'published_at' => null,
                'last_synced_at' => now(),
            ]);

            return ['ok' => false, 'message' => 'This article no longer exists on Shopify.'];
        }

        return $result;
    }

    private function execute(ProductArticle $article, string $action, Closure $operation, Closure $onSuccess): array
    {
        try {
            $client = new ShopifyClient(ShopifyPublishService::connectionFor($article->site->shopifyConnection));

            $article->update(['wp_sync_status' => 'syncing']);

            $data = $operation($client);
        } catch (ShopifyApiException $e) {
            return $this->fail($article, $action, ShopifyPublishService::friendlyError($e), $e->statusCode, $e->body);
        } catch (Throwable $e) {
            return $this->fail($article, $action, ShopifyPublishService::friendlyError($e));
        }

        $onSuccess($data, $client);

        $result = ['ok' => true, 'status_code' => 200, 'body' => $data];
        $this->log($article, $action, $result);

        return $result;
    }

    private function markSynced(ProductArticle $article, array $remote, ShopifyClient $client, bool $published, bool $publishing): void
    {
        $article->update([
            'shopify_article_id' => $remote['id'] ?? $article->shopify_article_id,
            'shopify_url' => $client->articleUrl($remote) ?? $article->shopify_url,
            'wp_sync_status' => 'synced',
            'publish_status' => $published ? 'published' : 'draft',
            'publish_error' => null,
            'published_at' => $published ? ($publishing ? now() : ($article->published_at ?? now())) : null,
            'last_synced_at' => now(),
        ]);
    }

    private function shouldBePublished(ProductArticle $article): bool
    {
        if ($article->publish_status === 'draft') {
            return false;
        }

        if ($article->publish_status === 'published' || $article->shopify_article_id) {
            return true;
        }

        $setting = $article->site->agentSetting ?? AgentSetting::firstOrCreate(['site_id' => $article->site->id]);

        return $setting->default_publish_status === 'publish';
    }

    private function fail(ProductArticle $article, string $action, string $message, int $status = 0, mixed $body = null): array
    {
        $result = ['ok' => false, 'message' => $message, 'status_code' => $status, 'body' => is_array($body) ? $body : null];

        $this->log($article, $action, $result);
        $article->update(['wp_sync_status' => 'failed', 'publish_error' => $message]);

        return $result;
    }

    private function log(ProductArticle $article, string $action, array $result): void
    {
        Log::log($result['ok'] ? 'info' : 'warning', 'shopify_product_article.'.$action, [
            'product_article_id' => $article->id,
            'site_id' => $article->site_id,
            'status_code' => $result['status_code'] ?? null,
            'shopify_article_id' => $article->shopify_article_id,
            'error' => $result['ok'] ? null : ($result['message'] ?? null),
        ]);
    }
}
