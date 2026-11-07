<?php

namespace App\Services\Products;

use Illuminate\Support\Facades\DB;

/**
 * Loads site products for article builders (e.g. ShopifyProductArticleBuilder).
 *
 * Contract used by the builder:
 *  - ids(mixed $raw): int[]                         -> normalized, unique, positive IDs
 *  - load(int $siteId, array $ids): array<int,array> -> products keyed by ID, each with an 'id' key
 */
class ProductCatalog
{
    /**
     * Normalize a product_ids value (array, JSON string, "1,2,3", or null) into a unique int list.
     *
     * @return int[]
     */
    public function ids(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\s,،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($raw)) {
            $raw = [$raw];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_array($value)) {
                $value = $value['id'] ?? null;
            }

            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Load products of a site by ID. Missing products are simply absent from the result,
     * which lets the builder detect "unresolved" sections.
     *
     * @param  int[]  $ids
     * @return array<int, array<string, mixed>>
     */
    public function load(int $siteId, array $ids): array
    {
        $ids = $this->ids($ids);

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('site_products')
            ->where('site_id', $siteId)
            ->whereIn('id', $ids)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->keyBy(fn (array $row) => (int) $row['id']);

        // Keep the order in which IDs were requested.
        $products = [];
        foreach ($ids as $id) {
            if (isset($rows[$id])) {
                $products[$id] = $rows[$id];
            }
        }

        return $products;
    }
}
