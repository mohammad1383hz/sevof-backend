<?php
// app/Http/Controllers/Api/Product/ProductArticleController.php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Jobs\Products\FinalizeProductArticleJob;
use App\Jobs\Products\GenerateProductArticleImageJob;
use App\Jobs\Products\GenerateProductArticleSectionJob;
use App\Jobs\Products\GenerateProductSeoIdeasJob;
use App\Jobs\Products\StartProductArticleGenerationJob;
use App\Models\ProductArticle;
use App\Models\ProductArticleSection;
use App\Models\ProductSeoIdea;
use App\Models\Site;
use App\Models\SiteProduct;
use App\Models\SiteProductCategory;
use App\Services\AI\ChatModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductArticleController extends Controller
{
    private const NO_CREDIT_MESSAGE = 'You don\'t have an active plan or enough credit. Please renew your plan.';

    /* ------------------------------------------------------------------ */
    /*  ایده‌ها (موضوع‌ها)                                                   */
    /* ------------------------------------------------------------------ */

    public function ideas(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $ideas = ProductSeoIdea::where('site_id', $site->id)
            ->latest('id')
            ->get();

        $articles = ProductArticle::where('site_id', $site->id)
            ->whereIn('product_seo_idea_id', $ideas->pluck('id'))
            ->whereNotIn('status', [ProductArticle::STATUS_FAILED])
            ->get(['id', 'product_seo_idea_id', 'status'])
            ->keyBy('product_seo_idea_id');

        $data = $ideas->map(function (ProductSeoIdea $idea) use ($articles): array {
            $row = $idea->toArray();
            $article = $articles->get($idea->id);
            $row['article_id'] = $article?->id;
            $row['article_status'] = $article?->status;

            return $row;
        });

        return response()->json([
            'data' => $data,
            'generation' => [
                'status' => $site->idea_generation_status,
                'error' => $site->idea_generation_error,
            ],
        ]);
    }

    public function generateIdeas(
        Request $request,
        Site $site,
        SubscriptionService $subscriptionService
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json(['message' => self::NO_CREDIT_MESSAGE], 402);
        }

        if ($site->idea_generation_status === 'generating_ideas') {
            return response()->json([
                'message' => 'Ideas are being generated. Please wait a moment.',
            ], 409);
        }

        $prompt = $this->sanitizeText($validated['prompt'] ?? null, 1000) ?? '';

        $batch = ((int) ProductSeoIdea::where('site_id', $site->id)->max('batch')) + 1;

        $site->update([
            'idea_generation_status' => 'generating_ideas',
            'idea_generation_error' => null,
        ]);

        GenerateProductSeoIdeasJob::dispatch($site, $batch, $prompt);

        return response()->json([
            'message' => 'Idea generation started.',
            'batch' => $batch,
        ], 202);
    }

    public function regenerateImage(
        Request $request,
        Site $site,
        ProductArticle $article,
        SubscriptionService $subscriptionService
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');

        $validated = $request->validate([
            'prompt' => ['nullable', 'string', 'max:500'],
            'model' => ['nullable', 'string'],
        ]);

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json(['message' => self::NO_CREDIT_MESSAGE], 402);
        }

        $userPrompt = $this->sanitizeText($validated['prompt'] ?? null, 500);

        // Claim اتمیک: فقط وقتی تصویر generated/failed است، و جلوی دابل‌کلیک را می‌گیرد.
        $updated = ProductArticle::query()
            ->whereKey($article->id)
            ->whereIn('image_status', [
                ProductArticle::IMAGE_STATUS_GENERATED,
                ProductArticle::IMAGE_STATUS_FAILED,
            ])
            ->update([
                'image_status' => ProductArticle::IMAGE_STATUS_PENDING,
                'image_error' => null,
                'image_user_prompt' => $userPrompt,
                'image_model' => $validated['model'] ?? $article->image_model,
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'The image is being generated or isn\'t ready to regenerate yet.',
            ], 409);
        }

        GenerateProductArticleImageJob::dispatch($article->fresh());

        return response()->json([
            'message' => 'Image regeneration started.',
            'article_id' => $article->id,
        ], 202);
    }

    public function destroyIdea(Request $request, Site $site, ProductSeoIdea $idea): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($idea->site_id !== $site->id, 403, 'Forbidden.');

        $hasArticle = ProductArticle::where('product_seo_idea_id', $idea->id)
            ->whereNotIn('status', [ProductArticle::STATUS_FAILED])
            ->exists();

        if ($hasArticle) {
            return response()->json([
                'message' => 'An article was created from this idea, so it can\'t be deleted.',
            ], 422);
        }

        $idea->delete();

        return response()->json(['message' => 'Idea deleted.']);
    }

    /* ------------------------------------------------------------------ */
    /*  مقالات                                                              */
    /* ------------------------------------------------------------------ */

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $articles = ProductArticle::where('site_id', $site->id)
            ->latest()
            ->get([
                'id',
                'product_seo_idea_id',
                'title',
                'status',
                'error_message',
                'publish_status',
                'wp_sync_status',
                'publish_error',
                'wp_permalink',
                'shopify_url',
                'shopify_article_id',
                'word_count',
                'completed_at',
                'published_at',
                'created_at',
            ]);

        $isPublishFailed = static fn (ProductArticle $a): bool => $a->publish_status === 'failed'
            || $a->wp_sync_status === 'failed';

        $data = $articles->map(function (ProductArticle $article) use ($isPublishFailed): array {
            $row = $article->toArray();

            // پیام خطا روی خود مقاله ذخیره می‌شود (WordPress and Shopify)
            if ($isPublishFailed($article)) {
                $row['publish_error'] = $article->publish_error ?: 'No error reason was recorded.';
            } else {
                $row['publish_error'] = null;
            }

            $row['publish_error_at'] = null;

            return $row;
        });

        return response()->json(['data' => $data]);
    }

    public function generate(
        Request $request,
        Site $site,
        ProductSeoIdea $idea,
        SubscriptionService $subscriptionService
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($idea->site_id !== $site->id, 403, 'Forbidden.');

        $validated = $request->validate([
            'article_user_prompt' => ['nullable', 'string', 'max:2000'],
            'min_sections' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_sections' => ['nullable', 'integer', 'min:1', 'max:50'],
            'model' => ['nullable', 'string'],
        ]);

        if (
            isset($validated['min_sections'], $validated['max_sections'])
            && $validated['min_sections'] > $validated['max_sections']
        ) {
            return response()->json([
                'message' => 'The minimum number of sections can\'t exceed the maximum.',
                'errors' => [
                    'min_sections' => ['The minimum number of sections can\'t exceed the maximum.'],
                ],
            ], 422);
        }

        // اگر برای این ایده مقاله‌ی غیرناموفق هست، همان را برگردان (حتی اگر کریدیت تمام شده باشد)
        if ($existing = $this->activeArticleFor($idea)) {
            return $this->alreadyExistsResponse($existing);
        }

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json(['message' => self::NO_CREDIT_MESSAGE], 402);
        }

        $isProUser = $subscriptionService->hasActivePaidPlan($request->user());
        $resolvedModel = ChatModelResolver::resolve($validated['model'] ?? null, $isProUser);
        $articleUserPrompt = $this->sanitizeText($validated['article_user_prompt'] ?? null, 2000);

        // قفل ردیف ایده تا دو درخواست هم‌زمان (دابل‌کلیک) دو مقاله نسازند
        [$article, $created] = DB::transaction(function () use ($idea, $site, $resolvedModel, $articleUserPrompt, $validated) {
            ProductSeoIdea::whereKey($idea->id)->lockForUpdate()->first();

            if ($existing = $this->activeArticleFor($idea)) {
                return [$existing, false];
            }

            return [ProductArticle::create([
                'product_seo_idea_id' => $idea->id,
                'site_id' => $site->id,
                'title' => $idea->title,
                'content_style' => $idea->content_style,
                'categories' => $idea->categories,
                'product_ids' => $idea->product_ids,
                'status' => ProductArticle::STATUS_PENDING,
                'selected_model' => $resolvedModel,
                'article_user_prompt' => $articleUserPrompt,
                'min_sections' => $validated['min_sections'] ?? null,
                'max_sections' => $validated['max_sections'] ?? null,
            ]), true];
        });

        if (! $created) {
            return $this->alreadyExistsResponse($article);
        }

        // بعد از commit؛ تا Job هرگز مقاله‌ی ذخیره‌نشده را نبیند
        StartProductArticleGenerationJob::dispatch($article);

        return response()->json([
            'message' => 'Product article generation started.',
            'started' => true,
            'article_id' => $article->id,
            'model' => $resolvedModel,
            'article_user_prompt' => $articleUserPrompt,
            'min_sections' => $validated['min_sections'] ?? null,
            'max_sections' => $validated['max_sections'] ?? null,
        ], 202);
    }

    /** مقاله‌ی غیرناموفقِ این ایده (در حال تولید یا تولیدشده)، اگر وجود داشته باشد. */
    private function activeArticleFor(ProductSeoIdea $idea): ?ProductArticle
    {
        return ProductArticle::where('product_seo_idea_id', $idea->id)
            ->whereNotIn('status', [ProductArticle::STATUS_FAILED])
            ->first();
    }

    private function alreadyExistsResponse(ProductArticle $article): JsonResponse
    {
        return response()->json([
            'message' => 'An article for this idea is already being generated or done.',
            'started' => false,
            'article_id' => $article->id,
        ], 200);
    }

    public function show(Request $request, Site $site, ProductArticle $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');

        return response()->json([
            'data' => $this->transformArticleForResponse($article),
        ]);
    }

    public function retry(Request $request, Site $site, ProductArticle $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');

        if (! in_array($article->status, [
            ProductArticle::STATUS_FAILED,
            ProductArticle::STATUS_PAUSED_CREDIT,
        ], true)) {
            return response()->json([
                'message' => 'Only failed articles or ones paused for low credit can be retried.',
            ], 422);
        }

        $article->update([
            'status' => ProductArticle::STATUS_PENDING,
            'error_message' => null,
        ]);

        StartProductArticleGenerationJob::dispatch($article);

        return response()->json(['message' => 'Retrying article generation.'], 202);
    }

    public function regenerateSection(
        Request $request,
        Site $site,
        ProductArticle $article,
        ProductArticleSection $section,
        SubscriptionService $subscriptionService
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');
        abort_if($section->product_article_id !== $article->id, 403, 'Forbidden.');

        if (! in_array($article->status, [
            ProductArticle::STATUS_COMPLETED,
            ProductArticle::STATUS_FAILED,
        ], true)) {
            return response()->json(['message' => 'The article is still being generated.'], 422);
        }

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json(['message' => self::NO_CREDIT_MESSAGE], 402);
        }

        $section->update([
            'status' => ProductArticleSection::STATUS_PENDING,
            'content' => null,
            'error_message' => null,
        ]);

        $article->update([
            'status' => ProductArticle::STATUS_GENERATING_SECTIONS,
            'error_message' => null,
        ]);

        Bus::chain([
            new GenerateProductArticleSectionJob($section, $article->selected_model),
            new FinalizeProductArticleJob($article),
        ])->dispatch();

        return response()->json([
            'message' => "Regenerating section \"{$section->heading}\".",
        ], 202);
    }

    /* ------------------------------------------------------------------ */
    /*  ویرایش دستی                                                         */
    /* ------------------------------------------------------------------ */

    public function updateField(Request $request, Site $site, ProductArticle $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');

        $data = $request->validate([
            'target_type' => ['required', Rule::in([
                'title', 'meta_title', 'meta_description', 'excerpt', 'slug', 'focus_keyword',
            ])],
            'value' => ['required', 'string'],
        ]);

        $article->update([$data['target_type'] => $data['value']]);

        return response()->json([
            'target' => [
                'type' => $data['target_type'],
                'id' => null,
                'value' => $data['value'],
            ],
        ]);
    }

    public function updateSection(
        Request $request,
        Site $site,
        ProductArticle $article,
        ProductArticleSection $section
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');
        abort_if($section->product_article_id !== $article->id, 403, 'Forbidden.');

        $data = $request->validate([
            'heading' => ['sometimes', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $section->update(array_filter([
            'heading' => $data['heading'] ?? null,
            'content' => $data['content'],
        ], fn ($v) => $v !== null));

        return response()->json([
            'target' => [
                'type' => 'section',
                'id' => $section->id,
                'heading' => $section->heading,
                'value' => $section->content,
            ],
        ]);
    }

    public function updateFaq(
        Request $request,
        Site $site,
        ProductArticle $article,
        int $index
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
        ]);

        $faq = $article->faq_schema ?? [];
        if (is_string($faq)) {
            $faq = json_decode($faq, true) ?? [];
        }

        if (! array_key_exists($index, $faq)) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $faq[$index] = array_merge($faq[$index], [
            'question' => $data['question'],
            'answer' => $data['answer'],
        ]);

        $article->update(['faq_schema' => array_values($faq)]);

        return response()->json([
            'target' => [
                'type' => 'faq',
                'id' => $index,
                'question' => $data['question'],
                'answer' => $data['answer'],
            ],
        ]);
    }

    public function destroy(Request $request, Site $site, ProductArticle $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->site_id !== $site->id, 403, 'Forbidden.');

        $busy = [
            ProductArticle::STATUS_CREATING_BRIEF,
            ProductArticle::STATUS_CREATING_ASSIGNMENTS,
            ProductArticle::STATUS_GENERATING_SECTIONS,
            ProductArticle::STATUS_GENERATING_META,
        ];

        if (in_array($article->status, $busy, true)) {
            return response()->json([
                'message' => 'The article is being generated and can\'t be deleted.',
            ], 422);
        }

        DB::transaction(function () use ($article) {
            $article->sections()->delete();
            $article->assignments()->delete();
            $article->delete();
        });

        return response()->json(['message' => 'Article deleted.']);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                             */
    /* ------------------------------------------------------------------ */

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    /**
     * منبع واحد شکل‌دهی خروجی مقاله برای فرانت.
     * محصولات یک‌بار برای کل مقاله لود می‌شوند (بدون N+1) و به هر سکشن پیوند می‌خورند.
     */
    private function transformArticleForResponse(ProductArticle $article): array
    {
        $article->load(['sections', 'idea:id,token,title,content_style']);

        $data = $article->toArray();

        $faq = $article->faq_schema ?? [];
        if (is_string($faq)) {
            $faq = json_decode($faq, true) ?? [];
        }
        $data['faq_schema'] = $faq;

        $articleIds = $this->normalizeIds($article->product_ids);

        $allIds = $article->sections
            ->flatMap(fn ($s) => $this->normalizeIds($s->product_ids))
            ->merge($articleIds)
            ->unique()
            ->values();

        $productsById = $this->loadProducts((int) $article->site_id, $allIds);

        $data['products'] = $this->pickProducts($productsById, $articleIds);

        $data['sections'] = $article->sections->values()->map(function ($section) use ($productsById) {
            $row = $section->toArray();
            $row['products'] = $this->pickProducts($productsById, $this->normalizeIds($section->product_ids));

            return $row;
        })->all();

        return $data;
    }

    /** ورودی می‌تواند آرایه، JSON-string یا null باشد. */
    private function normalizeIds(array|string|null $ids): array
    {
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?? [];
        }

        return collect($ids ?? [])
            ->map(fn ($id) => is_array($id) ? ($id['id'] ?? null) : $id)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, array> کلید = id محصول */
    private function loadProducts(int $siteId, Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return collect();
        }

        $products = SiteProduct::query()
            ->where('site_id', $siteId)
            // اگر product_ids شناسه‌ی ووکامرس است: ->whereIn('wc_product_id', ...) و در mapWithKeys از $p->wc_product_id استفاده کن
            ->whereIn('id', $ids->all())
            ->get();

        $catIds = $products
            ->map(fn ($p) => (int) $p->getAttribute('category_id'))
            ->filter()
            ->unique()
            ->values();

        $cats = $catIds->isEmpty()
            ? collect()
            : SiteProductCategory::where('site_id', $siteId)->whereIn('id', $catIds)->pluck('name', 'id');

        return $products->mapWithKeys(fn ($p) => [(int) $p->id => $this->formatProduct($p, $cats)]);
    }

    private function pickProducts(Collection $productsById, array $ids): array
    {
        return collect($ids)
            ->map(fn ($id) => $productsById->get($id))
            ->filter()
            ->values()
            ->all();
    }

    /** شکل یکدست محصول برای فرانت؛ فقط داده‌ی واقعی دیتابیس، بدون ساختن چیزی از خودمان. */
    private function formatProduct($p, Collection $cats): array
    {
        $get = function (string ...$keys) use ($p) {
            foreach ($keys as $k) {
                $v = $p->getAttribute($k);
                if ($v !== null && $v !== '' && $v !== []) {
                    return $v;
                }
            }

            return null;
        };

        // ---- تصویر ----
        $image = $get('image', 'image_url', 'thumbnail', 'featured_image');

        if (! $image) {
            $images = $get('images');
            if (is_string($images)) {
                $images = json_decode($images, true);
            }
            if (is_array($images) && $images) {
                $first = $images[0];
                $image = is_array($first) ? ($first['src'] ?? $first['url'] ?? null) : $first;
            }
        }

        // ---- قیمت و تخفیف ----
        $num = fn ($v) => (float) preg_replace('/[^\d.]/', '', (string) $v);
        $price = $get('price', 'regular_price');
        $regular = $get('regular_price');
        $regular = ($regular !== null && $num($regular) > $num($price)) ? $regular : null; // فقط اگر واقعاً گران‌تر بود

        // ---- موجودی (فقط اگر دیتابیس صریحاً بگوید) ----
        $stock = $get('stock_status', 'in_stock');
        $inStock = match (true) {
            is_bool($stock) => $stock,
            $stock === 'instock', $stock === 1, $stock === '1' => true,
            $stock === 'outofstock', $stock === 0, $stock === '0' => false,
            default => null,
        };

        $raw = (string) ($get('short_description', 'description') ?? '');
        $brand = $get('brand');

        return [
            'id' => (int) $p->id,
            'name' => $get('name', 'title'),
            'price' => $price,
            'regular_price' => $regular,
            // IRR یا IRT؛ فرانت واحد را از همین می‌نویسد
            'currency' => $p->getAttribute('currency'),
            'image' => $image,
            'url' => $get('permalink', 'url', 'link'),
            'category' => $cats->get((int) $p->getAttribute('category_id')),
            'brand' => is_string($brand) ? $this->cleanSpec($brand) : null,
            'in_stock' => $inStock,
            'excerpt' => $this->plain($raw, 170) ?: null,
            'specs' => $this->extractSpecs($get('attributes', 'specs', 'specifications'), $raw, $brand),
        ];
    }

    private function plain(string $html, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

        return Str::limit($text, $limit);
    }

    private function cleanSpec(mixed $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    /**
     * مشخصات واقعی محصول: اول attributes (فرمت ووکامرس یا key=>value)،
     * اگر نبود خطوط «عنوان: مقدار» داخل توضیحات؛ حداکثر ۶ مورد.
     *
     * @return array<int, array{label:string,value:string}>
     */
    private function extractSpecs(mixed $attrs, string $rawDescription, mixed $brand): array
    {
        $specs = [];

        if (is_string($attrs)) {
            $attrs = json_decode($attrs, true);
        }

        if (is_array($attrs)) {
            foreach ($attrs as $k => $a) {
                if (is_array($a)) {
                    if (($a['visible'] ?? true) === false) {
                        continue;
                    }
                    $label = $a['name'] ?? $a['label'] ?? $a['key'] ?? null;
                    $value = $a['options'] ?? $a['value'] ?? $a['values'] ?? null;
                } else {
                    $label = is_string($k) ? $k : null;
                    $value = $a;
                }

                if (is_array($value)) {
                    $value = implode('، ', array_filter(array_map(
                        fn ($v) => is_array($v) ? ($v['name'] ?? null) : $v,
                        $value
                    )));
                }

                $label = $this->cleanSpec($label);
                $value = $this->cleanSpec($value);

                if ($label !== '' && $value !== '' && mb_strlen($label) <= 30 && mb_strlen($value) <= 80) {
                    $specs[] = ['label' => $label, 'value' => $value];
                }
            }
        }

        if (! $specs && $rawDescription !== '') {
            $text = preg_replace('#</(li|p|tr|div)>|<br\s*/?>#i', "\n", $rawDescription);
            $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach (preg_split('/\R/u', $text) as $line) {
                if (preg_match('/^\s*[-•*]?\s*([^:：]{2,25})\s*[:：]\s*(.{1,80})$/u', trim($line), $m)) {
                    $specs[] = ['label' => $this->cleanSpec($m[1]), 'value' => $this->cleanSpec($m[2])];
                }
            }
        }

        $brand = is_string($brand) ? $this->cleanSpec($brand) : '';
        if ($brand !== '' && ! collect($specs)->contains(fn ($s) => $s['label'] === 'Brand')) {
            array_unshift($specs, ['label' => 'Brand', 'value' => $brand]);
        }

        return collect($specs)->unique('label')->take(6)->values()->all();
    }

    protected function sanitizeText(?string $value, int $max): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
        $value = str_replace(['<', '>'], '', $value);
        $value = mb_substr($value, 0, $max);

        return $value !== '' ? $value : null;
    }
}
