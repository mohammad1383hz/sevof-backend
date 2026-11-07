<?php

namespace App\Services\Shopify;

use App\Models\ShopifyConnection;
use App\Models\Site;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;

/**
 * Pulls active products (newest first, up to config('shopify.max_products')) and their
 * collections into site_products / site_product_categories with source = "shopify".
 *
 * raw_data is stored in the shape PageProductGapDetectionService already understands:
 * tags[{name}], images[{id, src, alt}] (id = numeric MediaImage id), brands[{name: vendor}].
 */
class ShopifyProductSync
{
    private const PAGE_SIZE = 50;

    /** @return array{0: int, 1: bool} [products synced, catalog fully read] */
    public function sync(Site $site, ShopifyConnection $connection): array
    {
        $client = new ShopifyClient($connection);
        $storefront = $connection->storefrontUrl();
        $max = (int) config('shopify.max_products', 250);

        $count = 0;
        $after = null;
        $complete = false;

        do {
            $page = $client->products(min(self::PAGE_SIZE, $max - $count), $after);

            foreach ((array) ($page['nodes'] ?? []) as $node) {
                $this->upsertProduct($site, $node, $storefront);
                $count++;
            }

            $hasNext = (bool) data_get($page, 'pageInfo.hasNextPage');
            $after = data_get($page, 'pageInfo.endCursor');
            $complete = ! $hasNext;
        } while ($hasNext && $count < $max);

        return [$count, $complete];
    }

    private function upsertProduct(Site $site, array $node, string $storefront): void
    {
        $collections = (array) data_get($node, 'collections.nodes', []);
        $categoryIds = array_values(array_filter(array_map(fn ($c) => $this->upsertCollection($site, $c, $storefront), $collections)));

        $price = data_get($node, 'priceRangeV2.minVariantPrice.amount');
        $compareAt = data_get($node, 'compareAtPriceRange.maxVariantCompareAtPrice.amount');
        $hasCompare = is_numeric($compareAt) && is_numeric($price) && (float) $compareAt > (float) $price;

        $images = [];

        foreach ((array) data_get($node, 'media.nodes', []) as $media) {
            $src = data_get($media, 'image.url');

            if ($src && preg_match('#/(\d+)$#', (string) ($media['id'] ?? ''), $m)) {
                $images[] = ['id' => (int) $m[1], 'src' => $src, 'alt' => (string) ($media['alt'] ?? '')];
            }
        }

        $outOfStock = ($node['tracksInventory'] ?? false) && (int) ($node['totalInventory'] ?? 0) <= 0;

        $product = SiteProduct::updateOrCreate(
            ['site_id' => $site->id, 'slug' => (string) $node['handle']],
            [
                'source' => 'shopify',
                'external_id' => (string) $node['id'],
                'name' => (string) $node['title'],
                'url' => $node['onlineStoreUrl'] ?: $storefront.'/products/'.$node['handle'],
                'sku' => data_get($node, 'variants.nodes.0.sku') ?: null,
                'description' => (string) ($node['descriptionHtml'] ?? ''),
                'short_description' => null,
                'price' => is_numeric($price) ? (float) $price : null,
                'regular_price' => $hasCompare ? (float) $compareAt : (is_numeric($price) ? (float) $price : null),
                'sale_price' => $hasCompare ? (float) $price : null,
                'currency' => data_get($node, 'priceRangeV2.minVariantPrice.currencyCode'),
                'images' => array_column($images, 'src'),
                'stock_status' => $outOfStock ? 'outofstock' : 'instock',
                'category_id' => $categoryIds[0] ?? null,
                'category_ids' => $categoryIds,
                'is_manual' => false,
                'raw_data' => [
                    'tags' => array_map(fn ($t) => ['name' => (string) $t], (array) ($node['tags'] ?? [])),
                    'images' => $images,
                    'brands' => ! empty($node['vendor']) ? [['name' => $node['vendor']]] : [],
                    'product_type' => $node['productType'] ?? null,
                    'seo' => $node['seo'] ?? null,
                    'attributes' => [],
                ],
            ]
        );

        // SiteProductService prunes rows not touched during a complete sync.
        if (! $product->wasRecentlyCreated && ! $product->wasChanged()) {
            $product->touch();
        }
    }

    private function upsertCollection(Site $site, array $collection, string $storefront): ?int
    {
        if (empty($collection['handle'])) {
            return null;
        }

        return SiteProductCategory::updateOrCreate(
            ['site_id' => $site->id, 'slug' => (string) $collection['handle']],
            [
                'source' => 'shopify',
                'external_id' => (string) ($collection['id'] ?? ''),
                'name' => (string) ($collection['title'] ?? $collection['handle']),
                'url' => $storefront.'/collections/'.$collection['handle'],
            ]
        )->id;
    }
}
