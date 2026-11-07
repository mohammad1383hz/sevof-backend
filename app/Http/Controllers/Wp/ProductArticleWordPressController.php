<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\ProductArticle;
use App\Models\Site;
use App\Services\ProductArticlePublisherResolver;
use Illuminate\Http\JsonResponse;

/**
 * انتشار مقاله‌ی محصول در مقصد سایت (WordPress or Shopify).
 * مسیرها و پیام‌ها برای وردپرس دقیقاً مثل قبل است.
 */
class ProductArticleWordPressController extends Controller
{
    public function __construct(
        private readonly ProductArticlePublisherResolver $resolver
    ) {
    }

    public function syncToWordPress(Site $site, ProductArticle $article): JsonResponse
    {
        abort_unless($article->site_id === $site->id, 404);

        $publisher = $this->resolver->for($article);
        $target = $publisher->target();

        $isUpdate = $publisher->hasRemoteCopy($article);

        $result = $isUpdate
            ? $publisher->updateProductArticle($article)
            : $publisher->createProductArticle($article);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message'] ?? "Failed to send the article to {$target}."], 422);
        }

        return response()->json([
            'message' => $isUpdate
                ? "Product article updated on {$target}."
                : "Product article sent to {$target}.",
        ]);
    }

    public function destroyOnWordPress(Site $site, ProductArticle $article): JsonResponse
    {
        abort_unless($article->site_id === $site->id, 404);

        $publisher = $this->resolver->for($article);
        $target = $publisher->target();

        $result = $publisher->deleteProductArticle($article);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message'] ?? "Failed to remove the article from {$target}."], 422);
        }

        return response()->json(['message' => "Product article removed from {$target}."]);
    }

    public function syncStatus(Site $site, ProductArticle $article): JsonResponse
    {
        abort_unless($article->site_id === $site->id, 404);

        $publisher = $this->resolver->for($article);
        $target = $publisher->target();

        $result = $publisher->syncProductArticleStatus($article);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message'] ?? "Failed to sync the article status with {$target}."], 422);
        }

        return response()->json(['message' => "Product article status synced with {$target}."]);
    }
}
