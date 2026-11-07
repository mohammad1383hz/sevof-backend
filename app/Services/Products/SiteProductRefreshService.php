<?php

namespace App\Services\Products;

use App\Models\Site;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * آپدیت روزانه‌ی محصولات. قواعد:
 *  - هیچ محصولی هرگز حذف نمی‌شود (ممکن است برایش محتوا ساخته شده باشد)؛ ناموجود فقط stock_status=outofstock می‌شود.
 *  - محصول جدید اضافه می‌شود؛ قیمت و موجودی محصولات قبلی آپدیت می‌شود.
 *  - فیلدهای دیگر (نام، توضیحات، دسته، تصاویر) برای محصولات موجود دست نمی‌خورد.
 *  - فقط ردیف‌هایی که واقعاً تغییر کرده‌اند نوشته می‌شوند.
 *  - اگر پاسخ سایت مطمئن نبود (خطا، timeout، صفحه‌ی ناقص) هیچ تغییری روی آن محصول اعمال نمی‌شود.
 */
class SiteProductRefreshService extends SiteProductService
{
    protected const MAX_NEW_PAGES = 3;          // اگر صفحه‌ی اول کاملاً جدید بود تا ۳ صفحه (۳۰۰ محصول) جلو می‌رود
    protected const MAX_NEW_PER_RUN = 300;
    protected const MAX_TRACKED = 1000;         // سقف محصولات ووکامرسی که قیمت/موجودی‌شان در هر اجرا چک می‌شود
    protected const MAX_CRAWL_TRACKED = 300;
    protected const MAX_CRAWL_NEW = 100;        // سقف URL جدیدِ بررسی‌شده در هر اجرا برای سایت‌های crawl
    protected const NON_PRODUCT_TTL_DAYS = 14;
    protected const NON_PRODUCT_CACHE_MAX = 5000;

    public function refresh(Site $site): array
    {
        $this->lastmods = [];

        $t = microtime(true);

        // هنوز هیچ‌وقت sync نشده → sync کامل اولیه
        if (! $site->products_synced_at) {
            return ['mode' => 'initial_full_sync'] + parent::sync($site);
        }

        if ($site->is_woocommerce) {
            [, $productsFirst, $categoriesFirst] = $this->probe($site);

            // خطای موقت سایت نباید چیزی را تغییر دهد
            if (! $this->isList($productsFirst)) {
                Log::warning('[SiteProductRefresh] Store API در دسترس نبود، رد شد', ['site_id' => $site->id]);

                return ['mode' => 'skipped', 'reason' => 'store_api_unavailable'];
            }

            $stats = ['mode' => 'woocommerce'] + $this->refreshWoocommerce($site, $productsFirst, $categoriesFirst);
        } elseif ($site->is_shop) {
            $stats = ['mode' => 'crawl'] + $this->refreshCrawled($site);
        } else {
            return ['mode' => 'skipped', 'reason' => 'not_a_shop'];
        }

        $site->update(['products_synced_at' => now()]);
        $this->applySiteCurrency($site);

        $stats['seconds'] = round(microtime(true) - $t, 1);

        Log::info('[SiteProductRefresh] آپدیت روزانه انجام شد', ['site_id' => $site->id] + $stats);

        return $stats;
    }

    // =====================================================================
    //  ووکامرس
    // =====================================================================

    protected function refreshWoocommerce(Site $site, Response $productsFirst, ?Response $categoriesFirst): array
    {
        $base = rtrim($site->url, '/').'/wp-json/wc/store/v1/products';
        $listQuery = ['stock_status' => ['instock'], 'orderby' => 'date', 'order' => 'desc', 'per_page' => self::PER_PAGE];

        $known = SiteProduct::where('site_id', $site->id)
            ->where('source', 'woocommerce')
            ->toBase()
            ->get(['id', 'slug', 'external_id', 'price', 'regular_price', 'sale_price', 'stock_status'])
            ->keyBy('external_id');

        // ۱) جدیدترین‌های موجود: محصول جدید + قیمت تازه‌ی محصولات اخیر (بدون درخواست اضافه)
        $listItems = [];
        $page = 1;
        $response = $productsFirst;

        while (true) {
            $items = $response->json();
            $unknown = 0;

            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                $id = (string) $item['id'];
                $listItems[$id] = $item;

                if (! $known->has($id)) {
                    $unknown++;
                }
            }

            $totalPages = (int) $response->header('X-WP-TotalPages');

            // فقط وقتی کل صفحه جدید بود احتمال دارد صفحه‌ی بعد هم جدید داشته باشد
            if ($items === [] || $unknown < count($items) || $page >= min($totalPages, self::MAX_NEW_PAGES)) {
                break;
            }

            $page++;
            $response = $this->fetchPage($base, $listQuery, $page);

            if (! $this->isList($response)) {
                break;
            }
        }

