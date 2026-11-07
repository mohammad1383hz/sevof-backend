<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ArticlePublisher;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ArticlePublisherResolver;
use App\Services\Shopify\ShopifyPublishService;
use Illuminate\Http\JsonResponse;

class ArticleWordPressController extends Controller
{
    public function __construct(
        private readonly ArticlePublisherResolver $resolver
    ) {
    }

    /**
     * ارسال یا به‌روزرسانی مقاله در مقصد انتشار سایت (Shopify or WordPress).
     *
     * توجه: این متد الزاماً مقاله را «منتشر» نمی‌کند؛ بسته به تنظیم Agent
     * ممکن است مقاله به‌صورت Draft هم ارسال شود.
     */
    public function syncToWordPress(Article $article): JsonResponse
    {
        $publisher = $this->resolver->for($article);
        $target    = $this->targetName($publisher);

        $isUpdate = $this->isPublished($article, $publisher);

        $result = $isUpdate
            ? $publisher->update($article)
            : $publisher->create($article);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'] ?? "Failed to send the article to {$target}.",
            ], 422);
        }

        return response()->json([
            'message' => $isUpdate
                ? "Article updated on {$target}."
                : "Article sent to {$target}.",
        ]);
    }

    public function destroyOnWordPress(Article $article): JsonResponse
    {
        $publisher = $this->resolver->for($article);
        $target    = $this->targetName($publisher);

        $result = $publisher->delete($article);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'] ?? "Failed to remove the article from {$target}.",
            ], 422);
        }

        return response()->json([
            'message' => "Article removed from {$target}.",
        ]);
    }

    public function syncStatus(Article $article): JsonResponse
    {
        $publisher = $this->resolver->for($article);
        $target    = $this->targetName($publisher);

        $result = $publisher->syncStatus($article);

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'] ?? "Failed to sync the article status with {$target}.",
            ], 422);
        }

        return response()->json([
            'message' => "Article status synced with {$target}.",
        ]);
    }

    // ---------- Helpers ----------

    private function targetName(ArticlePublisher $publisher): string
    {
        return $publisher instanceof ShopifyPublishService ? 'Shopify' : 'WordPress';
    }

    /**
     * مقاله قبلاً در مقصد فعلی ساخته شده؟ (Shopify: shopify_article_id,
     * برای وردپرس با wp_post_id)
     */
    private function isPublished(Article $article, ArticlePublisher $publisher): bool
    {
        return $publisher instanceof ShopifyPublishService
            ? (bool) $article->shopify_article_id
            : (bool) $article->wp_post_id;
    }
}
