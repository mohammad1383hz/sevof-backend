<?php

namespace App\Services\Products\PageProduct;

use App\Models\Site;
use App\Models\SiteProduct;

/**
 * Publishing platform for product-page SEO (same rule as ArticlePublisherResolver):
 * a connected Shopify store → Shopify, otherwise WordPress/WooCommerce.
 */
class PageProductSeoPublisherResolver
{
    public const SHOPIFY = 'shopify';
    public const WORDPRESS = 'wordpress';

    public function platform(Site $site): string
    {
        return $site->shopifyConnection?->isConnected() ? self::SHOPIFY : self::WORDPRESS;
    }

    public function isShopify(Site $site): bool
    {
        return $this->platform($site) === self::SHOPIFY;
    }

    /**
     * null = publishing is possible; otherwise a message for the user.
     * Checked before any status change or job dispatch.
     */
    public function unavailableReason(Site $site, ?SiteProduct $product): ?string
    {
        if ($this->isShopify($site)) {
            return ! $product || ! $product->external_id || $product->source !== 'shopify'
                ? 'Only products synced from your Shopify store can be edited.'
                : null;
        }

        if (! $site->connection) {
            return 'This site is not connected yet. Connect WordPress or Shopify from Automation first.';
        }

        if (! $product || ! $product->external_id || $product->source === 'crawled') {
            return 'Only products synced from WooCommerce can be edited.';
        }

        return null;
    }

    public function label(Site $site): string
    {
        return $this->isShopify($site) ? 'Shopify' : 'WooCommerce';
    }
}