        // ۲) بقیه‌ی محصولات ذخیره‌شده: فقط با include و هم‌زمان (۱۰۰ تا در هر درخواست)
        $refreshIds = $known
            ->filter(fn ($r) => ! isset($listItems[$r->external_id]))
            ->sortByDesc('id')
            ->take(self::MAX_TRACKED)
            ->keys()
            ->map(fn ($k) => (string) $k)
            ->values()
            ->all();

        $includeItems = [];
        $missing = [];
        $failedIds = 0;

        foreach (array_chunk(array_chunk($refreshIds, self::PER_PAGE), self::POOL_SIZE, true) as $batch) {
            $requests = [];

            foreach ($batch as $i => $ids) {
                $requests[$i] = [
                    'url' => $base,
                    'query' => ['include' => $ids, 'per_page' => self::PER_PAGE],
                    'timeout' => 30,
                    'json' => true,
                ];
            }

            $responses = $this->poolGet($requests);

            foreach ($batch as $i => $ids) {
                $r = $responses[$i] ?? null;

                if (! $this->isList($r)) {
                    $r = $this->fetchPage($base, ['include' => $ids, 'per_page' => self::PER_PAGE], 1);
                }

                if (! $this->isList($r)) {
                    $failedIds += count($ids);

                    continue;
                }

                $returned = [];

                foreach ($r->json() as $item) {
                    if (is_array($item) && isset($item['id'])) {
                        $includeItems[(string) $item['id']] = $item;
                        $returned[(string) $item['id']] = true;
                    }
                }

                // «پیدا نشد» فقط وقتی معتبر است که جواب کامل بوده (یک صفحه)
                if ((int) $r->header('X-WP-TotalPages') <= 1) {
                    foreach ($ids as $id) {
                        if (! isset($returned[$id])) {
                            $missing[] = $id;
                        }
                    }
                }
            }
        }

        // ۳) محاسبه‌ی تغییرات
        $updates = [];
        $newItems = [];
        $backInStock = 0;
        $wentOut = 0;

        foreach ([$listItems, $includeItems] as $set) {
            foreach ($set as $id => $item) {
                $id = (string) $id;
                $row = $known->get($id);
                $inStock = (bool) ($item['is_in_stock'] ?? true);

                if (! $row) {
                    if ($inStock) {
                        $newItems[] = $item;
                    }

                    continue;
                }

                $status = $inStock ? 'instock' : 'outofstock';
                $prices = $item['prices'] ?? [];
                $divisor = 10 ** (int) ($prices['currency_minor_unit'] ?? 2);

                $changed = $status !== $row->stock_status
                    || $this->priceChanged($row->price, $this->minorToPrice($prices['price'] ?? null, $divisor))
                    || $this->priceChanged($row->regular_price, $this->minorToPrice($prices['regular_price'] ?? null, $divisor))
                    || $this->priceChanged($row->sale_price, $this->minorToPrice($prices['sale_price'] ?? null, $divisor));

                if (! $changed) {
                    continue;
                }

                if ($row->stock_status === 'outofstock' && $inStock) {
                    $backInStock++;
                }

                if ($row->stock_status !== 'outofstock' && ! $inStock) {
                    $wentOut++;
                }

                $mapped = $this->mapWoocommerceItems($site, [$item], [], true)[0] ?? null;

                if ($mapped) {
                    $mapped['slug'] = $row->slug; // اگر slug در سایت عوض شده، ردیف تکراری نساز
                    $updates[] = $mapped;
                }
            }
        }

        // فقط ستون‌های قیمت/موجودی؛ محتوای ساخته‌شده دست نمی‌خورد
        $updateColumns = ['price', 'regular_price', 'sale_price', 'currency', 'stock_status', 'updated_at'];

