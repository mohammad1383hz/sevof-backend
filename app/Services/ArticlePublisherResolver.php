<?php

namespace App\Services;

use App\Contracts\ArticlePublisher;
use App\Models\Article;
use App\Services\Shopify\ShopifyPublishService;
use App\Services\Wp\ArticlePublishService;

/**
 * Publishing target per site: a connected Shopify store wins, otherwise the WordPress plugin.
 * Keep in sync with ProductArticlePublisherResolver and PageProductSeoPublisherResolver.
 */
class ArticlePublisherResolver
{
    public function for(Article $article): ArticlePublisher
    {
        return $article->site->shopifyConnection?->isConnected()
            ? app(ShopifyPublishService::class)
            : app(ArticlePublishService::class);
    }
}
