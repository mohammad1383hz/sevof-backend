<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Jobs\Products\GenerateProductSeoIdeasJob;
use App\Models\ProductArticle;
use App\Models\ProductSeoIdea;
use App\Models\Site;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use App\Services\Products\ProductSeoIdeaService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use App\Services\Products\SiteProductService;
class ProductSeoIdeaController extends Controller
{
    protected const MAX_IDEA_PRODUCTS = 10;    // حداکثر محصول یک ایده هنگام ویرایش
    protected const PICKER_PAGE_SIZE = 24;
    protected const MAX_MANUAL_PRODUCTS = 8;  // هر ایده همه‌ی محصولات انتخابی را می‌گیرد، پس برابر MAX_IDEA_PRODUCTS

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $batch = (int) $request->query('batch', $this->currentBatch($site));

        $ideas = ProductSeoIdea::where('site_id', $site->id)->where('batch', $batch)->get();

        $products = SiteProduct::where('site_id', $site->id)
            ->whereIn('id', $ideas->pluck('product_ids')->flatten()->unique())
            ->get(['id', 'name', 'url', 'price', 'currency', 'images'])
            ->keyBy('id');

        // مقاله‌ی هر ایده: اولویت با مقاله‌ی غیرناموفق، وگرنه آخرین مقاله‌ی (ناموفق) تا کاربر بتواند «تلاش مجدد» بزند.
        // هر ایده که مقاله دارد، در فرانت قفل می‌شود و فقط «مشاهده» دارد.
        $articles = ProductArticle::where('site_id', $site->id)
            ->whereIn('product_seo_idea_id', $ideas->pluck('id'))
            ->latest('id')
            ->get(['id', 'product_seo_idea_id', 'status'])
            ->groupBy('product_seo_idea_id')
            ->map(fn ($group) => $group->first(fn ($a) => $a->status !== ProductArticle::STATUS_FAILED) ?? $group->first());