        foreach (array_chunk($updates, self::UPSERT_CHUNK) as $chunk) {
            SiteProduct::upsert($chunk, ['site_id', 'slug'], $updateColumns);
        }

        // محصولی که دیگر در سایت نیست/منتشر نیست: حذف نمی‌شود، فقط ناموجود
        $toMarkOut = array_values(array_filter(
            $missing,
            fn ($id) => ($known->get($id)->stock_status ?? null) !== 'outofstock'
        ));

        foreach (array_chunk($toMarkOut, 500) as $chunk) {
            SiteProduct::where('site_id', $site->id)
                ->where('source', 'woocommerce')
                ->whereIn('external_id', $chunk)
                ->update(['stock_status' => 'outofstock', 'updated_at' => now()]);
        }

        // ۴) محصولات جدید (و دسته‌ی جدید فقط در صورت نیاز)
        $added = 0;

        if ($newItems !== []) {
            $categoryMap = SiteProductCategory::where('site_id', $site->id)->pluck('id', 'external_id')->all();

            $needsCategories = false;

            foreach ($newItems as $item) {
                foreach ($item['categories'] ?? [] as $c) {
                    if (! isset($categoryMap[(string) ($c['id'] ?? '')])) {
                        $needsCategories = true;
                        break 2;
                    }
                }
            }

            if ($needsCategories) {
                $this->syncWoocommerceCategories($site, $this->isList($categoriesFirst) ? $categoriesFirst : null);
                $categoryMap = SiteProductCategory::where('site_id', $site->id)->pluck('id', 'external_id')->all();
            }

            $added = $this->upsertProducts(
                $this->mapWoocommerceItems($site, $newItems, $categoryMap),
                self::MAX_NEW_PER_RUN
            );
        }

