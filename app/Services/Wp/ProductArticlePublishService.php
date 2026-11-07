<?php

namespace App\Services\Wp;

use App\Models\AgentSetting;
use App\Models\ProductArticle;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * انتشار «مقاله محصول» در وردپرس.
 * Networking, signing and image compression are inherited from ArticlePublishService;
 * می‌رسد؛ اینجا فقط ساخت payload و مدیریت وضعیت مخصوص ProductArticle است.
 *
 *  - محصولات هر سکشن     → کلید «products» روی همان سکشن
 *  - جدول مقایسه          → به‌صورت <table> داخل content سکشن comparison
 *  - محصولات پیشنهادی     → کلید «related_products» (سطح بالا)
 *
 * منبع داده‌ی محصولات: جدول site_products (SiteProduct) با شرط site_id،
 * دقیقاً همان منبعی که ProductArticleWriterService استفاده می‌کند.
 * اگر سکشنی باید ویترین داشته باشد ولی محصولش پیدا نشود، انتشار متوقف می‌شود.
 */
class ProductArticlePublishService extends ArticlePublishService
{
    private const ACTION_PUBLISH = 'publish';
    private const ACTION_UPDATE = 'update';
    private const ACTION_DELETE = 'delete';
    private const ACTION_SYNC_STATUS = 'sync_status';

    /** سقف‌ها باید با پلاگین (MAX_*_PRODUCTS) هماهنگ باشند. */
    private const MAX_SECTION_PRODUCTS = 12;
    private const MAX_RELATED_PRODUCTS = 24;
    private const MAX_PRODUCT_SPECS = 5;