        return response()->json([
            'batch' => $batch,
            'status' => $site->idea_generation_status,
            'error' => $site->idea_generation_error,
            'data' => $ideas
                ->map(fn (ProductSeoIdea $idea) => $this->serializeIdea($idea, $products, $articles->get($idea->id)))
                ->values(),
        ]);
    }
    /**
     * افزودن یک محصول با لینک. اگر قبلاً در سیستم بود، همان برمی‌گردد (status=existing).
     */
    public function importProduct(
        Request $request,
        Site $site,
        SiteProductService $service,
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'url' => 'required|string|max:2000',
        ]);

        $result = $service->importFromUrl($site, $validated['url']);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        /** @var SiteProduct $p */
        $p = $result['product'];

        return response()->json([
            'status' => $result['status'], // created | existing
            'product' => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'url' => $p->url,
                'price' => $p->price !== null ? (float) $p->price : null,
                'currency' => $p->currency,
                'image' => $this->firstImage($p->images),
                'category_id' => $p->category_id ? (int) $p->category_id : null,
            ],
        ]);
    }
    public function generate(
        Request $request,
        Site $site,
        SubscriptionService $subscriptionService,
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'prompt' => 'nullable|string|max:500',
            'product_ids' => 'nullable|array|max:'.self::MAX_MANUAL_PRODUCTS,
            'product_ids.*' => 'integer|distinct',
        ]);

        $prompt = trim($validated['prompt'] ?? '');
        $productIds = array_values(array_map('intval', $validated['product_ids'] ?? []));

        if (! SiteProduct::where('site_id', $site->id)->exists()) {
            return response()->json([
                'message' => 'This site\'s products must be synced first.',
            ], 422);
        }

        // همه‌ی محصولات انتخابی باید متعلق به همین سایت باشند
        if ($productIds !== []
            && SiteProduct::where('site_id', $site->id)->whereIn('id', $productIds)->count() !== count($productIds)) {
            return response()->json([
                'message' => 'Some selected products are invalid. Refresh the page and select again.',
            ], 422);
        }

        $subscription = $subscriptionService->getActiveSubscription($request->user());

        if (! $subscription) {
            return response()->json([
                'message' => 'You don\'t have an active plan.',
            ], 402);
        }

        $nextBatch = $this->currentBatch($site) + 1;

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json([
                'message' => 'You don\'t have enough credit left to generate ideas.',
            ], 402);
        }

        $site->update([
            'idea_generation_status' => 'generating_ideas',
            'idea_generation_error' => null,
            'idea_generation_prompt' => $prompt,
        ]);

        GenerateProductSeoIdeasJob::dispatch($site, $nextBatch, $prompt, $productIds);

        return response()->json([
            'message' => 'Product SEO idea generation started and will be ready soon.',
            'batch' => $nextBatch,
        ], 202);
    }

    /**
     * ویرایش دستی ایده (عنوان، محصولات، لینک داخلی). فقط فیلدهای ارسال‌شده تغییر می‌کنند.
     * ایده‌ای که مقاله دارد قفل است.
     */
    public function update(
        Request $request,
        Site $site,
        ProductSeoIdea $idea,
        ProductSeoIdeaService $service,
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($idea->site_id !== $site->id, 404);

        if (ProductArticle::where('site_id', $site->id)->where('product_seo_idea_id', $idea->id)->exists()) {
            return response()->json([
                'message' => 'An article was created from this idea, so it can no longer be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:100',
            'product_ids' => 'sometimes|required|array|min:1|max:'.self::MAX_IDEA_PRODUCTS,
            'product_ids.*' => 'integer|distinct',
            'target_category_id' => 'sometimes|nullable|integer',
        ]);

        $changes = [];

        if (array_key_exists('title', $validated)) {
            $changes['title'] = trim($validated['title']);
        }

        if (array_key_exists('product_ids', $validated)) {
            $ids = array_values(array_map('intval', $validated['product_ids']));

            $found = SiteProduct::where('site_id', $site->id)
                ->whereIn('id', $ids)
                ->get(['id', 'category_id'])
                ->keyBy('id');

            if ($found->count() !== count($ids)) {
                return response()->json([
                    'message' => 'Some selected products are invalid.',
                ], 422);
            }

            $catIds = $found->pluck('category_id')->filter()->unique()->values();
            $names = SiteProductCategory::where('site_id', $site->id)->whereIn('id', $catIds)->pluck('name', 'id');

            $changes['product_ids'] = $ids;
            $changes['categories'] = $catIds
                ->map(fn ($id) => ['id' => (int) $id, 'name' => (string) $names->get($id, '')])
                ->all();
        }

        if (array_key_exists('target_category_id', $validated)) {
            $categoryId = $validated['target_category_id'];

            if ($categoryId === null) {
                // لینک به صفحه‌ی اصلی فروشگاه
                $changes['target_category_id'] = null;
                $changes['target_url'] = $service->siteBaseUrl($site);
                $changes['target_url_title'] = (string) $site->domain;
            } else {
                $target = $service->loadTargets($site, [(int) $categoryId])[(int) $categoryId] ?? null;

                if (! $target) {
                    return response()->json([
                        'message' => 'The selected category is not valid for internal linking.',
                    ], 422);
                }

                $changes['target_category_id'] = $target['id'];
                $changes['target_url'] = $target['url'];
                $changes['target_url_title'] = $target['title'];
            }
        }

        if ($changes !== []) {
            $idea->update($changes);
            $idea->refresh();
        }

        $products = SiteProduct::where('site_id', $site->id)
            ->whereIn('id', $idea->product_ids ?? [])
            ->get(['id', 'name', 'url', 'price', 'currency', 'images'])
            ->keyBy('id');

        return response()->json(['data' => $this->serializeIdea($idea, $products)]);
    }

    /**
     * فهرست محصولات سایت برای انتخاب (جستجو + فیلتر دسته + صفحه‌بندی).
     */
    public function products(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $search = trim((string) $request->query('search', ''));
        $categoryId = (int) $request->query('category_id', 0);

        $query = SiteProduct::where('site_id', $site->id)->where('stock_status', 'instock');

        // همه‌ی کلمات جستجو باید در نام باشند (ترتیب مهم نیست)
        foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = str_replace(['ي', 'ك'], ['ی', 'ک'], mb_strtolower($word));
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $word).'%']);
        }

        if ($categoryId > 0) {
            $query->whereIn('category_id', $this->categoryWithDescendants($site, $categoryId));
        }

        $page = $query->orderByDesc('id')
            ->paginate(self::PICKER_PAGE_SIZE, ['id', 'name', 'url', 'price', 'currency', 'images', 'category_id']);

        return response()->json([
            'data' => $page->getCollection()->map(fn (SiteProduct $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'url' => $p->url,
                'price' => $p->price !== null ? (float) $p->price : null,
                'currency' => $p->currency,
                'image' => $this->firstImage($p->images),
                'category_id' => $p->category_id ? (int) $p->category_id : null,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * همه‌ی دسته‌های سایت (برای فیلتر محصولات و انتخاب لینک داخلی)، با مسیر کامل و آدرس.
     */
    public function categories(
        Request $request,
        Site $site,
        ProductSeoIdeaService $service,
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);

        $categories = SiteProductCategory::where('site_id', $site->id)->get(['id', 'name', 'parent_id']);
        $byId = $categories->keyBy('id');

        $counts = SiteProduct::where('site_id', $site->id)
            ->where('stock_status', 'instock')
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id');

        $targets = $service->loadTargets($site, $categories->pluck('id')->all());

        $pathOf = function ($id) use ($byId): string {
            $names = [];
            $guard = 0;

            while ($id && isset($byId[$id]) && $guard++ < 10) {
                array_unshift($names, (string) $byId[$id]->name);
                $id = $byId[$id]->parent_id;
            }

            return implode(' › ', $names);
        };

        $data = $categories
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'parent_id' => $c->parent_id ? (int) $c->parent_id : null,
                'path' => $pathOf($c->id),
                'products_count' => (int) ($counts[$c->id] ?? 0),
                'url' => $targets[(int) $c->id]['url'] ?? null,
            ])
            ->sortBy('path')
            ->values();

        return response()->json(['data' => $data]);
    }

    /** ساختار یکسانِ یک ایده برای index و update. */
    protected function serializeIdea(ProductSeoIdea $idea, Collection $products, $article = null): array
    {
        return [
            'id' => $idea->token,
            'title' => $idea->title,
            'content_style' => $idea->content_style,
            'categories' => $idea->categories,

            // لینک داخلی مقاله
            'target_category_id' => $idea->target_category_id,
            'target_url' => $idea->target_url,
            'target_url_title' => $idea->target_url_title,

            'article_id' => $article?->id,
            'article_status' => $article?->status,
            'products' => collect($idea->product_ids)
                ->map(fn ($id) => $products->get($id))
                ->filter()
                ->values(),
        ];
    }

    /** شناسه‌ی دسته + همه‌ی زیردسته‌هایش. */
    protected function categoryWithDescendants(Site $site, int $categoryId): array
    {
        $children = SiteProductCategory::where('site_id', $site->id)
            ->whereNotNull('parent_id')
            ->pluck('parent_id', 'id');

        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $parent = array_shift($queue);

            foreach ($children as $id => $parentId) {
                if ((int) $parentId === $parent && ! in_array((int) $id, $ids, true)) {
                    $ids[] = (int) $id;
                    $queue[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    protected function firstImage(mixed $images): ?string
    {
        if (is_string($images)) {
            $images = json_decode($images, true);
        }

        $first = is_array($images) ? ($images[0] ?? null) : null;

        return is_string($first) && $first !== '' ? $first : null;
    }

    protected function currentBatch(Site $site): int
    {
        return (int) ProductSeoIdea::where('site_id', $site->id)->max('batch');
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