        return [
            'added' => $added,
            'updated' => count($updates),
            'went_out_of_stock' => $wentOut + count($toMarkOut),
            'back_in_stock' => $backInStock,
            'unchecked' => $failedIds,
        ];
    }

    // =====================================================================
    //  سایت‌های غیر ووکامرس (crawl)
    // =====================================================================

    protected function refreshCrawled(Site $site): array
    {
        $root = rtrim($site->url, '/');
        $cacheKey = "site-products:non-product:{$site->id}";
        $nonProduct = (array) Cache::get($cacheKey, []); // md5(url) => 1 ؛ صفحاتی که محصول نیستند، هر روز دوباره دانلود نشوند

        $known = SiteProduct::where('site_id', $site->id)
            ->where('source', 'crawled')
            ->toBase()
            ->get(['id', 'slug', 'url', 'price', 'currency', 'stock_status'])
            ->keyBy('slug');

        [$urls] = $this->collectCandidateUrls($site); // سایت‌مپ‌ها، جدیدترین اول

        $targets = [];

        foreach ($known->sortByDesc('id')->take(self::MAX_CRAWL_TRACKED) as $row) {
            if ($row->url) {
                $targets[] = ['url' => $row->url, 'row' => $row];
            }
        }

        $newCount = 0;

        foreach ($urls as $u) {
            $h = md5($u);

            if ($known->has($h) || isset($nonProduct[$h])) {
                continue;
            }

            $targets[] = ['url' => $u, 'row' => null];

            if (++$newCount >= self::MAX_CRAWL_NEW) {
                break;
            }
        }

        $stats = ['added' => 0, 'updated' => 0, 'went_out_of_stock' => 0, 'back_in_stock' => 0, 'unchecked' => 0];
        $categoryCache = [];
        $pathCache = [];

        foreach (array_chunk($targets, self::CRAWL_CONCURRENCY) as $chunk) {
            $requests = [];

            foreach ($chunk as $i => $t) {
                $requests[$i] = ['url' => $t['url'], 'timeout' => 15];
            }

            $responses = $this->poolGet($requests);
            $rows = [];
            $now = now();

            foreach ($chunk as $i => $t) {
                $url = $t['url'];
                $row = $t['row'];
                $res = $responses[$i] ?? null;

                if (! $res instanceof Response) {
                    $stats['unchecked']++;

                    continue;
                }

                if (! $res->successful()) {
                    if (in_array($res->status(), [404, 410], true)) {
                        if ($row) {
                            if ($row->stock_status !== 'outofstock') {
                                SiteProduct::where('id', $row->id)->update(['stock_status' => 'outofstock']);
                                $stats['went_out_of_stock']++;
                            }
                        } else {
                            $nonProduct[md5($url)] = 1;
                        }
                    } else {
                        $stats['unchecked']++;
                    }

                    continue;
                }

                [$product, $breadcrumb] = $this->parseJsonLd($res->body(), $url);
                $name = $this->str($product['name'] ?? null);

                if ($product === null || $name === null) {
                    if ($row) {
                        $stats['unchecked']++;
                    } else {
                        $nonProduct[md5($url)] = 1;
                    }

                    continue;
                }

                $offers = $product['offers'] ?? [];
                $offers = is_array($offers) ? ($offers[0] ?? $offers) : [];
                $offers = is_array($offers) ? $offers : [];
                $availability = $this->str($offers['availability'] ?? null) ?? '';

                $isOut = (bool) preg_match('/OutOfStock|SoldOut|Discontinued/i', $availability);
                $isIn = stripos($availability, 'InStock') !== false;
                $price = $this->normalizePrice($offers['price'] ?? null) ?? $this->normalizePrice($offers['lowPrice'] ?? null);
                $currency = $this->str($offers['priceCurrency'] ?? null);

                // محصول قبلی: فقط قیمت/موجودی
                if ($row) {
                    $status = $isOut ? 'outofstock' : ($isIn ? 'instock' : $row->stock_status);
                    $changes = [];

                    if ($status !== $row->stock_status) {
                        $changes['stock_status'] = $status;
                    }

                    if ($price !== null && $this->priceChanged($row->price, $price)) {
                        $changes['price'] = $price;
                    }

                    if ($currency !== null && $currency !== $row->currency) {
                        $changes['currency'] = $currency;
                    }

                    if ($changes !== []) {
                        SiteProduct::where('id', $row->id)->update($changes);
                        $stats['updated']++;

                        if (($changes['stock_status'] ?? null) === 'outofstock') {
                            $stats['went_out_of_stock']++;
                        }

                        if (($changes['stock_status'] ?? null) === 'instock' && $row->stock_status === 'outofstock') {
                            $stats['back_in_stock']++;
                        }
                    }

                    continue;
                }

                // محصول جدید: ناموجود اضافه نمی‌شود
                if ($isOut) {
                    $nonProduct[md5($url)] = 1;

                    continue;
                }

                $categoryChain = $this->categoryNamesFromBreadcrumb($breadcrumb, $name, $url, $root);
                $chainKey = implode('>', $categoryChain);

                if (! array_key_exists($chainKey, $categoryCache)) {
                    $categoryCache[$chainKey] = $this->ensureCategoryChain($site, $categoryChain, $pathCache);
                }

                $categoryId = $categoryCache[$chainKey];

                $rows[] = [
                    'site_id' => $site->id,
                    'slug' => md5($url),
                    'category_id' => $categoryId,
                    'category_ids' => json_encode($categoryId ? [$categoryId] : []),
                    'source' => 'crawled',
                    'external_id' => $this->str($product['sku'] ?? null) ?? $this->str($product['mpn'] ?? null),
                    'name' => $name,
                    'url' => $url,
                    'sku' => $this->str($product['sku'] ?? null),
                    'description' => $this->str($product['description'] ?? null),
                    'price' => $price,
                    'currency' => $currency,
                    'images' => json_encode($this->normalizeImages($product['image'] ?? null), self::JSON_FLAGS),
                    'stock_status' => $isIn ? 'instock' : null,
                    'raw_data' => json_encode($product, self::JSON_FLAGS),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $stats['added'] += $this->upsertProducts($rows, self::MAX_CRAWL_NEW - $stats['added']);
        }

        Cache::put(
            $cacheKey,
            array_slice($nonProduct, -self::NON_PRODUCT_CACHE_MAX, null, true),
            now()->addDays(self::NON_PRODUCT_TTL_DAYS)
        );

        return $stats;
    }

    // ---------- کمکی ----------

    protected function priceChanged(mixed $old, ?float $new): bool
    {
        $old = is_numeric($old) ? (float) $old : null;

        if ($old === null || $new === null) {
            return $old !== $new;
        }

        return abs($old - $new) > 0.005;
    }
}
