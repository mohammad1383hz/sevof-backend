<?php

namespace App\Services\Products;

use App\Models\Site;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SiteProductService
{
    protected const MAX_PAGES = 100;          // سقف ایمنی (۱۰۰ صفحه × ۱۰۰ = ۱۰,۰۰۰ محصول)
    protected const PER_PAGE = 100;           // حداکثر مجاز Store API
    protected const POOL_SIZE = 8;            // تعداد صفحه‌ی همزمان
    protected const MAX_CRAWL_URLS = 1500;
    protected const CRAWL_CONCURRENCY = 25;
    protected const SITEMAP_CONCURRENCY = 10;
    protected const UPSERT_CHUNK = 100;

    protected const MAX_PRODUCTS = 100;        // ووکامرس: ۱۰۰ محصول موجودِ اخیر
    protected const MAX_CRAWL_PRODUCTS = 50;   // crawl: ۵۰ محصول اخیر

    protected const CONNECT_TIMEOUT = 8;       // ثانیه؛ بعد از آن ConnectionException

    protected const USER_AGENT = 'Mozilla/5.0 (compatible; SiteProductSync/1.0)';
    protected const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    protected array $lastmods = []; // url => timestamp (از lastmod سایت‌مپ)

    public function sync(Site $site): array
    {
        // سرویس ممکن است بین جاب‌ها زنده بماند؛ وضعیت هر sync از نو
        $this->lastmods = [];

        // بدون میکروثانیه تا ردیف‌های همین sync اشتباهاً «قدیمی» حساب نشوند
        $startedAt = now()->startOfSecond();

        // Shopify store connected via OAuth → read the catalog from the Admin API (no crawling).
        $shopify = $site->shopifyConnection;

        if ($shopify?->isConnected()) {
            [$productsCount, $complete] = app(\App\Services\Shopify\ShopifyProductSync::class)->sync($site, $shopify);
            $isWordpress = $isWoocommerce = false;
            $site->update(['is_wordpress' => false, 'is_woocommerce' => false]);
        } else {
            // تشخیص وردپرس + صفحه‌ی اول محصولات + صفحه‌ی اول دسته‌ها، همه هم‌زمان
            [$isWordpress, $productsFirst, $categoriesFirst] = $this->probe($site);

            // Store API خودش مدرک کافی است؛ به /wp-json/ ریشه وابسته نیست
            $isWoocommerce = $this->isList($productsFirst) || $this->detectWoocommerce($site);
            $isWordpress = $isWordpress || $isWoocommerce;

            // سایتی که قبلاً ووکامرسی ثبت شده، با یک خطای موقت نباید به crawl برود
            if (! $isWoocommerce && $site->is_woocommerce) {
                throw new \RuntimeException('The WooCommerce Store API is unavailable; this is a WooCommerce site, so it will not be crawled.');
            }

            $site->update([
                'is_wordpress' => $isWordpress,
                'is_woocommerce' => $isWoocommerce,
            ]);

            $productsCount = 0;
            $complete = false;

            if ($isWoocommerce) {
                $this->syncWoocommerceCategories($site, $this->isList($categoriesFirst) ? $categoriesFirst : null);
                [$productsCount, $complete] = $this->syncWoocommerceProducts(
                    $site,
                    $this->isList($productsFirst) ? $productsFirst : null
                );

                // ووکامرسی است ولی چیزی گرفته نشد: ردیف crawled نساز، job دوباره تلاش کند
                if ($productsCount === 0 && ! $complete) {
                    throw new \RuntimeException('This is a WooCommerce site but products couldn\'t be read from the Store API; it won\'t be crawled.');
                }
            } else {
                // سایت ووکامرسی نیست → crawl
                [, $productsCount, $complete] = $this->crawlProductsAndCategories($site);
            }
        }

        // is_shop را AI در AnalyzeSiteJob تعیین می‌کند؛ اینجا فقط زمان sync ثبت می‌شود.
        $site->update(['products_synced_at' => now()]);

        $isShop = (bool) $site->is_shop;

        // محصولاتی که دستی با لینک اضافه شده‌اند، اگر همان محصول در sync هم آمده باشد، نسخه‌ی sync معتبر است
        $this->removeManualDuplicates($site);

        $this->applySiteCurrency($site);

        // حذف محصولات ناموجود + (فقط اگر داده کامل و مطمئن بود) محصولاتی که دیگر در سقف اخیر نیستند
        // محصولات دستی (is_manual) با قانون «قدیمی» پاک نمی‌شوند
        SiteProduct::where('site_id', $site->id)
            ->where(function ($q) use ($complete, $startedAt) {
                $q->where('stock_status', 'outofstock');

                if ($complete) {
                    $q->orWhere(function ($w) use ($startedAt) {
                        $w->where('updated_at', '<', $startedAt)->where('is_manual', false);
                    });
                }
            })
            ->delete();

        // دسته‌های بدون محصول پاک شوند
        $this->pruneEmptyCategories($site);

        $categoriesCount = SiteProductCategory::where('site_id', $site->id)->count();

        Log::info('[SiteProductService] همگام‌سازی محصولات سایت انجام شد', [
            'site_id' => $site->id,
            'is_wordpress' => $isWordpress,
            'is_woocommerce' => $isWoocommerce,
            'is_shop' => $isShop,
            'categories_count' => $categoriesCount,
            'products_count' => $productsCount,
            'complete' => $complete,
        ]);

        return compact('isWordpress', 'isWoocommerce', 'isShop', 'categoriesCount', 'productsCount', 'complete');
    }

    // =====================================================================
    //  ارتقای ردیف‌های crawled به woocommerce (بدون حذف؛ همان id می‌ماند)
    // =====================================================================

    /**
     * هر محصول crawled را با مسیر آدرس (permalink) به محصول Store API وصل می‌کند و
     * همان ردیف را درجا به source=woocommerce و external_id واقعی تبدیل می‌کند.
     * چون id ردیف عوض نمی‌شود، ارجاع‌های سئو (PageProductSeoOptimization و ...) سالم می‌مانند.
     *
     * @return array{total:int, upgraded:int, outofstock:int, clash:array, unmatched:array, complete:bool}
     */
    public function upgradeCrawledToWoocommerce(Site $site, bool $protect = false, bool $dryRun = false): array
    {

        $stats = ['total' => 0, 'upgraded' => 0, 'outofstock' => 0, 'clash' => [], 'unmatched' => [], 'complete' => false];

        if (! $this->detectWoocommerce($site)) {
            throw new \RuntimeException('The WooCommerce Store API is unavailable; upgrade is not possible.');
        }

        $crawled = SiteProduct::where('site_id', $site->id)
            ->where('source', 'crawled')
            ->toBase()
            ->get(['id', 'url', 'slug']);

        $stats['total'] = $crawled->count();

        if ($crawled->isEmpty()) {
            $stats['complete'] = true;

            return $stats;
        }

        // مسیر آدرس (lowercase) => idهای ردیف‌های crawled
        $byPath = [];

        foreach ($crawled as $c) {
            $path = $c->url ? mb_strtolower($this->pathOf($c->url)) : '';

            if ($path !== '') {
                $byPath[$path][] = (int) $c->id;
            }
        }

        if (! $dryRun) {
            $site->update(['is_wordpress' => true, 'is_woocommerce' => true]);
            $this->syncWoocommerceCategories($site);
        }

        $categoryMap = SiteProductCategory::where('site_id', $site->id)->pluck('id', 'external_id')->all();

        // slug => id صاحب آن؛ برای تشخیص تداخل با unique(site_id, slug)
        $slugOwner = [];

        foreach (SiteProduct::where('site_id', $site->id)->toBase()->get(['id', 'slug']) as $p) {
            $slugOwner[$p->slug] = (int) $p->id;
        }

        $seen = [];

        $apply = function (array $row, array $ids) use ($site, $protect, $dryRun, &$slugOwner, &$seen, &$stats) {
            foreach ($ids as $id) {
                $id = (int) $id;

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $owner = $slugOwner[$row['slug']] ?? null;

                // همین محصول قبلاً با ردیف دیگری (مثلاً از sync) ثبت شده
                if ($owner !== null && $owner !== $id) {
                    $stats['clash'][] = ['id' => $id, 'slug' => $row['slug'], 'existing_id' => $owner];

                    continue;
                }

                if (! $dryRun) {
                    $data = array_diff_key($row, array_flip(['site_id', 'created_at']));

                    if ($protect) {
                        $data['is_manual'] = true; // با قانون «قدیمی» در sync پاک نشود
                    }

                    SiteProduct::where('site_id', $site->id)->whereKey($id)->update($data);
                }

                $slugOwner[$row['slug']] = $id;
                $stats['upgraded']++;
            }
        };

        // پاس ۱: همه‌ی محصولات Store API (بدون فیلتر موجودی) و تطبیق با مسیر آدرس
        $base = rtrim($site->url, '/').'/wp-json/wc/store/v1/products';

        $stats['complete'] = $this->fetchAllPages(
            $base,
            ['orderby' => 'date', 'order' => 'desc'],
            function (array $items) use (&$byPath, &$seen, &$stats, $apply, $site, $categoryMap) {
                foreach ($items as $item) {
                    if (! is_array($item) || ! isset($item['id'])) {
                        continue;
                    }

                    $path = mb_strtolower($this->pathOf((string) ($item['permalink'] ?? '')));

                    if ($path === '' || ! isset($byPath[$path])) {
                        continue;
                    }

                    if (! ($item['is_in_stock'] ?? true)) {
                        // ناموجود؛ sync خودش این‌ها را طبق قانون حذف می‌کند
                        foreach ($byPath[$path] as $id) {
                            if (! isset($seen[$id])) {
                                $seen[$id] = true;
                                $stats['outofstock']++;
                            }
                        }

                        continue;
                    }

                    $rows = $this->mapWoocommerceItems($site, [$item], $categoryMap);

                    if (isset($rows[0])) {
                        $apply($rows[0], $byPath[$path]);
                    }
                }
            }
        );

        // پاس ۲: ردیف‌هایی که در لیست پیدا نشدند، یکی‌یکی با slug آدرس
        foreach ($crawled as $c) {
            $id = (int) $c->id;

            if (isset($seen[$id])) {
                continue;
            }

            [$row, $outOfStock] = $c->url ? $this->fetchWoocommerceRow($site, $c->url) : [null, false];

            if ($outOfStock) {
                $seen[$id] = true;
                $stats['outofstock']++;
            } elseif ($row !== null) {
                $apply($row, [$id]);
            } else {
                $seen[$id] = true;
                $stats['unmatched'][] = ['id' => $id, 'url' => $c->url];
            }
        }

        if (! $dryRun) {
            $this->pruneEmptyCategories($site);
        }

        Log::info('[SiteProductService] ارتقای محصولات crawled به woocommerce', [
            'site_id' => $site->id,
            'dry_run' => $dryRun,
            'protect' => $protect,
            'total' => $stats['total'],
            'upgraded' => $stats['upgraded'],
            'outofstock' => $stats['outofstock'],
            'clash' => count($stats['clash']),
            'unmatched' => count($stats['unmatched']),
        ]);

        return $stats;
    }

    // =====================================================================
    //  HTTP layer
    // =====================================================================

    protected function buildUrl(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * One GET request.
     */
    protected function getOne(string $url, array $query = [], int $timeout = 15, bool $json = false): ?Response
    {
        return $this->poolGet([
            'r' => ['url' => $url, 'query' => $query, 'timeout' => $timeout, 'json' => $json],
        ])['r'] ?? null;
    }

    /**
     * Several concurrent GETs; a failed request (connection error, timeout) yields null.
     *
     * @param  array<int|string, array{url:string, query?:array, timeout?:int, json?:bool}>  $requests
     * @return array<int|string, Response|null>
     */
    protected function poolGet(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($requests) {
                $calls = [];

                foreach ($requests as $key => $r) {
                    $req = $pool->as((string) $key)
                        ->withUserAgent(self::USER_AGENT)
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->timeout($r['timeout'] ?? 15);

                    if (! empty($r['json'])) {
                        $req = $req->acceptJson();
                    }

                    $calls[] = $req->get($r['url'], $r['query'] ?? []);
                }

                return $calls;
            });
        } catch (Throwable $e) {
            // Some Laravel versions abort the whole pool on a connection error.
            $responses = [];
        }

        $results = [];

        foreach ($requests as $key => $r) {
            $res = $responses[(string) $key] ?? null;
            $results[$key] = $res instanceof Response ? $res : null;
        }

        return $results;
    }

    // =====================================================================

    /**
     * سه درخواست هم‌زمان: ایندکس wp-json، صفحه‌ی اول محصولات، صفحه‌ی اول دسته‌ها.
     * صفحه‌ی اول محصولات باید دقیقاً با query نهایی syncWoocommerceProducts یکی باشد (دوباره درخواست نمی‌شود).
     * خروجی: [isWordpress, پاسخ محصولات|null, پاسخ دسته‌ها|null]
     */
    protected function probe(Site $site): array
    {
        $root = rtrim($site->url, '/');

        try {
            $responses = $this->poolGet([
                'wp' => [
                    'url' => $root.'/wp-json/',
                    'query' => ['_fields' => 'name'],
                    'timeout' => 10,
                    'json' => true,
                ],
                'products' => [
                    'url' => $root.'/wp-json/wc/store/v1/products',
                    'query' => [
                        'stock_status' => ['instock'], 'orderby' => 'date', 'order' => 'desc',
                        'per_page' => self::PER_PAGE, 'page' => 1,
                    ],
                    'timeout' => 30,
                    'json' => true,
                ],
                'categories' => [
                    'url' => $root.'/wp-json/wc/store/v1/products/categories',
                    'query' => ['hide_empty' => true, 'per_page' => self::PER_PAGE, 'page' => 1],
                    'timeout' => 30,
                    'json' => true,
                ],
            ]);
        } catch (Throwable $e) {
            return [false, null, null];
        }

        $wp = $responses['wp'] ?? null;
        $isWordpress = false;

        try {
            $isWordpress = $wp instanceof Response && $wp->successful() && is_array($wp->json());
        } catch (Throwable $e) {
            $isWordpress = false;
        }

        $products = $responses['products'] ?? null;
        $categories = $responses['categories'] ?? null;

        return [
            $isWordpress,
            $products instanceof Response ? $products : null,
            $categories instanceof Response ? $categories : null,
        ];
    }

    /**
     * از Store API عمومی ووکامرس استفاده می‌کنیم چون بدون کلید/توکن روی هر سایت ووکامرسی در دسترسه.
     * با retry و fallback به ?rest_route= (برای سایت‌هایی که /wp-json بسته یا خراب است).
     */
    protected function detectWoocommerce(Site $site): bool
    {
        $root = rtrim($site->url, '/');

        $attempts = [
            [$root.'/wp-json/wc/store/v1/products', ['per_page' => 1]],
            [$root.'/', ['rest_route' => '/wc/store/v1/products', 'per_page' => 1]],
        ];

        for ($round = 0; $round < 3; $round++) {
            $inconclusive = false;

            foreach ($attempts as [$url, $query]) {
                try {
                    $response = $this->getOne($url, $query, 25, true);
                } catch (Throwable $e) {
                    $response = null;
                }

                if ($this->isList($response)) {
                    return true;
                }

                // timeout/اتصال/403/429/5xx = نتیجه قطعی نیست؛ 404 یا 200 غیرلیست = ووکامرس نیست
                if (! $response instanceof Response
                    || in_array($response->status(), [403, 429], true)
                    || $response->serverError()) {
                    $inconclusive = true;
                }
            }

            if (! $inconclusive) {
                return false;
            }

            if ($round < 2) {
                usleep(1500000);
            }
        }

        return false;
    }

    protected function isList(mixed $response): bool
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return false;
        }

        $json = $response->json();

        return is_array($json) && array_is_list($json);
    }

    /**
     * فقط خطای اتصال و 5xx دوباره امتحان می‌شود (نه 400 صفحه‌ی خارج از محدوده).
     */
    protected function fetchPage(string $base, array $query, int $page): ?Response
    {
        $r = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $r = $this->getOne($base, $query + ['page' => $page], 30, true);
            } catch (Throwable $e) {
                $r = null;
            }

            if ($r instanceof Response && ! $r->serverError()) {
                return $r;
            }

            if ($attempt < 2) {
                usleep(500000);
            }
        }

        return $r;
    }

    /**
     * همه‌ی صفحات یک endpoint صفحه‌بندی‌شده را می‌گیرد و هر صفحه را همان لحظه به $onPage می‌دهد
     * (برای مصرف کم حافظه). خروجی: آیا همه‌ی صفحات کامل گرفته شد.
     * اگر $first (صفحه‌ی ۱ با همین query) از قبل گرفته شده باشد دوباره درخواست نمی‌شود.
     * اگر $shouldStop بعد از یک صفحه true برگرداند، دریافت متوقف و true برمی‌گردد (مجموعه‌ی کافی و مطمئن).
     */
    protected function fetchAllPages(
        string $base,
        array $query,
        callable $onPage,
        ?Response $first = null,
        ?callable $shouldStop = null
    ): bool {
        $query += ['per_page' => self::PER_PAGE];

        $first ??= $this->fetchPage($base, $query, 1);

        if (! $this->isList($first)) {
            return false;
        }

        $onPage($first->json());

        if ($shouldStop && $shouldStop()) {
            return true;
        }

        $headerPages = $first->header('X-WP-TotalPages');

        // سایت هدر تعداد صفحات را نمی‌فرستد: ترتیبی ادامه بده
        if ($headerPages === '' || $headerPages === null) {
            return $this->fetchSequentially($base, $query, count($first->json()), $onPage, $shouldStop);
        }

        $totalPages = (int) $headerPages;
        $complete = $totalPages <= self::MAX_PAGES;   // بیش از سقف = ناقص
        $totalPages = min($totalPages, self::MAX_PAGES);
        $failed = [];

        for ($start = 2; $start <= $totalPages; $start += self::POOL_SIZE) {
            $pages = range($start, min($start + self::POOL_SIZE - 1, $totalPages));

            $requests = [];

            foreach ($pages as $p) {
                $requests[$p] = [
                    'url' => $base,
                    'query' => $query + ['page' => $p],
                    'timeout' => 30,
                    'json' => true,
                ];
            }

            $responses = $this->poolGet($requests);

            foreach ($pages as $p) {
                $r = $responses[$p] ?? null;

                if ($this->isList($r)) {
                    $onPage($r->json());
                } else {
                    $failed[] = $p;
                }
            }

            if ($shouldStop && $shouldStop()) {
                return true;
            }
        }

        // صفحه‌های ناموفق را یکی‌یکی (با retry) دوباره امتحان کن
        foreach ($failed as $p) {
            $r = $this->fetchPage($base, $query, $p);

            if ($this->isList($r)) {
                $onPage($r->json());
            } else {
                $complete = false;
            }

            if ($shouldStop && $shouldStop()) {
                return true;
            }
        }

        return $complete;
    }

    protected function fetchSequentially(
        string $base,
        array $query,
        int $lastCount,
        callable $onPage,
        ?callable $shouldStop = null
    ): bool {
        for ($page = 2; $page <= self::MAX_PAGES; $page++) {
            if ($lastCount < self::PER_PAGE) {
                return true; // صفحه‌ی قبلی ناقص بود = آخرین صفحه
            }

            $r = $this->fetchPage($base, $query, $page);

            // وردپرس برای صفحه‌ی خارج از محدوده 400 می‌دهد = پایان
            if ($r instanceof Response && $r->status() === 400) {
                return true;
            }

            if (! $this->isList($r)) {
                return false;
            }

            $items = $r->json();

            if ($items === []) {
                return true;
            }

            $onPage($items);

            if ($shouldStop && $shouldStop()) {
                return true;
            }

            $lastCount = count($items);
        }

        return $lastCount < self::PER_PAGE;
    }

    protected function syncWoocommerceCategories(Site $site, ?Response $first = null): int
    {
        $base = rtrim($site->url, '/').'/wp-json/wc/store/v1/products/categories';
        $items = [];

        // hide_empty فقط بهینه‌سازی است؛ پاک‌سازی نهایی در pruneEmptyCategories انجام می‌شود
        $this->fetchAllPages($base, ['hide_empty' => true], function (array $page) use (&$items) {
            array_push($items, ...$page);
        }, $first);

        $now = now();
        $rows = [];
        $parentMap = []; // slug => external id والد

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                continue;
            }

            $slug = $item['slug'] ?? (string) $item['id'];

            $rows[$slug] = [
                'site_id' => $site->id,
                'slug' => $slug,
                'source' => 'woocommerce',
                'external_id' => (string) $item['id'],
                'name' => $this->decode($item['name'] ?? ''),
                'url' => $item['permalink'] ?? null,
                'description' => $item['description'] ?? null,
                'parent_id' => null, // اگر دسته به سطح بالا آمده، والد قبلی پاک شود
                'created_at' => $now,
                'updated_at' => $now,
            ];

            unset($parentMap[$slug]);

            if (! empty($item['parent'])) {
                $parentMap[$slug] = (string) $item['parent'];
            }
        }

        if ($rows === []) {
            return 0;
        }

        // upsert دسته‌ای؛ نیازمند unique index روی (site_id, slug) در جدول دسته‌ها
        $updateColumns = ['source', 'external_id', 'name', 'url', 'description', 'parent_id', 'updated_at'];

        foreach (array_chunk(array_values($rows), self::UPSERT_CHUNK) as $chunk) {
            SiteProductCategory::upsert($chunk, ['site_id', 'slug'], $updateColumns);
        }

        // پاس دوم: parent_id بعد از ساخته شدن همه‌ی دسته‌ها ست می‌شود
        if ($parentMap !== []) {
            $existing = SiteProductCategory::where('site_id', $site->id)
                ->toBase()
                ->get(['id', 'slug', 'external_id']);

            $externalToId = $existing->pluck('id', 'external_id')->all();
            $slugToId = $existing->pluck('id', 'slug')->all();
            $childrenByParent = [];

            foreach ($parentMap as $slug => $parentExternalId) {
                $childId = $slugToId[$slug] ?? null;
                $parentId = $externalToId[$parentExternalId] ?? null;

                if ($childId && $parentId) {
                    $childrenByParent[$parentId][] = $childId;
                }
            }

            foreach ($childrenByParent as $parentId => $childIds) {
                foreach (array_chunk($childIds, 500) as $chunk) {
                    SiteProductCategory::whereIn('id', $chunk)->update(['parent_id' => $parentId]);
                }
            }
        }

        return count($rows);
    }

    /**
     * فقط ۱۰۰ محصول موجودِ اخیر (orderby=date, order=desc) ذخیره می‌شود.
     * خروجی: [تعداد محصولات موجود ذخیره‌شده, آیا مجموعه کامل و مطمئن است]
     */
    protected function syncWoocommerceProducts(Site $site, ?Response $first = null): array
    {
        $base = rtrim($site->url, '/').'/wp-json/wc/store/v1/products';
        $categoryExternalToId = SiteProductCategory::where('site_id', $site->id)->pluck('id', 'external_id')->all();
        $count = 0;

        $complete = $this->fetchAllPages(
            $base,
            ['stock_status' => ['instock'], 'orderby' => 'date', 'order' => 'desc'],
            function (array $items) use ($site, $categoryExternalToId, &$count) {
                $rows = $this->mapWoocommerceItems($site, $items, $categoryExternalToId);
                $count += $this->upsertProducts($rows, self::MAX_PRODUCTS - $count);
            },
            $first,
            function () use (&$count) {
                return $count >= self::MAX_PRODUCTS;
            }
        );

        // رسیدن به سقف = مجموعه‌ی مطمئن
        return [$count, $complete || $count >= self::MAX_PRODUCTS];
    }

    protected function mapWoocommerceItems(Site $site, array $items, array $categoryExternalToId): array
    {
        $rows = [];
        $now = now();

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id']) || ! ($item['is_in_stock'] ?? true)) {
                continue;
            }

            $categoryIds = collect($item['categories'] ?? [])
                ->map(fn ($c) => $categoryExternalToId[(string) ($c['id'] ?? '')] ?? null)
                ->filter()
                ->values()
                ->all();

            // Store API قیمت‌ها را به واحد کوچک برمی‌گرداند؛ باید بر currency_minor_unit تقسیم شود
            $prices = $item['prices'] ?? [];
            $divisor = 10 ** (int) ($prices['currency_minor_unit'] ?? 2);

            $rows[] = [
                'site_id' => $site->id,
                'slug' => $item['slug'] ?? (string) $item['id'],
                'category_id' => $categoryIds[0] ?? null,
                'category_ids' => json_encode($categoryIds),
                'source' => 'woocommerce',
                'external_id' => (string) $item['id'],
                'name' => $this->decode($item['name'] ?? ''),
                'url' => $item['permalink'] ?? null,
                'sku' => $this->str($item['sku'] ?? null),
                'description' => $item['description'] ?? null,
                'short_description' => $item['short_description'] ?? null,
                'price' => $this->minorToPrice($prices['price'] ?? null, $divisor),
                'regular_price' => $this->minorToPrice($prices['regular_price'] ?? null, $divisor),
                'sale_price' => $this->minorToPrice($prices['sale_price'] ?? null, $divisor),
                'currency' => $prices['currency_code'] ?? null,
                'images' => json_encode(collect($item['images'] ?? [])->pluck('src')->all(), self::JSON_FLAGS),
                'stock_status' => 'instock',
                'raw_data' => json_encode($item, self::JSON_FLAGS),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * خروجی: [تعداد دسته‌ها, تعداد محصولات, آیا crawl کامل و مطمئن بود]
     * با رسیدن به MAX_CRAWL_PRODUCTS محصول، crawl متوقف می‌شود.
     */
    protected function crawlProductsAndCategories(Site $site): array
    {
        [$urls, $truncated] = $this->collectCandidateUrls($site);

        $root = rtrim($site->url, '/');
        $complete = $urls !== [] && ! $truncated;
        $outOfStockSlugs = [];
        $categoryCache = [];
        $pathCache = [];
        $productsCount = 0;

        foreach (array_chunk($urls, self::CRAWL_CONCURRENCY) as $chunk) {
            if ($productsCount >= self::MAX_CRAWL_PRODUCTS) {
                break;
            }

            $requests = [];

            foreach ($chunk as $i => $u) {
                $requests[$i] = ['url' => $u, 'timeout' => 15];
            }

            $responses = $this->poolGet($requests);

            $rows = [];

            foreach ($chunk as $i => $url) {
                $response = $responses[$i] ?? null;

                if (! ($response instanceof Response)) {
                    $complete = false;

                    continue;
                }

                if (! $response->successful()) {
                    // 404/410 یعنی صفحه واقعاً حذف شده؛ بقیه‌ی خطاها یعنی نتیجه قابل‌اعتماد نیست
                    if (! in_array($response->status(), [404, 410], true)) {
                        $complete = false;
                    }

                    continue;
                }

                $result = $this->crawledRowFromHtml($site, $response->body(), $url, $root, $categoryCache, $pathCache);

                // ناموجود ذخیره نشود (و اگر قبلاً ذخیره شده بود، پاک می‌شود)
                if ($result['status'] === 'outofstock') {
                    $outOfStockSlugs[] = md5($url);

                    continue;
                }

                if ($result['status'] !== 'ok') {
                    continue;
                }

                $rows[] = $result['row'];
            }

            $productsCount += $this->upsertProducts($rows, self::MAX_CRAWL_PRODUCTS - $productsCount);
        }

        if ($outOfStockSlugs !== []) {
            foreach (array_chunk($outOfStockSlugs, 500) as $slugs) {
                SiteProduct::where('site_id', $site->id)
                    ->whereIn('slug', $slugs)
                    ->delete();
            }
        }

        $categoriesCreated = count(array_unique(array_filter($categoryCache)));
        $reachedCap = $productsCount >= self::MAX_CRAWL_PRODUCTS;

        return [$categoriesCreated, $productsCount, $reachedCap || ($complete && $productsCount > 0)];
    }

    /**
     * از HTML یک صفحه، ردیف محصول (با JSON-LD) می‌سازد.
     * خروجی: ['status' => 'ok', 'row' => [...]] | ['status' => 'outofstock'] | ['status' => 'no_product']
     */
    protected function crawledRowFromHtml(
        Site $site,
        string $html,
        string $url,
        string $root,
        array &$categoryCache,
        array &$pathCache,
        bool $strict = false
    ): array {
        [$product, $breadcrumb] = $this->parseJsonLd($html, $url, $strict);
        $name = $this->str($product['name'] ?? null);

        if ($product === null || $name === null) {
            return ['status' => 'no_product'];
        }

        $offers = $product['offers'] ?? [];
        $offers = is_array($offers) ? ($offers[0] ?? $offers) : [];
        $offers = is_array($offers) ? $offers : [];
        $availability = $this->str($offers['availability'] ?? null) ?? '';

        if (preg_match('/OutOfStock|SoldOut|Discontinued/i', $availability)) {
            return ['status' => 'outofstock'];
        }

        $categoryChain = $this->categoryNamesFromBreadcrumb($breadcrumb, $name, $url, $root);
        $chainKey = implode('>', $categoryChain);

        if (! array_key_exists($chainKey, $categoryCache)) {
            $categoryCache[$chainKey] = $this->ensureCategoryChain($site, $categoryChain, $pathCache);
        }

        $categoryId = $categoryCache[$chainKey];
        $now = now();

        return [
            'status' => 'ok',
            'row' => [
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
                'price' => $this->normalizePrice($offers['price'] ?? null)
                    ?? $this->normalizePrice($offers['lowPrice'] ?? null),
                'currency' => $this->str($offers['priceCurrency'] ?? null),
                'images' => json_encode($this->normalizeImages($product['image'] ?? null), self::JSON_FLAGS),
                'stock_status' => stripos($availability, 'InStock') !== false ? 'instock' : null,
                'raw_data' => json_encode($product, self::JSON_FLAGS),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }

    /**
     * ذخیره‌ی دسته‌ای با سقف $limit. تعداد ردیف‌های واقعاً ذخیره‌شده را برمی‌گرداند.
     * توجه: upsert از cast های مدل رد می‌شود، به همین دلیل آرایه‌ها بالاتر json_encode شده‌اند.
     */
    protected function upsertProducts(array $rows, int $limit = PHP_INT_MAX): int
    {
        if ($rows === [] || $limit <= 0) {
            return 0;
        }

        $defaults = [
            'category_id' => null, 'category_ids' => '[]', 'external_id' => null, 'url' => null,
            'sku' => null, 'description' => null, 'short_description' => null,
            'price' => null, 'regular_price' => null, 'sale_price' => null, 'currency' => null,
            'images' => '[]', 'stock_status' => null, 'raw_data' => null,
        ];

        // slug تکراری در یک batch (مثلاً جابه‌جایی محصولات بین صفحات) روی PostgreSQL خطا می‌دهد
        $unique = [];

        foreach ($rows as $row) {
            $unique[$row['slug']] = array_merge($defaults, $row);
        }

        $rows = array_slice(array_values($unique), 0, $limit);
        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['site_id', 'slug', 'created_at']));

        foreach (array_chunk($rows, self::UPSERT_CHUNK) as $chunk) {
            SiteProduct::upsert($chunk, ['site_id', 'slug'], $updateColumns);
        }

        return count($rows);
    }

    /**
     * دسته‌هایی که نه خودشان محصول دارند و نه زیردسته‌شان، حذف می‌شوند.
     * والدِ هر دسته‌ی دارای محصول نگه داشته می‌شود تا درخت سالم بماند.
     */
    protected function pruneEmptyCategories(Site $site): void
    {
        $used = [];

        // toBase(): بدون cast مدل، پس category_ids به‌صورت رشته‌ی JSON می‌آید
        $products = SiteProduct::where('site_id', $site->id)
            ->toBase()
            ->get(['category_id', 'category_ids']);

        foreach ($products as $p) {
            if ($p->category_id) {
                $used[(int) $p->category_id] = true;
            }

            $ids = is_string($p->category_ids) ? json_decode($p->category_ids, true) : $p->category_ids;

            foreach ((array) $ids as $id) {
                if ($id) {
                    $used[(int) $id] = true;
                }
            }
        }

        $categories = SiteProductCategory::where('site_id', $site->id)
            ->toBase()
            ->get(['id', 'parent_id'])
            ->keyBy('id');

        $keep = [];

        foreach (array_keys($used) as $id) {
            while ($id && isset($categories[$id]) && ! isset($keep[$id])) {
                $keep[$id] = true;
                $id = $categories[$id]->parent_id ? (int) $categories[$id]->parent_id : null;
            }
        }

        $toDelete = $categories->keys()->reject(fn ($id) => isset($keep[$id]))->values()->all();

        foreach (array_chunk($toDelete, 500) as $chunk) {
            // جلوگیری از خطای foreign key روی parent_id
            SiteProductCategory::whereIn('parent_id', $chunk)->update(['parent_id' => null]);
            SiteProductCategory::whereIn('id', $chunk)->delete();
        }
    }

    /**
     * خروجی: [urls, آیا لیست به‌خاطر سقف MAX_CRAWL_URLS بریده شد]
     */
    protected function collectCandidateUrls(Site $site): array
    {
        $this->lastmods = []; // برای هر سایت از نو

        $root = rtrim($site->url, '/');
        $urls = [];

        // سه مسیر sitemap هم‌زمان گرفته می‌شوند؛ اولویت همان ترتیب است
        $candidates = array_map(fn ($p) => $root.$p, ['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml']);
        $locsMap = $this->fetchSitemapLocs($candidates);

        foreach ($candidates as $candidate) {
            $urls = $this->expandSitemap($locsMap[$candidate] ?? [], 0);

            if ($urls !== []) {
                break;
            }
        }

        if ($urls === [] && ! empty($site->crawled_pages)) {
            $urls = collect($site->crawled_pages)->pluck('url')->filter()->values()->all();
        }

        $urls = array_values(array_unique($urls));

        // جدیدترین‌ها اول (URLهای بدون lastmod ته لیست و با همان ترتیب قبلی)
        if ($this->lastmods !== []) {
            $order = array_flip($urls);

            usort($urls, fn ($a, $b) =>
                (($this->lastmods[$b] ?? 0) <=> ($this->lastmods[$a] ?? 0))
                ?: ($order[$a] <=> $order[$b])
            );
        }

        $truncated = count($urls) > self::MAX_CRAWL_URLS;

        return [array_slice($urls, 0, self::MAX_CRAWL_URLS), $truncated];
    }

    /**
     * چند sitemap را هم‌زمان می‌گیرد و <loc>های هر کدام را برمی‌گرداند (url => locs؛ در خطا آرایه‌ی خالی).
     */
    protected function fetchSitemapLocs(array $urls): array
    {
        $urls = array_values($urls);

        if ($urls === []) {
            return [];
        }

        $requests = [];

        foreach ($urls as $i => $u) {
            $requests[$i] = ['url' => $u, 'timeout' => 15];
        }

        try {
            $responses = $this->poolGet($requests);
        } catch (Throwable $e) {
            return [];
        }

        $out = [];

        foreach ($urls as $i => $u) {
            $r = $responses[$i] ?? null;

            if (! $r instanceof Response || ! $r->successful()) {
                $out[$u] = [];

                continue;
            }

            $body = $r->body();
            $locs = [];

            // هر <url> یا <sitemap> را جدا می‌خوانیم تا loc و lastmod با هم جفت شوند
            preg_match_all('/<(?:url|sitemap)\b[^>]*>(.*?)<\/(?:url|sitemap)>/is', $body, $blocks);

            foreach ($blocks[1] ?? [] as $block) {
                if (! preg_match('/<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/loc>/is', $block, $m)) {
                    continue;
                }

                $loc = html_entity_decode(trim($m[1]));
                $locs[] = $loc;

                if (preg_match('/<lastmod>\s*(.*?)\s*<\/lastmod>/is', $block, $lm)
                    && ($ts = strtotime(trim($lm[1])))) {
                    $this->lastmods[$loc] = $ts;
                }
            }

            // fallback به روش قبلی اگر ساختار <url> پیدا نشد
            if ($locs === []) {
                preg_match_all('/<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/loc>/is', $body, $matches);
                $locs = array_map(fn ($loc) => html_entity_decode(trim($loc)), $matches[1] ?? []);
            }

            $out[$u] = $locs;
        }

        return $out;
    }

    /**
     * locs یک sitemap را می‌گیرد؛ اگر sitemap index بود، به‌صورت بازگشتی (هر سطح هم‌زمان) وارد فرزندان می‌شود
     * و اگر sitemap ای با کلمه‌ی product داشت فقط همان‌ها (به‌جز دسته/برچسب/برند) را می‌خواند.
     */
    protected function expandSitemap(array $locs, int $depth): array
    {
        if ($depth >= 2) {
            return $locs;
        }

        $children = array_values(array_filter($locs, fn ($u) => (bool) preg_match('/\.xml(\?.*)?$/i', $u)));

        if ($children === []) {
            return $locs;
        }

        $productChildren = array_values(array_filter(
            $children,
            fn ($u) => stripos($u, 'product') !== false
                && ! preg_match('/product[_-]?(cat|tag|brand)|taxonom/i', $u)
        ));
        $targets = $productChildren ?: $children;

        $urls = [];

        foreach (array_chunk($targets, self::SITEMAP_CONCURRENCY) as $chunk) {
            $locsMap = $this->fetchSitemapLocs($chunk);

            foreach ($chunk as $child) {
                $urls = array_merge($urls, $this->expandSitemap($locsMap[$child] ?? [], $depth + 1));

                if (count($urls) > self::MAX_CRAWL_URLS) {
                    break 2;
                }
            }
        }

        return $urls;
    }

    protected function extractJsonLd(string $html): array
    {
        // خروج سریع: بیشتر صفحات غیرمحصول اصلاً JSON-LD ندارند
        if (stripos($html, 'ld+json') === false) {
            return [];
        }

        preg_match_all(
            '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $matches
        );

        $blocks = [];

        foreach ($matches[1] ?? [] as $raw) {
            $decoded = json_decode(trim($raw), true);

            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }

        return $blocks;
    }

    protected function flattenJsonLdNodes(array $block): array
    {
        if (isset($block['@graph']) && is_array($block['@graph'])) {
            return $block['@graph'];
        }

        return array_is_list($block) ? $block : [$block];
    }

    /**
     * خروجی: [Product مربوط به همین صفحه یا null, نود BreadcrumbList یا null]
     * ووکامرس در صفحه‌ی محصول، محصولات مرتبط را هم در JSON-LD می‌گذارد؛
     * پس Productی که URL/@id آن با صفحه یکی است اولویت دارد.
     * $strict: اگر چند محصول در صفحه هست و هیچ‌کدام مال همین URL نیست (مثلاً صفحه‌ی دسته)، رد می‌شود.
     */
    protected function parseJsonLd(string $html, string $pageUrl, bool $strict = false): array
    {
        $products = [];
        $breadcrumb = null;

        foreach ($this->extractJsonLd($html) as $block) {
            foreach ($this->flattenJsonLdNodes($block) as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $types = (array) ($node['@type'] ?? null);

                if (in_array('Product', $types, true)) {
                    $products[] = $node;
                }

                if (in_array('BreadcrumbList', $types, true)) {
                    $breadcrumb = $node;
                }
            }
        }

        $pagePath = $this->pathOf($pageUrl);

        $product = collect($products)->first(function ($p) use ($pagePath) {
            foreach ([$p['url'] ?? null, $p['@id'] ?? null] as $candidate) {
                if (is_string($candidate) && $candidate !== '' && $this->pathOf($candidate) === $pagePath) {
                    return true;
                }
            }

            return false;
        }) ?? ((! $strict || count($products) === 1) ? ($products[0] ?? null) : null);

        return [$product, $breadcrumb];
    }

    /**
     * نام دسته‌ها از breadcrumb: «خانه» و خودِ محصول حذف می‌شوند.
     */
    protected function categoryNamesFromBreadcrumb(?array $node, string $productName, string $url, string $root): array
    {
        if (! $node) {
            return [];
        }

        $crumbs = [];
        $elements = collect($node['itemListElement'] ?? [])
            ->filter(fn ($el) => is_array($el))
            ->sortBy('position');

        foreach ($elements as $el) {
            $item = $el['item'] ?? null;
            $name = $el['name'] ?? (is_array($item) ? ($item['name'] ?? null) : null);
            $link = is_string($item) ? $item : (is_array($item) ? ($item['@id'] ?? $item['url'] ?? null) : null);

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $crumbs[] = ['name' => trim($name), 'link' => is_string($link) ? $link : null];
        }

        // اولین crumb اگر صفحه‌ی اصلی سایت بود حذف شود
        if ($crumbs !== [] && $crumbs[0]['link'] !== null
            && $this->pathOf($crumbs[0]['link']) === $this->pathOf($root)) {
            array_shift($crumbs);
        }

        // آخرین crumb فقط اگر خودِ محصول بود حذف شود
        if ($crumbs !== []) {
            $last = $crumbs[array_key_last($crumbs)];

            $isProduct = $last['link'] === null
                || $this->pathOf($last['link']) === $this->pathOf($url)
                || Str::lower($last['name']) === Str::lower(trim($productName));

            if ($isProduct) {
                array_pop($crumbs);
            }
        }

        return array_column($crumbs, 'name');
    }

    /**
     * $pathCache (by reference): کلید = مسیر کامل دسته، مقدار = id. والدهای مشترک بین زنجیره‌های مختلف
     * فقط یک بار updateOrCreate می‌شوند.
     */
    protected function ensureCategoryChain(Site $site, array $names, array &$pathCache = []): ?int
    {
        $parentId = null;
        $path = [];

        foreach ($names as $name) {
            $path[] = $name;
            $key = implode('>', $path);

            if (isset($pathCache[$key])) {
                $parentId = $pathCache[$key];

                continue;
            }

            // slug به کل مسیر وابسته است تا «اکسسوری» زیر والدهای مختلف یکی نشود
            $slug = Str::limit(Str::slug($name), 60, '').'-'.substr(md5($site->id.'|'.$key), 0, 8);

            $category = SiteProductCategory::updateOrCreate(
                ['site_id' => $site->id, 'slug' => $slug],
                ['parent_id' => $parentId, 'source' => 'crawled', 'name' => $name]
            );

            $parentId = $category->id;
            $pathCache[$key] = $parentId;
        }

        return $parentId;
    }

    // =====================================================================
    //  افزودن دستیِ یک محصول با لینک
    // =====================================================================

    /**
     * خروجی: ['ok' => bool, 'status' => 'created'|'existing'|null, 'product' => SiteProduct|null, 'message' => string|null]
     */
    public function importFromUrl(Site $site, string $rawUrl): array
    {

        $url = $this->normalizeProductUrl($rawUrl);

        if ($url === null) {
            return $this->importFail('Invalid link.');
        }

        if (! $this->sameHost($url, (string) $site->url)) {
            return $this->importFail('This link belongs to the site '.$site->domain.' نیست.');
        }

        if ($this->pathOf($url) === '' && ! str_contains($url, '?')) {
            return $this->importFail('This link is the site\'s home page, not a product.');
        }

        $upgrade = null;

        // ۱) شاید قبلاً در دیتابیس باشد (کاربر نمی‌دانسته)
        if ($existing = $this->findExistingProduct($site, $url)) {
            // ردیف crawledِ سایت وردپرسی قابل ویرایش در سایت نیست؛ باید ارتقا یابد
            if (! ($existing->source === 'crawled' && ($site->is_woocommerce || $site->is_wordpress))) {
                return $this->importOk('existing', $existing);
            }

            $upgrade = $existing;
        }

        $row = null;
        $outOfStock = false;

        // ۲) وردپرس: اول Store API (داده‌ی کامل‌تر)
        if ($site->is_woocommerce || $site->is_wordpress) {
            [$row, $outOfStock] = $this->fetchWoocommerceRow($site, $url);
        }

        // ۳) صفحه‌ی خودِ محصول
        if ($row === null && ! $outOfStock) {
            $response = $this->getOne($url, [], 20);

            if (! $response instanceof Response) {
                return $this->importFail('Could not reach the product page. Try again in a moment.');
            }

            if (in_array($response->status(), [404, 410], true)) {
                return $this->importFail('Page not found (404). Check the link.');
            }

            if (! $response->successful()) {
                return $this->importFail('The site didn\'t return a valid response (code '.$response->status().').');
            }

            $html = $response->body();

            // ۳-الف) وردپرس: ID واقعی از HTML + تأیید با Store API
            if ($site->is_woocommerce || $site->is_wordpress) {
                $id = $this->wooIdFromHtml($html);

                if ($id !== null) {
                    [$row, $outOfStock] = $this->fetchWoocommerceRowById($site, $id, $url);
                }
            }

            // ۳-ب) سایت ووکامرسی: هرگز ردیف crawled (غیرقابل انتشار) نساز
            if ($row === null && ! $outOfStock && $site->is_woocommerce) {
                return $this->importFail('This product couldn\'t be read from the site\'s WooCommerce; check the link or try again later.');
            }

            // ۳-ج) بقیه‌ی سایت‌ها: JSON-LD
            if ($row === null && ! $outOfStock) {
                $categoryCache = [];
                $pathCache = [];

                $result = $this->crawledRowFromHtml(
                    $site,
                    $html,
                    $url,
                    rtrim((string) $site->url, '/'),
                    $categoryCache,
                    $pathCache,
                    true
                );

                if ($result['status'] === 'outofstock') {
                    $outOfStock = true;
                } elseif ($result['status'] === 'ok') {
                    $row = $result['row'];

                    // slug را مثل Store API (آخرین بخش آدرس) می‌گذاریم تا sync بعدی دوبل نسازد
                    $segment = strtolower($this->lastSegment($url));

                    if ($site->is_woocommerce && $segment !== '') {
                        $row['slug'] = $segment;
                    }
                }
            }
        }

        if ($outOfStock) {
            return $this->importFail('This product is out of stock; only in-stock products can be added.');
        }

        if ($row === null) {
            return $this->importFail('No product data was found on this page. Make sure the link points directly to a product page.');
        }

        // ۴) ارتقای ردیف crawled به woocommerce (همان id، پس ارجاع‌های سئو می‌مانند)
        if ($upgrade) {
            if (($row['source'] ?? null) !== 'woocommerce') {
                return $this->importOk('existing', $upgrade);
            }

            $clash = SiteProduct::where('site_id', $site->id)
                ->where('slug', $row['slug'])
                ->where('id', '!=', $upgrade->id)
                ->first();

            if ($clash) {
                return $this->importOk('existing', $clash);
            }

            SiteProduct::where('site_id', $site->id)->whereKey($upgrade->id)->update(
                array_diff_key($row, array_flip(['site_id', 'created_at']))
                + ['stock_status' => 'instock', 'is_manual' => true]
            );

            return $this->importOk('created', $upgrade->fresh());
        }

        // ۵) چک نهایی تکراری با slug نهایی یا sku
        $duplicate = SiteProduct::where('site_id', $site->id)
            ->where(function ($q) use ($row) {
                $q->where('slug', $row['slug']);

                if (! empty($row['sku'])) {
                    $q->orWhere('sku', $row['sku']);
                }
            })
            ->first();

        if ($duplicate) {
            return $this->importOk('existing', $duplicate);
        }

        // 'instock': محصولی که صریحاً ناموجود نبوده، باید در لیست انتخاب دیده شود
        $row['stock_status'] = 'instock';
        $row['is_manual'] = true;

        $this->upsertProducts([$row]);

        $product = SiteProduct::where('site_id', $site->id)->where('slug', $row['slug'])->first();

        if (! $product) {
            return $this->importFail('Saving the product failed. Please try again.');
        }

        if (! $site->is_shop) {
            $site->update(['is_shop' => true]);
        }

        $this->applySiteCurrency($site);
        $product->refresh();

        Log::info('[SiteProductService] محصول با لینک اضافه شد', [
            'site_id' => $site->id,
            'product_id' => $product->id,
            'url' => $url,
        ]);

        return $this->importOk('created', $product);
    }

    protected function importOk(string $status, SiteProduct $product): array
    {
        return ['ok' => true, 'status' => $status, 'product' => $product, 'message' => null];
    }

    protected function importFail(string $message): array
    {
        return ['ok' => false, 'status' => null, 'product' => null, 'message' => $message];
    }

    /**
     * محصول را با slug آخرِ آدرس از Store API می‌گیرد و فقط اگر permalink آن دقیقاً همین صفحه بود قبول می‌کند.
     * خروجی: [ردیف|null, ناموجود؟]
     */
    protected function fetchWoocommerceRow(Site $site, string $url): array
    {
        $segment = $this->lastSegment($url);

        if ($segment === '') {
            return [null, false];
        }

        $base = rtrim((string) $site->url, '/').'/wp-json/wc/store/v1/products';
        $pagePath = mb_strtolower($this->pathOf($url));

        // slug فارسی در دیتابیس وردپرس به‌صورت percent-encoded با حروف کوچک ذخیره می‌شود
        foreach (array_unique([strtolower($segment), urldecode($segment)]) as $slug) {
            $response = $this->getOne($base, ['slug' => $slug, 'per_page' => 5], 20, true);

            if (! $this->isList($response)) {
                continue;
            }

            foreach ($response->json() as $item) {
                if (! is_array($item) || ! isset($item['id'])) {
                    continue;
                }

                if (mb_strtolower($this->pathOf((string) ($item['permalink'] ?? ''))) !== $pagePath) {
                    continue;
                }

                if (! ($item['is_in_stock'] ?? true)) {
                    return [null, true];
                }

                $map = $this->ensureWoocommerceCategories($site, (array) ($item['categories'] ?? []));
                $rows = $this->mapWoocommerceItems($site, [$item], $map);

                return [$rows[0] ?? null, false];
            }
        }

        return [null, false];
    }

    /**
     * ID محصول ووکامرس از HTML صفحه: کلاس postid-N روی body، shortlink، یا فرم add-to-cart.
     */
    protected function wooIdFromHtml(string $html): ?string
    {
        foreach ([
            '/<body[^>]*class=["\'][^"\']*\bpostid-(\d+)\b/i',
            '/<link[^>]+rel=["\']shortlink["\'][^>]*href=["\'][^"\']*[?&]p=(\d+)/i',
            '/name=["\']add-to-cart["\'][^>]*value=["\'](\d+)["\']/i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * محصول را با ID از Store API می‌گیرد و فقط اگر permalink دقیقاً همین صفحه بود قبول می‌کند.
     * خروجی: [ردیف|null, ناموجود؟]
     */
    protected function fetchWoocommerceRowById(Site $site, string $id, string $url): array
    {
        $base = rtrim((string) $site->url, '/').'/wp-json/wc/store/v1/products/'.$id;
        $response = $this->getOne($base, [], 20, true);

        if (! $response instanceof Response || ! $response->successful()) {
            return [null, false];
        }

        $item = $response->json();

        if (! is_array($item) || ! isset($item['id']) || (string) $item['id'] !== $id) {
            return [null, false];
        }

        if (mb_strtolower($this->pathOf((string) ($item['permalink'] ?? ''))) !== mb_strtolower($this->pathOf($url))) {
            return [null, false];
        }

        if (! ($item['is_in_stock'] ?? true)) {
            return [null, true];
        }

        $map = $this->ensureWoocommerceCategories($site, (array) ($item['categories'] ?? []));
        $rows = $this->mapWoocommerceItems($site, [$item], $map);

        return [$rows[0] ?? null, false];
    }

    /**
     * دسته‌های محصول (اگر در دیتابیس نبودند) ساخته می‌شوند. خروجی: external_id => id محلی.
     */
    protected function ensureWoocommerceCategories(Site $site, array $categories): array
    {
        $map = [];

        foreach ($categories as $c) {
            if (! is_array($c) || ! isset($c['id'])) {
                continue;
            }

            $external = (string) $c['id'];

            $category = SiteProductCategory::updateOrCreate(
                ['site_id' => $site->id, 'slug' => $c['slug'] ?? $external],
                [
                    'source' => 'woocommerce',
                    'external_id' => $external,
                    'name' => $this->decode($c['name'] ?? ''),
                    'url' => $c['link'] ?? null,
                ]
            );

            $map[$external] = $category->id;
        }

        return $map;
    }

    /**
     * آیا این محصول (با slug یا مسیر آدرس) از قبل برای این سایت ذخیره شده؟
     */
    protected function findExistingProduct(Site $site, string $url): ?SiteProduct
    {
        $segment = $this->lastSegment($url);
        $trimmed = rtrim($url, '/');

        $slugs = array_values(array_unique(array_filter([
            md5($url),
            md5($trimmed),
            md5($trimmed.'/'),
            strtolower($segment),
        ])));

        $needles = array_values(array_unique(array_filter([
            mb_strtolower($segment),
            mb_strtolower(urldecode($segment)),
        ])));

        $pagePath = mb_strtolower($this->pathOf($url));

        $candidates = SiteProduct::where('site_id', $site->id)
            ->where(function ($q) use ($slugs, $needles) {
                $q->whereIn('slug', $slugs);

                foreach ($needles as $n) {
                    $q->orWhereRaw('LOWER(url) LIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $n).'%']);
                }
            })
            ->get();

        return $candidates->first(
            fn (SiteProduct $p) => in_array($p->slug, $slugs, true)
                || ($pagePath !== '' && $p->url && mb_strtolower($this->pathOf($p->url)) === $pagePath)
        );
    }

    /**
     * ردیف دستی‌ای که همان محصول در sync (با slug متفاوت) آمده، حذف می‌شود تا دوبل نماند.
     */
    protected function removeManualDuplicates(Site $site): void
    {
        $rows = SiteProduct::where('site_id', $site->id)->toBase()->get(['id', 'url', 'is_manual']);

        $synced = [];

        foreach ($rows as $r) {
            $path = $r->url ? mb_strtolower($this->pathOf($r->url)) : '';

            if (! $r->is_manual && $path !== '') {
                $synced[$path] = true;
            }
        }

        $dupIds = [];

        foreach ($rows as $r) {
            $path = $r->url ? mb_strtolower($this->pathOf($r->url)) : '';

            if ($r->is_manual && $path !== '' && isset($synced[$path])) {
                $dupIds[] = $r->id;
            }
        }

        foreach (array_chunk($dupIds, 500) as $chunk) {
            SiteProduct::whereIn('id', $chunk)->delete();
        }
    }

    protected function normalizeProductUrl(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        $p = parse_url($raw);

        if (! $p || empty($p['host']) || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $query = [];

        if (! empty($p['query'])) {
            parse_str($p['query'], $query);

            foreach (array_keys($query) as $k) {
                $key = strtolower((string) $k);

                if (str_starts_with($key, 'utm_') || in_array($key, ['fbclid', 'gclid', 'add-to-cart'], true)) {
                    unset($query[$k]);
                }
            }
        }

        $url = strtolower($p['scheme']).'://'.strtolower($p['host']).($p['path'] ?? '');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    protected function sameHost(string $a, string $b): bool
    {
        $host = fn (string $u) => preg_replace('/^www\./i', '', strtolower((string) parse_url($u, PHP_URL_HOST)));

        return $host($a) !== '' && $host($a) === $host($b);
    }

    protected function lastSegment(string $url): string
    {
        return Str::afterLast(rtrim((string) parse_url($url, PHP_URL_PATH), '/'), '/');
    }

    // ---------- کمکی‌ها ----------

    protected function pathOf(string $url): string
    {
        return rtrim(urldecode((string) parse_url($url, PHP_URL_PATH)), '/');
    }

    protected function decode(mixed $value): string
    {
        return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function str(mixed $value): ?string
    {
        if (is_bool($value) || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    // قیمت خالی ("" برای «تماس بگیرید») باعث TypeError می‌شد
    /**
     * سایتی که ادمین واحد قیمتش را تعیین کرده (مثلاً ریال)، همه‌ی محصولاتش همان واحد را می‌گیرند؛
     * بقیه‌ی سایت‌ها مثل قبل‌اند (واحد خالی = تومان).
     */
    public function applySiteCurrency(Site $site): void
    {
        if ($site->product_currency && preg_match('/^[A-Z]{3}$/', (string) $site->product_currency)) {
            SiteProduct::where('site_id', $site->id)
                ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', '!=', $site->product_currency))
                ->update(['currency' => $site->product_currency]);
        }
    }

    protected function minorToPrice(mixed $value, int $divisor): ?float
    {
        return is_numeric($value) ? (float) $value / $divisor : null;
    }

    protected function normalizePrice(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٬' => '', ',' => '', ' ' => '',
        ]);

        return is_numeric($value) ? (float) $value : null;
    }

    // image در JSON-LD می‌تواند string، آرایه‌ی string یا ImageObject باشد
    protected function normalizeImages(mixed $image): array
    {
        if (is_string($image)) {
            return $image === '' ? [] : [$image];
        }

        if (! is_array($image)) {
            return [];
        }

        if (isset($image['url']) || isset($image['contentUrl'])) {
            return $this->normalizeImages($image['url'] ?? $image['contentUrl']);
        }

        $out = [];

        foreach ($image as $i) {
            array_push($out, ...$this->normalizeImages($i));
        }

        return array_values(array_unique($out));
    }
}
