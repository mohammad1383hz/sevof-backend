<?php

namespace App\Services\Shopify;

use App\Contracts\ArticlePublisher;
use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticlePublishLog;
use App\Models\ShopifyConnection;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Publishes articles as Shopify blog articles. Same result shape as the WordPress publisher:
 * ['ok' => bool, 'status_code' => int, 'message' => ?string, 'body' => ?array].
 */
class ShopifyPublishService implements ArticlePublisher
{
    public function __construct(private readonly ShopifyArticleBuilder $builder)
    {
    }

    public function create(Article $article): array
    {
        $published = $this->shouldBePublished($article);

        return $this->execute(
            $article,
            ArticlePublishLog::ACTION_PUBLISH,
            fn (ShopifyClient $client) => $this->createWithFallback($client, $this->builder->build($article, $client, $published, forCreate: true)),
            fn (array $remote, ShopifyClient $client) => $this->markSynced($article, $remote, $client, $published, publishing: true)
        );
    }

    public function update(Article $article): array
    {
        if (! $article->shopify_article_id) {
            return $this->fail($article, ArticlePublishLog::ACTION_UPDATE, 'This article has not been published to Shopify yet.');
        }

        $published = $this->shouldBePublished($article);

        $result = $this->execute(
            $article,
            ArticlePublishLog::ACTION_UPDATE,
            fn (ShopifyClient $client) => $this->updateWithFallback($client, $article->shopify_article_id, $this->builder->build($article, $client, $published, forCreate: false)),
            fn (array $remote, ShopifyClient $client) => $this->markSynced($article, $remote, $client, $published, publishing: false)
        );

        // Deleted in Shopify (or the store changed) → create it again.
        if (! $result['ok'] && ($result['status_code'] ?? 0) === 404) {
            $article->update(['shopify_article_id' => null, 'shopify_url' => null, 'wp_sync_status' => 'not_synced']);

            return $this->create($article);
        }

        return $result;
    }

    public function delete(Article $article): array
    {
        if (! $article->shopify_article_id) {
            return $this->fail($article, ArticlePublishLog::ACTION_DELETE, 'This article is not on Shopify.');
        }

        return $this->execute(
            $article,
            ArticlePublishLog::ACTION_DELETE,
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

    public function syncStatus(Article $article): array
    {
        if (! $article->shopify_article_id) {
            return $this->fail($article, ArticlePublishLog::ACTION_SYNC_STATUS, 'This article is not on Shopify.');
        }

        return $this->execute(
            $article,
            ArticlePublishLog::ACTION_SYNC_STATUS,
            fn (ShopifyClient $client) => $client->getArticle($article->shopify_article_id),
            fn (array $remote, ShopifyClient $client) => $article->update([
                'wp_sync_status' => 'synced',
                'publish_status' => ($remote['isPublished'] ?? false) ? 'published' : 'draft',
                'shopify_url' => $client->articleUrl($remote) ?? $article->shopify_url,
                'publish_error' => null,
                'last_synced_at' => now(),
            ])
        );
    }

    // ---------- Shared with the product-article publisher ----------

    /** A title_tag metafield of another type on the store must not block publishing. */
    public function createWithFallback(ShopifyClient $client, array $input): array
    {
        try {
            return $client->createArticle($input);
        } catch (ShopifyApiException $e) {
            if (! isset($input['metafields']) || ! str_contains(mb_strtolower($e->getMessage()), 'metafield')) {
                throw $e;
            }

            unset($input['metafields']);

            return $client->createArticle($input);
        }
    }

    public function updateWithFallback(ShopifyClient $client, string $id, array $input): array
    {
        try {
            return $client->updateArticle($id, $input);
        } catch (ShopifyApiException $e) {
            if (! isset($input['metafields']) || ! str_contains(mb_strtolower($e->getMessage()), 'metafield')) {
                throw $e;
            }

            unset($input['metafields']);

            return $client->updateArticle($id, $input);
        }
    }

    public static function connectionFor(?ShopifyConnection $connection): ShopifyConnection
    {
        if (! $connection || ! $connection->isConnected()) {
            throw new RuntimeException('This site is not connected to Shopify.');
        }

        return $connection;
    }

    public static function friendlyError(Throwable $e): string
    {
        if ($e instanceof ShopifyApiException && $e->isUnauthorized()) {
            return 'Shopify access was revoked or is missing a permission. Reconnect the store from Automation.';
        }

        return mb_substr($e->getMessage(), 0, 500);
    }

    // ---------- Core flow ----------

    private function execute(Article $article, string $action, Closure $operation, Closure $onSuccess): array
    {
        try {
            $client = new ShopifyClient(self::connectionFor($article->site->shopifyConnection));

            $article->update(['wp_sync_status' => 'syncing']);

            $data = $operation($client);
        } catch (ShopifyApiException $e) {
            return $this->fail($article, $action, self::friendlyError($e), $e->statusCode, $e->body);
        } catch (Throwable $e) {
            Log::warning('shopify.article_failed', ['article_id' => $article->id, 'action' => $action, 'error' => $e->getMessage()]);

            return $this->fail($article, $action, self::friendlyError($e));
        }

        $onSuccess($data, $client);

        $result = ['ok' => true, 'status_code' => 200, 'body' => $data];
        $this->log($article, $action, $result);

        return $result;
    }

    private function markSynced(Article $article, array $remote, ShopifyClient $client, bool $published, bool $publishing): void
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

    private function shouldBePublished(Article $article): bool
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

    private function fail(Article $article, string $action, string $message, int $status = 0, mixed $body = null): array
    {
        $result = ['ok' => false, 'message' => $message, 'status_code' => $status, 'body' => is_array($body) ? $body : null];

        $this->log($article, $action, $result);
        $article->update(['wp_sync_status' => 'failed', 'publish_error' => $message]);

        return $result;
    }

    private function log(Article $article, string $action, array $result): void
    {
        ArticlePublishLog::create([
            'article_id' => $article->id,
            'site_id' => $article->website_id,
            'action' => $action,
            'status' => $result['ok'] ? ArticlePublishLog::STATUS_SUCCESS : ArticlePublishLog::STATUS_FAILED,
            'wp_post_id' => null, // numeric column; the Shopify GID lives on the article
            'response' => is_array($result['body'] ?? null) ? $result['body'] : null,
            'error_message' => $result['ok'] ? null : ($result['message'] ?? null),
        ]);
    }
}
