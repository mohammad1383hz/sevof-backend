<?php

namespace App\Services;

use App\Contracts\ProductArticlePublisher;
use App\Models\ProductArticle;
use App\Services\Shopify\ShopifyProductArticlePublishService;
use App\Services\Wp\WordPressProductArticlePublisher;

class ProductArticlePublisherResolver
{
    /** Same rule as ArticlePublisherResolver. Services are built lazily on purpose. */
    public function for(ProductArticle $article): ProductArticlePublisher
    {
        return $article->site->shopifyConnection?->isConnected()
            ? app(ShopifyProductArticlePublishService::class)
            : app(WordPressProductArticlePublisher::class);
    }
}
