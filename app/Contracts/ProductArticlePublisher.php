<?php

namespace App\Contracts;

use App\Models\ProductArticle;

interface ProductArticlePublisher
{
    /** Target name for messages: "Shopify" or "WordPress" */
    public function target(): string;

    /** Already created on this target? (Shopify: shopify_article_id | WP: wp_post_id) */
    public function hasRemoteCopy(ProductArticle $article): bool;

    public function createProductArticle(ProductArticle $article): array;

    public function updateProductArticle(ProductArticle $article): array;

    public function deleteProductArticle(ProductArticle $article): array;

    public function syncProductArticleStatus(ProductArticle $article): array;
}
