<?php

namespace App\Http\Controllers\Api\Product\PageProduct;

use App\Http\Controllers\Controller;
use App\Jobs\Products\PageProduct\PublishPageProductSeoToShopifyJob;
use App\Jobs\Products\PageProduct\PublishPageProductSeoToWpJob;
use App\Jobs\Products\PageProduct\RunPageProductSeoPipelineJob;
use App\Models\PageProduct\PageProductSeoOptimization;
use App\Models\Site;
use App\Models\SiteProduct;
use App\Services\Products\PageProduct\PageProductGapDetectionService;
use App\Services\Products\PageProduct\PageProductSeoPublisherResolver;
use App\Services\Products\PageProduct\ProductContentSanitizer;
use App\Services\SubscriptionService;
use App\Support\ContentLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PageProductSeoController extends Controller
{
    /** سقف‌ها باید با فرانت (EXTRA_LIMIT) و پلاگین یکی باشند. */
    private const MAX_TAGS = 6;
    private const MAX_TAG_LENGTH = 40;
    private const MAX_FAQ_ITEMS = 5;
    private const MAX_FAQ_QUESTION = 300;
    private const MAX_FAQ_ANSWER = 1000;
    private const MAX_IMAGE_ALTS = 30;
    private const MAX_IMAGE_ALT = 125;
    private const MAX_FOCUS_KEYWORD = 100;

    public function update(Request $request, Site $site, PageProductSeoOptimization $optimization): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($optimization->site_id !== $site->id, 404);

        if (! in_array($optimization->status, [PageProductSeoOptimization::STATUS_READY, PageProductSeoOptimization::STATUS_APPROVED], true)) {
            return response()->json(['message' => 'This item can\'t be edited.'], 422);
        }

        if ($optimization->wp_sync_status === 'syncing') {
            return response()->json(['message' => 'This item is being sent to the site; try again shortly.'], 409);
        }

        $validated = $request->validate([
            'short_description' => 'sometimes|required|string|max:1000',
            'description' => 'sometimes|required|string|max:20000',
            'seo_title' => 'sometimes|required|string|max:70',
            'meta_description' => 'sometimes|required|string|max:160',
            'focus_keyword' => 'sometimes|required|string|max:'.self::MAX_FOCUS_KEYWORD,
            'attributes' => 'sometimes|array|max:30',
            'attributes.*.name' => 'required|string|max:100',
            'attributes.*.value' => 'required|string|max:500',
            'tags' => 'sometimes|array|min:1|max:'.(self::MAX_TAGS * 2),
            'tags.*' => 'string|max:'.(self::MAX_TAG_LENGTH * 2),
            'faq' => 'sometimes|array|min:1|max:'.(self::MAX_FAQ_ITEMS * 2),
            'faq.*.question' => 'required|string|max:'.self::MAX_FAQ_QUESTION,
            'faq.*.answer' => 'required|string|max:'.self::MAX_FAQ_ANSWER,
            'image_alts' => 'sometimes|array|min:1|max:'.self::MAX_IMAGE_ALTS,
            'image_alts.*.id' => 'required|integer|min:1',
            'image_alts.*.alt' => 'required|string|max:'.(self::MAX_IMAGE_ALT * 2),
            // بازگردانی فیلد به مقدار اولیه‌ی AI
            'revert' => 'sometimes|array|max:'.count(PageProductSeoOptimization::FIELDS),
            'revert.*' => ['string', Rule::in(PageProductSeoOptimization::FIELDS)],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No changes were sent.'], 422);
        }

        $revert = array_values(array_unique((array) ($validated['revert'] ?? [])));
        unset($validated['revert']);

        $result = (array) $optimization->result;

        // فقط فیلدهایی که در نتیجه‌ی تولیدشده وجود دارند
        if (array_diff(array_merge(array_keys($validated), $revert), array_keys($result)) !== []) {
            return response()->json(['message' => 'Only generated fields can be edited.'], 422);
        }

        $original = (array) ($result['original'] ?? []);
        $edited = (array) ($result['edited_fields'] ?? []);
        $initialUnproven = (array) ($result['unproven_numbers_initial'] ?? $result['unproven_numbers'] ?? []);
        $changed = false;

        // بازگردانی: مقدار اولیه‌ی AI دقیقاً برمی‌گردد و علامت «ویرایش‌شده» پاک می‌شود
        foreach ($revert as $field) {
            if (! array_key_exists($field, $original)) {
                continue;
            }

            $result[$field] = $original[$field];
            unset($original[$field]);
            $edited = array_values(array_diff($edited, [$field]));
            $changed = true;
        }

        $existingTags = [];

        if (array_key_exists('tags', $validated) && $optimization->product) {
            $existingTags = app(PageProductGapDetectionService::class)->snapshot(
                $optimization->product,
                [],
                app(PageProductSeoPublisherResolver::class)->isShopify($site),
            )['tags'];
        }

        foreach ($validated as $field => $value) {
            $new = match ($field) {
                'attributes' => $this->cleanAttributes((array) $value, (array) ($result['attributes'] ?? [])),
                'tags' => ProductContentSanitizer::tags((array) $value, $existingTags, self::MAX_TAGS),
                'faq' => ProductContentSanitizer::faq((array) $value, self::MAX_FAQ_ITEMS),
                'image_alts' => $this->cleanImageAlts((array) $value, (array) ($result['image_alts'] ?? [])),
                'description' => $this->linkCategory(
                    ProductContentSanitizer::html(ProductContentSanitizer::stripFaqBlock((string) $value)),
                    $result['category'] ?? null,
                    $site,
                ),
                default => ProductContentSanitizer::plainLine((string) $value),
            };

            if ($field !== 'attributes' && ($new === '' || $new === [])) {
                return response()->json(['message' => "\"{$field}\" can't be empty or invalid."], 422);
            }

            // بدون تغییر واقعی، چیزی ذخیره نمی‌شود
            if (json_encode($new) === json_encode($result[$field] ?? null)) {
                continue;
            }

            // مقدار اولیه‌ی AI فقط بار اول نگه داشته می‌شود
            if (! array_key_exists($field, $original)) {
                $original[$field] = $result[$field] ?? null;
            }

            $result[$field] = $new;
            $edited[] = $field;
            $changed = true;
        }

        if (! $changed) {
            return response()->json(['message' => 'Nothing changed.', 'data' => ['proposed' => $result]]);
        }

        // هشدار عددها: روی همه‌ی متن‌ها (توضیحات، FAQ، مشخصات)؛ فقط عددهای هشدارشده‌ی اولیه که هنوز در متن هستند
        $present = ProductContentSanitizer::numbers(ProductContentSanitizer::textForNumbers($result));

        $result['unproven_numbers_initial'] = $initialUnproven;
        $result['unproven_numbers'] = array_values(array_intersect($initialUnproven, $present));

        $result['original'] = $original;
        $result['edited_fields'] = array_values(array_unique($edited));

        $optimization->update(['result' => $result]);

        return response()->json([
            'message' => 'Your edit was saved.',
            'data' => ['proposed' => $result],
        ]);
    }

    /**
     * مشخصات ویرایش‌شده: نام تکراری و ردیف خالی حذف می‌شود.
     * ردیفی که کاربر دست نزده منبع قبلی‌اش را نگه می‌دارد؛ ردیف جدید/تغییرکرده source = user می‌گیرد.
     *
     * @return list<array{name:string,value:string,source:string,verified:bool|null}>
     */
    protected function cleanAttributes(array $incoming, array $current): array
    {
        $known = [];

        foreach ($current as $a) {
            if (is_array($a) && isset($a['name'], $a['value'])) {
                $known[PageProductGapDetectionService::norm((string) $a['name']).'|'.PageProductGapDetectionService::norm((string) $a['value'])] = $a;
            }
        }

        $seen = [];
        $out = [];

        foreach ($incoming as $a) {
            $name = ProductContentSanitizer::plainLine((string) ($a['name'] ?? ''));
            $value = ProductContentSanitizer::plainLine((string) ($a['value'] ?? ''));
            $key = PageProductGapDetectionService::norm($name);

            if ($name === '' || $value === '' || $key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $prev = $known[$key.'|'.PageProductGapDetectionService::norm($value)] ?? null;

            $out[] = [
                'name' => $name,
                'value' => $value,
                'source' => $prev['source'] ?? 'user',
                'verified' => $prev['verified'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * alt تصاویر: فقط idهایی که AI برایشان alt تولید کرده (کاربر نمی‌تواند id دلخواه بفرستد)،
     * بدون تکرار و با سقف طول.
     *
     * @return list<array{id:int,alt:string}>
     */
    protected function cleanImageAlts(array $incoming, array $current): array
    {
        $allowed = [];

        foreach ($current as $row) {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                $allowed[(int) $row['id']] = true;
            }
        }

        $out = [];

        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $alt = ProductContentSanitizer::limitAtWord(
                ProductContentSanitizer::plainLine((string) ($row['alt'] ?? '')),
                self::MAX_IMAGE_ALT
            );

            if ($id > 0 && $alt !== '' && isset($allowed[$id]) && ! isset($out[$id])) {
                $out[$id] = ['id' => $id, 'alt' => $alt];
            }
        }

        return array_values($out);
    }

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $this->failStale($site);

        $current = $this->currentBatch($site);
        $query = PageProductSeoOptimization::where('site_id', $site->id);

        if ($request->filled('batch')) {
            $query->where('batch', (int) $request->query('batch'))->orderBy('id');
        } else {
            $query->whereIn('id', $this->visibleIds($site, $current))->orderByDesc('id');
        }

        $batch = $request->filled('batch') ? (int) $request->query('batch') : $current;

        $items = $query
            ->with(['product:id,name,url,images,description,category_id', 'product.category:id,name,url'])
            ->get();

        return response()->json([
            'batch' => $batch,
            'data' => $items->map(fn (PageProductSeoOptimization $o) => [
                'id' => $o->token,
                'status' => $o->status,
                'error' => $o->error,
                'missing_fields' => $this->missingWithCategoryLink($o),
                'wp_sync_status' => $o->wp_sync_status,
                'publish_error' => $o->publish_error,
                'product' => [
                    'id' => $o->product?->id,
                    'name' => $o->product?->name,
                    'url' => $o->product?->url,
                ],
            ])->values(),
        ]);
    }

    /**
     * فهرست محصولات همگام‌سازی‌شده‌ی سایت برای انتخاب (جستجو + صفحه‌بندی).
     * محصول ناموجود (outofstock) نمایش داده نمی‌شود؛ محصول دستی که stock_status ندارد نمایش داده می‌شود.
     *
     * علاوه بر محصولات، «limits» هم برمی‌گردد تا فرانت بداند کاربر چند محصول می‌تواند انتخاب کند
     * (پلن رایگان: محدود / پلن پولی: سقف کامل).
     */
    public function products(Request $request, Site $site, SubscriptionService $subscriptionService): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $query = SiteProduct::where('site_id', $site->id)
            ->where(fn ($q) => $q->whereNull('stock_status')->orWhere('stock_status', '!=', 'outofstock'));

        // همه‌ی کلمات جستجو باید در نام باشند (ترتیب مهم نیست)
        foreach (preg_split('/\s+/u', trim((string) $request->query('search', '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = str_replace(['ي', 'ك'], ['ی', 'ک'], mb_strtolower($word));
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%']);
        }

        $page = $query->orderByDesc('id')->paginate(24, ['id', 'name', 'url', 'price', 'currency', 'images']);

        return response()->json([
            'data' => $page->getCollection()->map(function (SiteProduct $p) {
                $images = is_string($p->images) ? json_decode($p->images, true) : $p->images;
                $first = is_array($images) ? ($images[0] ?? null) : null;

                // ستون images هم لیست URL است و هم ممکن است آرایه‌ی ووکامرس (src/url) باشد
                $src = is_array($first) ? ($first['src'] ?? $first['url'] ?? null) : $first;

                return [
                    'id' => (int) $p->id,
                    'name' => (string) $p->name,
                    'url' => $p->url,
                    'price' => $p->price !== null ? (float) $p->price : null,
                    'currency' => $p->currency,
                    'image' => is_string($src) && $src !== '' ? $src : null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'limits' => $this->limits($request, $subscriptionService),
        ]);
    }

    /** مرحله ۱: انتخاب محصولات و شروع فلو. */
    public function store(Request $request, Site $site, SubscriptionService $subscriptionService): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $max = (int) config('page_product_seo.max_products', 100);

        $validated = $request->validate([
            'product_ids' => 'required|array|min:1|max:'.$max,
            'product_ids.*' => 'integer|distinct',
        ]);

        $productIds = array_values(array_map('intval', $validated['product_ids']));

        if (SiteProduct::where('site_id', $site->id)->whereIn('id', $productIds)->count() !== count($productIds)) {
            return response()->json([
                'message' => 'Some selected products are invalid. Refresh the page and select again.',
            ], 422);
        }

        if (! $subscriptionService->getActiveSubscription($request->user())) {
            return response()->json(['message' => 'You don\'t have an active plan.'], 402);
        }

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json(['message' => 'You don\'t have enough credit left.'], 402);
        }

        // محصولی که نتیجه‌ی منتشرنشده دارد دوباره پردازش (و هزینه) نمی‌شود؛ نتیجه‌ی قبلی‌اش سر جایش می‌ماند
        $open = $this->openProductIds($site, $productIds);
        $alreadyOpen = count(array_intersect($productIds, $open));
        $productIds = array_values(array_diff($productIds, $open));

        if ($productIds === []) {
            return response()->json([
                'message' => 'The selected products are already in the results list and not yet published; review and publish them from there.',
                'code' => 'already_in_progress',
            ], 422);
        }

        // محدودیت پلن رایگان — همیشه در بک‌اند بررسی می‌شود (فرانت فقط نمایش می‌دهد و قابل دور زدن است).
        $limits = $this->limits($request, $subscriptionService);

        if ($limits['is_free'] && count($productIds) > $limits['remaining']) {
            $message = $limits['remaining'] === 0
                ? "You've used your free quota ({$limits['free_limit']} products). Upgrade to Pro to optimize more products."
                : "On the free plan you can optimize only {$limits['remaining']} more products. Select fewer or upgrade to Pro.";

            return response()->json([
                'message' => $message,
                'code' => 'free_limit_reached',
                'limits' => $limits,
            ], 402);
        }

        $batch = $this->currentBatch($site) + 1;

        $ids = [];

        foreach ($productIds as $productId) {
            $ids[] = PageProductSeoOptimization::create([
                'site_id' => $site->id,
                'site_product_id' => $productId,
                'batch' => $batch,
                'status' => PageProductSeoOptimization::STATUS_PENDING,
            ])->id;
        }

        RunPageProductSeoPipelineJob::dispatch($ids);

        return response()->json([
            'message' => $alreadyOpen > 0
                ? 'Optimizing '.count($ids)." new products. {$alreadyOpen} products were already in the results list and were not processed again."
                : 'Product SEO optimization started.',
            'batch' => $batch,
            'added' => count($ids),
            'already_open' => $alreadyOpen,
        ], 202);
    }

    /** وضعیت‌هایی که یعنی نتیجه هنوز منتشر نشده و باید در فهرست بماند (در صف، در حال پردازش، آماده‌ی بررسی، در حال ارسال). */
    private const OPEN = [
        PageProductSeoOptimization::STATUS_PENDING,
        PageProductSeoOptimization::STATUS_ANALYZING,
        PageProductSeoOptimization::STATUS_READY,
        PageProductSeoOptimization::STATUS_APPROVED,
    ];

    /** @param list<int> $productIds  @return list<int> */
    protected function openProductIds(Site $site, array $productIds): array
    {
        $this->failStale($site);

        return PageProductSeoOptimization::where('site_id', $site->id)
            ->whereIn('site_product_id', $productIds)
            ->whereIn('status', self::OPEN)
            ->distinct()
            ->pluck('site_product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * یک ردیف برای هر محصول: نتیجه‌ی منتشرنشده (اگر دارد) وگرنه آخرین ردیف.
     * منتشرشده/بی‌نقص فقط از آخرین نوبت نشان داده می‌شود؛ بقیه تا تکلیفشان روشن نشده در فهرست می‌مانند.
     * (قبلاً هر نوبت جدید همه‌ی نوبت‌های قبلی را از فهرست پنهان می‌کرد.)
     *
     * @return list<int>
     */
    protected function visibleIds(Site $site, int $current): array
    {
        return PageProductSeoOptimization::where('site_id', $site->id)
            ->orderBy('id')
            ->get(['id', 'site_product_id', 'status', 'batch'])
            ->groupBy('site_product_id')
            ->map(fn ($rows) => $rows->whereIn('status', self::OPEN)->last() ?? $rows->last())
            ->filter(fn ($o) => in_array($o->status, self::OPEN, true)
                || $o->status === PageProductSeoOptimization::STATUS_FAILED
                || (int) $o->batch === $current)
            ->pluck('id')
            ->values()
            ->all();
    }

    /** صف/پردازشی که ساعت‌ها تکان نخورده (job از دست رفته) خطا می‌شود تا محصول گیر نکند و قابل انتخاب دوباره باشد. */
    protected function failStale(Site $site): void
    {
        PageProductSeoOptimization::where('site_id', $site->id)
            ->whereIn('status', [PageProductSeoOptimization::STATUS_PENDING, PageProductSeoOptimization::STATUS_ANALYZING])
            ->where('updated_at', '<', now()->subHours(3))
            ->update([
                'status' => PageProductSeoOptimization::STATUS_FAILED,
                'error' => 'Processing failed or timed out. Please select again.',
                'updated_at' => now(),
            ]);
    }

    /** لینک دسته‌ی محصول در توضیحات (بعد از sanitize که تگ a را حذف می‌کند). */
    private function linkCategory(string $html, mixed $link, Site $site): string
    {
        if (! is_array($link) || empty($link['url']) || trim($html) === '') {
            return $html;
        }

        return ProductContentSanitizer::withCategoryLink(
            $html, (string) ($link['name'] ?? ''), (string) $link['url'], ContentLanguage::isPersian(ContentLanguage::forSite($site))
        );
    }

    /** لینک دسته‌ای که محصول باید داشته باشد ولی توضیحات فعلی‌اش ندارد (فقط برای موارد آماده‌ی بررسی). */
    private function missingCategoryLink(PageProductSeoOptimization $o): ?array
    {
        if ($o->status !== PageProductSeoOptimization::STATUS_READY || ! $o->product) {
            return null;
        }

        $link = app(PageProductGapDetectionService::class)->categoryLink($o->product);

        return $link && ! ProductContentSanitizer::hasLinkTo((string) $o->product->description, $link['url']) ? $link : null;
    }

    /** @return list<string> */
    private function missingWithCategoryLink(PageProductSeoOptimization $o): array
    {
        $missing = (array) ($o->missing_fields ?? []);

        if (! in_array('category_link', $missing, true) && $this->missingCategoryLink($o)) {
            $missing[] = 'category_link';
        }

        return $missing;
    }

    /**
     * نتیجه‌هایی که قبل از قابلیت لینک دسته ساخته شده‌اند، یک‌بار به‌روز می‌شوند:
     * توضیحات پیشنهادی لینک می‌گیرد، یا اگر توضیحات تولید نشده، فیلد قابل‌تأیید «لینک به دسته‌بندی» اضافه می‌شود.
     */
    private function addCategoryLink(PageProductSeoOptimization $o, Site $site): void
    {
        $link = $this->missingCategoryLink($o);

        if (! $link) {
            return;
        }

        $result = (array) $o->result;
        $result['category'] = $link;

        if (! empty($result['description'])) {
            $result['description'] = $this->linkCategory((string) $result['description'], $link, $site);
        } else {
            $result['category_link'] = $link;
        }

        $missing = $this->missingWithCategoryLink($o);

        if ($result != (array) $o->result || $missing !== (array) $o->missing_fields) {
            $o->update(['result' => $result, 'missing_fields' => $missing]);
        }
    }

    /** مرحله ۸: پیش‌نمایش (فعلی در برابر پیشنهادی). */
    public function show(Request $request, Site $site, PageProductSeoOptimization $optimization): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($optimization->site_id !== $site->id, 404);

        $this->addCategoryLink($optimization, $site);

        $product = $optimization->product;
        $isShopify = app(PageProductSeoPublisherResolver::class)->isShopify($site);
        $snapshot = $product ? app(PageProductGapDetectionService::class)->snapshot($product, [], $isShopify) : null;

        return response()->json(['data' => [
            'id' => $optimization->token,
            'status' => $optimization->status,
            'error' => $optimization->error,
            'needs_search' => $optimization->needs_search,
            'missing_fields' => $optimization->missing_fields,
            'sources' => $optimization->search_data['sources'] ?? [],
            'current' => [
                'short_description' => $product?->short_description,
                // بلوک FAQ از توضیحات فعلی جدا می‌شود تا مقایسه با پیشنهاد درست باشد
                'description' => $product ? ProductContentSanitizer::stripFaqBlock((string) $product->description) : null,
                'tags' => $snapshot['tags'] ?? [],
                'has_faq' => $snapshot['has_faq'] ?? false,
                // برای نمایش بندانگشتی تصاویر کنار alt پیشنهادی
                'images' => $snapshot['images'] ?? [],
            ],
            'proposed' => $optimization->result,
            // فرانت با این مقدار فیلدهای بی‌معنی برای پلتفرم (مثل کلمه‌ی کلیدی در Shopify) را پنهان می‌کند
            'platform' => $isShopify ? PageProductSeoPublisherResolver::SHOPIFY : PageProductSeoPublisherResolver::WORDPRESS,
            'wp_sync_status' => $optimization->wp_sync_status,
            'publish_error' => $optimization->publish_error,
        ]]);
    }

    /** مرحله ۸ و ۹: تأیید کاربر و ارسال فقط فیلدهای تأییدشده به ووکامرس یا Shopify (بر اساس اتصال سایت). */
    public function approve(Request $request, Site $site, PageProductSeoOptimization $optimization, PageProductSeoPublisherResolver $publishers): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($optimization->site_id !== $site->id, 404);

        if (! in_array($optimization->status, [PageProductSeoOptimization::STATUS_READY, PageProductSeoOptimization::STATUS_APPROVED], true)) {
            return response()->json(['message' => 'This item isn\'t ready for approval.'], 422);
        }

        // جلوگیری از تأیید دوباره وقتی ارسال قبلی هنوز در جریان است
        if ($optimization->wp_sync_status === 'syncing') {
            return response()->json(['message' => 'This item is being sent to the site.'], 409);
        }

        $validated = $request->validate([
            'fields' => 'required|array|min:1',
            'fields.*' => ['string', Rule::in(PageProductSeoOptimization::FIELDS)],
        ]);

        $fields = array_values(array_intersect($validated['fields'], array_keys((array) $optimization->result)));

        if ($fields === []) {
            return response()->json(['message' => 'No approvable field was selected.'], 422);
        }

        // پیش از هر تغییر وضعیت یا job: اگر ارسال ممکن نیست، همان لحظه به کاربر گفته می‌شود
        if ($reason = $publishers->unavailableReason($site, $optimization->product)) {
            return response()->json(['message' => $reason], 422);
        }

        $optimization->update([
            'approved_fields' => $fields,
            'status' => PageProductSeoOptimization::STATUS_APPROVED,
            'wp_sync_status' => 'not_synced',
            'publish_error' => null,
        ]);

        // same rule as ArticlePublisherResolver
        $publishers->isShopify($site)
            ? PublishPageProductSeoToShopifyJob::dispatch($optimization->id)
            : PublishPageProductSeoToWpJob::dispatch($optimization->id);

        return response()->json(['message' => 'Changes approved and being sent to '.$publishers->label($site).'.'], 202);
    }

    protected function currentBatch(Site $site): int
    {
        return (int) PageProductSeoOptimization::where('site_id', $site->id)->max('batch');
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    /**
     * محدودیت انتخاب محصول بر اساس پلن کاربر.
     *
     * - پلن پولی (پرو): تا config('page_product_seo.max_products') محصول در هر نوبت.
     * - پلن رایگان: در مجموع فقط config('page_product_seo.free_max_products') محصول
     *   (پیش‌فرض ۳) — کل سهمیه‌ی کاربر روی همه‌ی سایت‌هایش حساب می‌شود، نه برای هر نوبت،
     *   تا با چند بار شروع کردن نشود محدودیت را دور زد.
     *
     * @return array{is_free: bool, max_selectable: int, free_limit: int|null, used: int|null, remaining: int|null}
     */
    protected function limits(Request $request, SubscriptionService $subscriptionService): array
    {
        $max = (int) config('page_product_seo.max_products', 100);

        if (! $this->isFreePlan($request, $subscriptionService)) {
            return [
                'is_free' => false,
                'max_selectable' => $max,
                'free_limit' => null,
                'used' => null,
                'remaining' => null,
            ];
        }

        $freeLimit = (int) config('page_product_seo.free_max_products', 3);

        // هر ردیفِ غیرخطا یک محصول از سهمیه مصرف می‌کند (خطاها حساب نمی‌شوند)
        $used = PageProductSeoOptimization::whereIn(
            'site_id',
            Site::where('user_id', $request->user()->id)->select('id')
        )
            ->where('status', '!=', PageProductSeoOptimization::STATUS_FAILED)
            ->count();

        $remaining = max(0, $freeLimit - $used);

        return [
            'is_free' => true,
            'max_selectable' => min($max, $remaining),
            'free_limit' => $freeLimit,
            'used' => $used,
            'remaining' => $remaining,
        ];
    }

    /**
     * کاربر «رایگان» حساب می‌شود مگر اینکه اشتراک پولیِ فعال و معتبر داشته باشد.
     * از hasActivePaidPlan() خودِ SubscriptionService استفاده می‌شود.
     */
    protected function isFreePlan(Request $request, SubscriptionService $subscriptionService): bool
    {
        return ! $subscriptionService->hasActivePaidPlan($request->user());
    }
}
