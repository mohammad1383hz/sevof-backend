<?php

namespace App\Services\Wp;

use App\Contracts\ProductArticlePublisher;
use App\Models\ProductArticle;

/**
 * Adapter روی ProductArticlePublishService (وردپرس).
 * هیچ منطقی از سرویس وردپرس تغییر نمی‌کند؛ فقط صدا زده می‌شود.
 */
class WordPressProductArticlePublisher implements ProductArticlePublisher
{
    public function __construct(private readonly ProductArticlePublishService $inner)
    {
    }

    public function target(): string
    {
        return 'WordPress';
    }

    public function hasRemoteCopy(ProductArticle $article): bool
    {
        return (bool) $article->wp_post_id;
    }

    public function createProductArticle(ProductArticle $article): array
    {
        return $this->inner->createProductArticle($article);
    }

    public function updateProductArticle(ProductArticle $article): array
    {
        return $this->inner->updateProductArticle($article);
    }

    public function deleteProductArticle(ProductArticle $article): array
    {
        return $this->inner->deleteProductArticle($article);
    }

    public function syncProductArticleStatus(ProductArticle $article): array
    {
        return $this->inner->syncProductArticleStatus($article);
    }
}