    public function createProductArticle(ProductArticle $article): array
    {
        if ($article->status !== ProductArticle::STATUS_COMPLETED) {
            return $this->failProductArticle($article, self::ACTION_PUBLISH, 'The product article is still being generated.');
        }

        try {
            $connection = $this->productConnectionOrFail($article);
        } catch (Throwable $e) {
            return $this->failProductArticle($article, self::ACTION_PUBLISH, $e->getMessage());
        }

        $postStatus = $this->resolveProductPostStatus($article);

        $article->update(['wp_sync_status' => 'syncing']);

        try {
            $payload = $this->buildProductPayload($article, $postStatus);
        } catch (Throwable $e) {
            return $this->failProductArticle($article, self::ACTION_PUBLISH, $e->getMessage());
        }

        $result = $this->call(
            $connection,
            'POST',
            config('wordpress_agent.plugin_routes.articles'),
            $payload,
            config('wordpress_agent.signing_paths.articles')
        );

        $this->logProductResult($article, self::ACTION_PUBLISH, $result);

        if ($result['ok']) {
            $body = is_array($result['body'] ?? null) ? $result['body'] : [];

            $article->update([
                'wp_post_id' => $body['wp_post_id'] ?? null,
                'wp_permalink' => $body['permalink'] ?? null,
                'wp_sync_status' => 'synced',
                'publish_status' => $postStatus === 'publish' ? 'published' : 'draft',
                'publish_error' => null,
                'published_at' => $postStatus === 'publish' ? now() : null,
                'last_synced_at' => now(),
            ]);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    public function updateProductArticle(ProductArticle $article): array
    {
        try {
            $connection = $this->productConnectionOrFail($article);
        } catch (Throwable $e) {
            return $this->failProductArticle($article, self::ACTION_UPDATE, $e->getMessage());
        }

        if (! $article->wp_post_id) {
            return $this->failProductArticle($article, self::ACTION_UPDATE, 'The article hasn\'t been published to WordPress yet.');
        }

        $article->update(['wp_sync_status' => 'syncing']);

        $postStatus = $this->resolveProductPostStatus($article);

        try {
            $payload = $this->buildProductPayload($article, $postStatus);
        } catch (Throwable $e) {
            return $this->failProductArticle($article, self::ACTION_UPDATE, $e->getMessage());
        }

        $path = rtrim(config('wordpress_agent.plugin_routes.articles'), '/') . '/' . $article->wp_post_id;
        $signingPath = rtrim(config('wordpress_agent.signing_paths.articles'), '/') . '/' . $article->wp_post_id;

        $result = $this->call($connection, 'PUT', $path, $payload, $signingPath);

        // اگر مقاله به سایت دیگری منتقل شده باشد، wp_post_id قدیمی آنجا 404 می‌دهد؛
        // فقط همین حالت را به‌عنوان create مجدد در نظر می‌گیریم.
        if ($this->isManagedArticleNotFound($result)) {
            Log::notice('WordPress managed product article not found during update; recreating', [
                'product_article_id' => $article->id,
                'old_wp_post_id' => $article->wp_post_id,
            ]);

            $article->update([
                'wp_post_id' => null,
                'wp_permalink' => null,
                'wp_sync_status' => 'not_synced',
            ]);

            return $this->createProductArticle($article);
        }

        $this->logProductResult($article, self::ACTION_UPDATE, $result);

        if ($result['ok']) {
            $body = is_array($result['body'] ?? null) ? $result['body'] : [];

            $article->update([
                'wp_sync_status' => 'synced',
                'publish_status' => $postStatus === 'publish' ? 'published' : 'draft',
                'wp_permalink' => $body['permalink'] ?? $article->wp_permalink,
                'publish_error' => null,
                'published_at' => $postStatus === 'publish' ? ($article->published_at ?? now()) : null,
                'last_synced_at' => now(),
            ]);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    public function deleteProductArticle(ProductArticle $article): array
    {
        try {
            $connection = $this->productConnectionOrFail($article);
        } catch (Throwable $e) {
            return $this->failProductArticle($article, self::ACTION_DELETE, $e->getMessage());
        }

        if (! $article->wp_post_id) {
            return $this->failProductArticle($article, self::ACTION_DELETE, 'The article was not on WordPress.');
        }

        $article->update(['wp_sync_status' => 'syncing']);

        $path = rtrim(config('wordpress_agent.plugin_routes.articles'), '/') . '/' . $article->wp_post_id;
        $signingPath = rtrim(config('wordpress_agent.signing_paths.articles'), '/') . '/' . $article->wp_post_id;

        $result = $this->call($connection, 'DELETE', $path, [], $signingPath);

        $this->logProductResult($article, self::ACTION_DELETE, $result);

        if ($result['ok']) {
            $article->update([
                'wp_sync_status' => 'not_synced',
                'publish_status' => null,
                'wp_post_id' => null,
                'wp_permalink' => null,
                'publish_error' => null,
                'published_at' => null,
                'last_synced_at' => now(),
            ]);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    public function syncProductArticleStatus(ProductArticle $article): array
    {
        try {
            $connection = $this->productConnectionOrFail($article);
        } catch (Throwable $e) {
            return $this->failProductArticle($article, self::ACTION_SYNC_STATUS, $e->getMessage());
        }

        if (! $article->wp_post_id) {
            return $this->failProductArticle($article, self::ACTION_SYNC_STATUS, 'The article is not published on WordPress.');
        }

        $article->update(['wp_sync_status' => 'syncing']);

        $path = rtrim(config('wordpress_agent.plugin_routes.articles'), '/') . '/' . $article->wp_post_id;
        $signingPath = rtrim(config('wordpress_agent.signing_paths.articles'), '/') . '/' . $article->wp_post_id;

        $result = $this->call($connection, 'GET', $path, [], $signingPath);

        $this->logProductResult($article, self::ACTION_SYNC_STATUS, $result);

        // پست در وردپرس پاک شده؛ مقاله نباید برای همیشه «synced» با لینک مرده بماند.
        if ($this->isManagedArticleNotFound($result)) {
            $article->update([
                'wp_post_id' => null,
                'wp_permalink' => null,
                'wp_sync_status' => 'not_synced',
                'publish_status' => null,
                'published_at' => null,
                'last_synced_at' => now(),
            ]);

            return ['ok' => false, 'message' => 'This article no longer exists on WordPress.'];
        }

        if ($result['ok']) {
            $body = is_array($result['body'] ?? null) ? $result['body'] : [];

            $publishStatus = match ($body['status'] ?? null) {
                'publish' => 'published',
                'draft' => 'draft',
                default => $article->publish_status,
            };

            $article->update([
                'wp_sync_status' => 'synced',
                'wp_permalink' => $body['permalink'] ?? $article->wp_permalink,
                'publish_status' => $publishStatus,
                'last_synced_at' => now(),
            ]);
        } else {
            $article->update([
                'wp_sync_status' => 'failed',
                'publish_error' => $result['message'],
            ]);
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Payload                                                             */
    /* ------------------------------------------------------------------ */

    private function buildProductPayload(ProductArticle $article, string $postStatus): array
    {
        $sections = $article->sections()->orderBy('position')->get();

        // جدول مقایسه فقط داخل content تجمیع‌شده‌ی مقاله است (نه content سکشن).
        $comparisonTable = $this->productExtractTable((string) ($article->content ?? ''));

        $catalog = $this->productResolveCatalog($article, $sections);
        $articleProducts = $catalog['article'];

        $content = [];
        $shownProductIds = [];
        $sectionProductCount = 0;
        $unresolvedSections = [];

        foreach ($sections as $section) {
            $type = (string) ($section->type ?? '');
            $sectionIds = $this->productIdList($section->product_ids ?? null);
            $products = $catalog['sections'][(string) $section->getKey()] ?? [];

            // ویترین فقط زیر سکشن product (یا اگر type نداشت و فقط یک محصول بود).
            $wantsProducts = $sectionIds !== [] && ($type === '' || $type === 'product');
            $showsProducts = count($products) > 0
                && ($type !== '' ? $type === 'product' : count($products) === 1);

            if ($wantsProducts && $products === []) {
                $unresolvedSections[] = [
                    'id' => $section->getKey(),
                    'heading' => (string) $section->heading,
                    'type' => $type,
                    'product_ids' => $sectionIds,
                ];
            }

            $markdown = (string) ($section->content ?? '');

            if ($type === 'comparison' && $comparisonTable !== '' && ! $this->productHasTable($markdown)) {
                $markdown = $comparisonTable . "\n\n" . $markdown;
            }

            $item = [
                'heading' => $this->markdownInlineToHtml((string) $section->heading),
                'content' => $this->productMarkdownToHtml($markdown),
            ];

            if ($showsProducts) {
                $item['products'] = $products;
                $sectionProductCount += count($products);

                foreach ($products as $product) {
                    if (isset($product['id'])) {
                        $shownProductIds[(string) $product['id']] = true;
                    }
                }
            }

            $content[] = $item;
        }

        if ($unresolvedSections !== []) {
            $this->productLogUnresolved($article, $unresolvedSections, $catalog);

            $names = implode('، ', array_map(
                static fn (array $s): string => '«' . trim(strip_tags($s['heading'])) . '»',
                array_slice($unresolvedSections, 0, 5)
            ));

            throw new \RuntimeException(
                'Product data for section ' . $names . ' not found (the product is missing from site_products for this site or was deleted). '
                . 'مقاله منتشر نشد تا بدون کارت محصول در وردپرس نیاید. جزئیات در لاگ wp_product_article.products_unresolved است.'
            );
        }

        // «محصولات پیشنهادی»: محصولات مقاله که در ویترین هیچ سکشنی نیامده‌اند.
        $related = array_values(array_filter(
            $articleProducts,
            static fn (array $product): bool => ! isset($product['id']) || ! isset($shownProductIds[(string) $product['id']])
        ));
        $related = array_slice($related, 0, self::MAX_RELATED_PRODUCTS);

        $payload = [
            'payload_version' => self::PAYLOAD_VERSION,
            'source_type' => 'product_article',
            'article_id' => $article->id,
            'title' => $article->title,
            'post_status' => $postStatus,
            'content' => $content,
        ];

        if ($related !== []) {
            $payload['related_products'] = $related;
        }

        // کلید با مقدار null/خالی فرستاده نمی‌شود، چون validate_callback پلاگین
        // کل درخواست را با 400 رد می‌کند.
        $optionalFields = [
            'meta_description' => $article->meta_description,
            'meta_title' => $article->meta_title,
            'og_title' => $article->og_title,
            'og_description' => $article->og_description,
            'focus_keyword' => $article->focus_keyword,
            'faq_schema' => $article->faq_schema,
            'product_schema' => $article->product_schema,
            'slug' => $article->slug,
        ];

        foreach ($optionalFields as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $payload[$key] = $value;
        }

        if ($article->image_status === ProductArticle::IMAGE_STATUS_GENERATED) {
            $file = $this->encodeStoredFile(self::IMAGE_DISK, $article->image_path);

            if ($file !== null) {
                if (filled($article->image_alt)) {
                    $file['alt'] = $article->image_alt;
                }

                $payload['featured_image'] = $file;
            }
        }

        Log::info('wp_product_article.payload_built', [
            'product_article_id' => $article->id,
            'sections' => count($content),
            'sections_with_products' => count(array_filter($content, static fn (array $s): bool => isset($s['products']))),
            'section_products' => $sectionProductCount,
            'related_products' => count($related),
            'article_products_loaded' => count($articleProducts),
            'products_source' => $catalog['source'],
            'comparison_table' => $comparisonTable !== '',
        ]);

        return $payload;
    }

    /* ------------------------------------------------------------------ */
    /*  منبع محصولات                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param  iterable<int, mixed>  $sections
     * @return array{
     *     article: list<array<string, mixed>>,
     *     by_id: array<string, array<string, mixed>>,
     *     sections: array<string, list<array<string, mixed>>>,
     *     source: string
     * }
     */
    private function productResolveCatalog(ProductArticle $article, iterable $sections): array
    {
        $sources = [];
        $articleProducts = [];
        $byId = [];

        // منبع اصلی: جدول site_products
        foreach ($this->productsFromDatabase($article, $sections) as $id => $product) {
            $byId[(string) $id] = $product;
            $articleProducts[] = $product;
            $sources[] = 'db';
        }

        // فالبک: اگر روی مدل مقاله/سکشن داده‌ی کامل محصول باشد (معمولاً نیست)
        if ($byId === []) {
            foreach ($this->productArticleLevelProducts($article) as $product) {
                if (isset($product['id']) && ! isset($byId[(string) $product['id']])) {
                    $byId[(string) $product['id']] = $product;
                    $articleProducts[] = $product;
                    $sources[] = 'article';
                }
            }
        }

        $sectionProducts = [];

        foreach ($sections as $section) {
            $key = (string) $section->getKey();

            $resolved = [];

            foreach ($this->productIdList($section->product_ids ?? null) as $id) {
                if (isset($byId[$id])) {
                    $resolved[] = $byId[$id];
                }

                if (count($resolved) >= self::MAX_SECTION_PRODUCTS) {
                    break;
                }
            }

            if ($resolved === []) {
                $direct = $this->productNormalizeList($section->products ?? null, self::MAX_SECTION_PRODUCTS);

                if ($direct !== []) {
                    $resolved = $direct;
                    $sources[] = 'section';
                }
            }

            if ($resolved !== []) {
                $sectionProducts[$key] = $resolved;
            }
        }

        return [
            'article' => array_slice($articleProducts, 0, 200),
            'by_id' => $byId,
            'sections' => $sectionProducts,
            'source' => $sources === [] ? 'none' : implode(',', array_unique($sources)),
        ];
    }

    /**
     * محصولات واقعی را از جدول site_products می‌خواند (همان منبع Writer).
     * کلید خروجی: id رشته‌ای. ترتیب = ترتیب product_ids مقاله.
     *
     * @param  iterable<int, mixed>  $sections
     * @return array<string, array<string, mixed>>
     */
    private function productsFromDatabase(ProductArticle $article, iterable $sections): array
    {
        $articleIds = $this->productIdList($article->product_ids ?? null);
        $ids = $articleIds;

        foreach ($sections as $section) {
            $ids = array_merge($ids, $this->productIdList($section->product_ids ?? null));
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $rows = SiteProduct::where('site_id', $article->site_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn ($p) => (string) $p->getKey());

        if ($rows->isEmpty()) {
            return [];
        }

        $categoryIds = $rows->pluck('category_id')->filter()->unique()->values()->all();
        $categories = $categoryIds === []
            ? collect()
            : SiteProductCategory::where('site_id', $article->site_id)
                ->whereIn('id', $categoryIds)
                ->pluck('name', 'id');

        $orderedIds = array_values(array_unique(array_merge($articleIds, $rows->keys()->all())));

        $out = [];

        foreach ($orderedIds as $id) {
            $p = $rows->get($id);

            if (! $p) {
                continue;
            }

            $raw = $this->productDecodeJson($p->raw_data ?? null);
            $url = trim((string) ($p->url ?? ''));

            // قیمت: price → sale_price → regular_price
            $current = $this->productToNumber($p->price)
                ?: $this->productToNumber($p->sale_price)
                ?: $this->productToNumber($p->regular_price);
            $regular = $this->productToNumber($p->regular_price);

            // keep cents: global stores price in decimals (19.99)
            $price = $current > 0 ? (string) round($current, 2) : '';
            // قیمت قبل از تخفیف فقط وقتی معنی دارد که واقعاً بیشتر از قیمت فعلی باشد.
            $regularPrice = ($regular > $current && $current > 0) ? (string) round($regular, 2) : '';

            $stock = strtolower(trim((string) ($p->stock_status ?? '')));
            $inStock = $stock === '' ? null : in_array($stock, ['instock', 'in_stock', 'available', 'onbackorder'], true);

            $excerptSource = trim((string) ($p->short_description ?? ''));
            if ($excerptSource === '') {
                $excerptSource = trim((string) ($p->description ?? ''));
            }
            $excerpt = mb_substr(
                trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($excerptSource), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                0,
                300
            );

            $category = $categories[$p->category_id] ?? null;
            if (! is_string($category) || trim($category) === '') {
                $category = null;
            } else {
                $category = html_entity_decode($category, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            $normalized = $this->productNormalizeOne([
                'id' => $p->getKey(),
                'name' => html_entity_decode((string) $p->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $url,
                'image' => $this->productFirstImage($p->images ?? null, $raw, $url),
                'price' => $price,
                'regular_price' => $regularPrice,
                'currency' => $p->currency,
                'category' => $category,
                'excerpt' => $excerpt,
                'in_stock' => $inStock,
                'specs' => $this->productSpecsFromRaw($raw),
            ]);

            if ($normalized !== null) {
                $out[(string) $p->getKey()] = $normalized;
            }
        }

        return $out;
    }

    /** مقدار JSON (آرایه یا رشته) را به آرایه تبدیل می‌کند. */
    private function productDecodeJson(mixed $value): array
    {
        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    /** decimal (رشته‌ی "1250000.00") یا هر ورودی عددی را به float تبدیل می‌کند. */
    private function productToNumber(mixed $value): float
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return 0.0;
        }

        $clean = preg_replace('/[^\d.]/', '', strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٬' => '', ',' => '', '،' => '',
        ]));

        return ($clean !== '' && is_numeric($clean)) ? (float) $clean : 0.0;
    }

    /**
     * اولین تصویر قابل استفاده: از ستون images (آرایه‌ی رشته یا {src|url})،
     * بعد raw_data.images. مسیر نسبی با دامنه‌ی url محصول کامل می‌شود.
     */
    private function productFirstImage(mixed $images, array $raw, string $productUrl): string
    {
        $candidates = [$this->productDecodeJson($images), (array) ($raw['images'] ?? [])];

        foreach ($candidates as $list) {
            if (isset($list['src']) || isset($list['url'])) {
                $list = [$list];
            }

            foreach ($list as $item) {
                if (is_array($item)) {
                    $item = $item['src'] ?? $item['url'] ?? $item['source_url'] ?? null;
                }

                if (! is_string($item) || trim($item) === '') {
                    continue;
                }

                $absolute = $this->productAbsoluteUrl(trim($item), $productUrl);

                if ($absolute !== '') {
                    return $absolute;
                }
            }
        }

        return '';
    }

    private function productAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $parts = parse_url($baseUrl);

        if (empty($parts['host'])) {
            return '';
        }

        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . '/' . ltrim($url, '/');
    }

    /**
     * مشخصات از raw_data.attributes (شکل ووکامرس: name + options[]).
     *
     * @return list<array{label:string,value:string}>
     */
    private function productSpecsFromRaw(array $raw): array
    {
        $specs = [];

        foreach ((array) ($raw['attributes'] ?? []) as $attr) {
            if (! is_array($attr)) {
                continue;
            }

            $label = trim((string) ($attr['name'] ?? $attr['label'] ?? ''));
            $value = $attr['options'] ?? $attr['value'] ?? '';

            if (is_array($value)) {
                $value = implode('، ', array_filter(array_map(
                    static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
                    $value
                )));
            }

            $value = trim((string) $value);

            if ($label === '' || $value === '') {
                continue;
            }

            $specs[] = ['label' => $label, 'value' => $value];

            if (count($specs) >= self::MAX_PRODUCT_SPECS) {
                break;
            }
        }

        return $specs;
    }

    /**
     * محصولات سطح مقاله از خودِ مدل (فالبک؛ معمولاً خالی است).
     *
     * @return list<array<string, mixed>>
     */
    private function productArticleLevelProducts(ProductArticle $article): array
    {
        $brief = $article->brief ?? null;

        if (is_string($brief)) {
            $decoded = json_decode($brief, true);
            $brief = is_array($decoded) ? $decoded : null;
        }

        if (is_array($brief)) {
            foreach (['products', 'selected_products'] as $key) {
                $products = $this->productNormalizeList($brief[$key] ?? null, 200);

                if ($products !== []) {
                    return $products;
                }
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $unresolved
     * @param  array<string, mixed>  $catalog
     */
    private function productLogUnresolved(ProductArticle $article, array $unresolved, array $catalog): void
    {
        Log::warning('wp_product_article.products_unresolved', [
            'product_article_id' => $article->id,
            'site_id' => $article->site_id,
            'article_product_ids' => $article->product_ids,
            'sections' => $unresolved,
            'article_products_loaded' => count($catalog['article']),
            'hint' => 'محصول با این id و site_id در جدول site_products پیدا نشد یا نام/لینک ندارد.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Markdown → HTML (با پشتیبانی مطمئن از جدول)                          */
    /* ------------------------------------------------------------------ */

    private function productMarkdownToHtml(string $markdown): string
    {
        $markdown = trim($markdown);

        if ($markdown === '') {
            return '';
        }

        $html = [];

        foreach ($this->productSplitTables($markdown) as [$kind, $text]) {
            if ($kind === 'table') {
                $html[] = $this->productTableHtml($text);

                continue;
            }

            $text = trim($text);

            if ($text !== '') {
                $html[] = $this->markdownToHtml($text);
            }
        }

        return implode("\n", array_filter($html, static fn ($chunk): bool => trim((string) $chunk) !== ''));
    }

    private function productExtractTable(string $markdown): string
    {
        foreach ($this->productSplitTables($markdown) as [$kind, $text]) {
            if ($kind === 'table') {
                return $text;
            }
        }

        return '';
    }

    private function productHasTable(string $markdown): bool
    {
        return $this->productExtractTable($markdown) !== '';
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function productSplitTables(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $total = count($lines);
        $segments = [];
        $buffer = [];
        $inFence = false;
        $i = 0;

        $flush = function () use (&$segments, &$buffer): void {
            if ($buffer !== []) {
                $segments[] = ['md', implode("\n", $buffer)];
                $buffer = [];
            }
        };

        while ($i < $total) {
            $line = $lines[$i];

            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = ! $inFence;
                $buffer[] = $line;
                $i++;

                continue;
            }

            if (
                ! $inFence
                && str_contains($line, '|')
                && isset($lines[$i + 1])
                && $this->productIsTableSeparator($lines[$i + 1])
            ) {
                $flush();

                $table = [$line, $lines[$i + 1]];
                $i += 2;

                while ($i < $total && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) {
                    $table[] = $lines[$i];
                    $i++;
                }

                $segments[] = ['table', implode("\n", $table)];

                continue;
            }

            $buffer[] = $line;
            $i++;
        }

        $flush();

        return $segments;
    }

    private function productIsTableSeparator(string $line): bool
    {
        return str_contains($line, '|')
            && (bool) preg_match('/^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/', $line);
    }

    /** @return list<string> */
    private function productSplitRow(string $row): array
    {
        $row = trim($row);
        $row = preg_replace('/^\|/', '', $row) ?? $row;
        $row = preg_replace('/(?<!\\\\)\|$/', '', $row) ?? $row;

        $cells = preg_split('/(?<!\\\\)\|/', $row) ?: [];

        return array_map(static fn (string $cell): string => trim(str_replace('\|', '|', $cell)), $cells);
    }

    private function productTableHtml(string $tableMarkdown): string
    {
        $rows = preg_split('/\R/u', trim($tableMarkdown)) ?: [];

        if (count($rows) < 2) {
            return '';
        }

        $header = $this->productSplitRow($rows[0]);
        $columns = count($header);
        $body = array_slice($rows, 2);

        $html = '<table class="sevof-table"><thead><tr>';

        foreach ($header as $cell) {
            $html .= '<th>' . $this->productCellHtml($cell) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($body as $row) {
            if (trim($row) === '') {
                continue;
            }

            $cells = array_pad(array_slice($this->productSplitRow($row), 0, $columns), $columns, '');

            $html .= '<tr>';

            foreach ($cells as $cell) {
                $html .= '<td>' . $this->productCellHtml($cell) . '</td>';
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function productCellHtml(string $text): string
    {
        $text = preg_replace('~<br\s*/?>~i', "\n", trim($text)) ?? $text;
        $links = [];

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(\s*([^)\s]+)(?:\s+"[^"]*")?\s*\)/u',
            function (array $m) use (&$links): string {
                $url = trim($m[2]);

                if (! preg_match('~^https?://~i', $url)) {
                    return $m[1];
                }

                $token = "\x1A" . count($links) . "\x1A";
                $links[$token] = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
                    . $this->productInlineEmphasis(htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8')) . '</a>';

                return $token;
            },
            $text
        ) ?? $text;

        $html = $this->productInlineEmphasis(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $html = str_replace(array_keys($links), array_values($links), $html);

        return nl2br($html, false);
    }

    private function productInlineEmphasis(string $escaped): string
    {
        $escaped = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/us', '<strong>$1$2</strong>', $escaped) ?? $escaped;

        return preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/us', '<em>$1</em>', $escaped) ?? $escaped;
    }

    /* ------------------------------------------------------------------ */
    /*  محصولات                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @return list<string>
     */
    private function productIdList(mixed $raw): array
    {
        if ($raw instanceof \Illuminate\Contracts\Support\Arrayable) {
            $raw = $raw->toArray();
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $id) {
            if (is_array($id) && isset($id['id'])) {
                $id = $id['id'];
            }

            if (is_scalar($id) && (string) $id !== '') {
                $ids[(string) $id] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productNormalizeList(mixed $raw, int $max): array
    {
        if ($raw instanceof \Illuminate\Contracts\Support\Arrayable) {
            $raw = $raw->toArray();
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $products = [];

        foreach ($raw as $item) {
            $product = $this->productNormalizeOne($item);

            if ($product !== null) {
                $products[] = $product;
            }

            if (count($products) >= $max) {
                break;
            }
        }

        return $products;
    }

    /** @return array<string, mixed>|null */
    private function productNormalizeOne(mixed $item): ?array
    {
        if ($item instanceof \Illuminate\Contracts\Support\Arrayable) {
            $item = $item->toArray();
        } elseif (is_object($item)) {
            $item = get_object_vars($item);
        }

        if (! is_array($item)) {
            return null;
        }

        $str = static function (mixed $value, int $max = 255): string {
            if (! is_scalar($value)) {
                return '';
            }

            return mb_substr(trim(strip_tags((string) $value)), 0, $max);
        };

        $httpUrl = static function (mixed $value): string {
            if (! is_string($value)) {
                return '';
            }

            $value = trim($value);

            if (str_starts_with($value, '//')) {
                $value = 'https:' . $value;
            }

            return preg_match('~^https?://~i', $value) ? $value : '';
        };

        $imageValue = static function (mixed $value): mixed {
            if (is_array($value)) {
                return $value['src'] ?? $value['url'] ?? null;
            }

            return $value;
        };

        $image = $imageValue($item['image'] ?? $item['image_url'] ?? $item['thumbnail'] ?? null);

        if (! is_string($image) || trim($image) === '') {
            $images = $item['images'] ?? null;

            if (is_array($images) && $images !== []) {
                $image = $imageValue(reset($images));
            }
        }

        $categoryValue = static function (mixed $value): mixed {
            if (is_array($value)) {
                return $value['name'] ?? $value['title'] ?? null;
            }

            return $value;
        };

        $category = $categoryValue($item['category'] ?? null);

        if (! is_string($category) || trim($category) === '') {
            $categories = $item['categories'] ?? null;

            if (is_array($categories) && $categories !== []) {
                $category = $categoryValue(reset($categories));
            }
        }

        $product = [];

        if (isset($item['id']) && is_scalar($item['id']) && (string) $item['id'] !== '') {
            $product['id'] = $item['id'];
        }

        $fields = [
            'name' => $str($item['name'] ?? $item['title'] ?? ''),
            'image' => $httpUrl($image),
            'url' => $httpUrl($item['url'] ?? $item['permalink'] ?? $item['link'] ?? null),
            'price' => $str($item['price'] ?? '', 60),
            'regular_price' => $str($item['regular_price'] ?? '', 60),
            'currency' => strtoupper($str($item['currency'] ?? '', 3)),
            'category' => $str($category, 120),
            'excerpt' => $str($item['excerpt'] ?? $item['short_description'] ?? '', 600),
        ];

        foreach ($fields as $key => $value) {
            if ($value !== '') {
                $product[$key] = $value;
            }
        }

        if (array_key_exists('in_stock', $item) && $item['in_stock'] !== null && $item['in_stock'] !== '') {
            $product['in_stock'] = filter_var($item['in_stock'], FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($item['stock_status']) && is_string($item['stock_status'])) {
            $product['in_stock'] = $item['stock_status'] === 'instock';
        }

        $specs = [];
        $rawSpecs = $item['specs'] ?? $item['attributes'] ?? [];

        if (is_array($rawSpecs)) {
            foreach ($rawSpecs as $key => $spec) {
                if (is_array($spec)) {
                    $label = $str($spec['label'] ?? $spec['name'] ?? '', 120);
                    $rawValue = $spec['value'] ?? $spec['options'] ?? '';

                    if (is_array($rawValue)) {
                        $rawValue = implode('، ', array_filter(array_map(
                            static fn ($v): string => is_scalar($v) ? trim((string) $v) : '',
                            $rawValue
                        )));
                    }

                    $value = $str($rawValue, 200);
                } elseif (is_string($key) && is_scalar($spec)) {
                    $label = $str($key, 120);
                    $value = $str($spec, 200);
                } else {
                    continue;
                }

                if ($label === '' || $value === '') {
                    continue;
                }

                $specs[] = ['label' => $label, 'value' => $value];

                if (count($specs) >= self::MAX_PRODUCT_SPECS) {
                    break;
                }
            }
        }

        if ($specs !== []) {
            $product['specs'] = $specs;
        }

        // محصولی که نه اسم دارد نه لینک، چیزی برای نمایش ندارد.
        if (! isset($product['name']) && ! isset($product['url'])) {
            return null;
        }

        return $product;
    }

    /* ------------------------------------------------------------------ */
    /*  وضعیت انتشار / لاگ                                                    */
    /* ------------------------------------------------------------------ */

    private function resolveProductPostStatus(ProductArticle $article): string
    {
        if ($article->wp_post_id) {
            return $article->publish_status === 'draft' ? 'draft' : 'publish';
        }

        if ($article->publish_status === 'draft') {
            return 'draft';
        }

        if ($article->publish_status === 'published') {
            return 'publish';
        }

        $setting = $article->site->agentSetting
            ?? AgentSetting::firstOrCreate(['site_id' => $article->site->id]);

        return $setting->default_publish_status === 'publish' ? 'publish' : 'draft';
    }

    private function productConnectionOrFail(ProductArticle $article)
    {
        $connection = $article->site->connection;

        if (! $connection) {
            throw new \RuntimeException('This site is not connected to WordPress yet.');
        }

        return $connection;
    }

    private function logProductResult(ProductArticle $article, string $action, array $result): void
    {
        Log::log($result['ok'] ? 'info' : 'warning', 'wp_product_article.' . $action, [
            'product_article_id' => $article->id,
            'site_id' => $article->site_id,
            'ok' => $result['ok'],
            'status_code' => $result['status_code'] ?? null,
            'wp_post_id' => $result['body']['wp_post_id'] ?? $article->wp_post_id,
            'error' => $result['ok'] ? null : ($result['message'] ?? null),
        ]);
    }

    private function failProductArticle(ProductArticle $article, string $action, string $message): array
    {
        $result = ['ok' => false, 'message' => $message];

        $this->logProductResult($article, $action, $result);

        $article->update([
            'wp_sync_status' => 'failed',
            'publish_error' => $message,
        ]);

        return $result;
    }
}
