<?php
// app/Http/Controllers/Api/ArticleController.php

namespace App\Http\Controllers\Api;

use App\Enums\ImageStyle;
use App\Exceptions\ImageModelNotAvailableException;
use App\Exceptions\ImageModelRequiresProException;
use App\Http\Controllers\Controller;
use App\Jobs\FinalizeArticleJob;
use App\Jobs\GenerateArticleBannerJob;
use App\Jobs\GenerateArticleSectionJob;
use App\Jobs\StartArticleGenerationJob;
use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\ArticleSection;
use App\Models\Site;
use App\Services\AI\ArticleWriterService;
use App\Services\AI\ChatModelResolver;
use App\Services\ArticleTargetResolver;
use App\Services\CloudflareImageService;
use App\Services\Image\ImageFileName;
use App\Services\Image\ImageGenerationService;
use App\Services\Image\ImageModelResolver;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class ArticleController extends Controller
{
    /**
     * لیست مقالات یک سایت.
     *
     * برای مقالاتی که publish_status آن‌ها failed است، آخرین لاگ واقعیِ
     * action=publish از جدول article_publish_logs خوانده می‌شود تا دلیل واقعی
     * شکست (مثلاً "سایت وردپرسی متصل نیست.") به‌جای صرفاً برچسب "failed"
     * در اختیار فرانت/کاربر قرار بگیرد.
     *
     * action=delete عمداً نادیده گرفته می‌شود چون به دلیل شکست انتشار ربطی ندارد
     * (مربوط به زمانی است که یک مقاله‌ی قبلاً منتشرشده بعداً از وردپرس حذف شده).
     */
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $query = Article::where('website_id', $site->id)
            ->with('idea:id,token,title,entity_name')
            ->latest();

        // فیلتر source اختیاری شد؛ اگه پارامتر source توی request بیاد اعمال میشه
        // در غیر این صورت (حالت عادی) همه مقالات صرف‌نظر از source برمی‌گردن
        if ($request->filled('source')) {
            $query->where('source', $request->query('source'));
        }

        $articles = $query->get([
            'id',
            'token',
            'article_idea_id',
            'title',
            'status',
            'publish_status',
            'wp_permalink',
            'seo_score',
            'word_count',
            'completed_at',
            'published_at',
            'created_at',
        ]);

        $failedArticleIds = $articles->where('publish_status', 'failed')->pluck('id');

        $latestPublishErrors = collect();
        if ($failedArticleIds->isNotEmpty()) {
            $latestPublishErrors = DB::table('article_publish_logs')
                ->whereIn('article_id', $failedArticleIds)
                ->where('action', 'publish')
                ->orderByDesc('created_at')
                ->get(['article_id', 'status', 'error_message', 'created_at'])
                ->unique('article_id')
                ->keyBy('article_id');
        }

        $articles = $articles->map(function (Article $article) use ($latestPublishErrors): array {
            $data = $article->toArray();

            if ($article->publish_status === 'failed') {
                $log = $latestPublishErrors->get($article->id);
                $data['publish_error'] = $log->error_message ?? 'No error reason was recorded.';
                $data['publish_error_at'] = $log->created_at ?? null;
            } else {
                $data['publish_error'] = null;
                $data['publish_error_at'] = null;
            }

            return $data;
        });

        return response()->json(['data' => $articles]);
    }

    /**
     * منبعِ واحدِ شکل‌دهیِ خروجیِ مقاله برای فرانت.
     *
     * هر endpointـی که مقاله را در پاسخِ خودش برمی‌گرداند (show، regenerateImage،
     * regenerateGalleryImage، و هر endpoint آینده‌ای) باید از همینجا استفاده کند،
     * نه مستقیم از $article->fresh() یا $article->toArray(). دلیلش این است که
     * فرانت به چند فیلدِ محاسبه‌شده وابسته است که در مدلِ خامِ Eloquent وجود
     * ندارند:
     *   - sections[].images_after   (گروه‌بندیِ گالری بر اساسِ position سکشن)
     *   - sections[].images_after[].gallery_index (ایندکسِ اصلی در image_gallery)
     *   - banner                    (اولین آیتمِ رابطه‌ی banners)
     *
     * قبلاً این منطق فقط در show() بود؛ regenerateImage/regenerateGalleryImage
     * صرفاً $article->fresh() را برمی‌گرداندند که فاقدِ این فیلدها بود، و همین
     * باعث می‌شد بعد از تولید دوباره‌ی تصویر، فرانت تا رفرشِ دستیِ صفحه (که
     * show() را دوباره صدا می‌زد) این فیلدها را نداشته باشد.
     */
    private function transformArticleForResponse(Article $article): array
    {
        // مهم: هیچ orderBy دستی‌ای اینجا نمی‌ذاریم؛ ترتیب توسط خودِ متد
        // sections() روی مدل Article تعیین می‌شه (orderBy('position'))،
        // که دقیقاً همون ترتیبیه که FinalizeArticleJob هنگام محاسبه‌ی
        // "after_section" استفاده کرده — این هماهنگی نباید در هیچ جای
        // دیگه‌ای (کنترلر، export، ادمین و ...) به‌صورت دستی تکرار بشه.
        $article->load(['sections', 'banners']);

        $data = $article->toArray();
        $data['banner'] = $article->banners->first();
        unset($data['banners']); // دیگه لازم نیست آرایه رو هم بفرستیم

        // گالری تصاویر (به‌جز تصویر شاخص) رو بر اساس "after_section" گروه‌بندی
        // می‌کنیم تا فرانت مجبور نباشه خودش محاسبه کنه بعد از کدوم سکشن باید
        // چه عکسی رو نشون بده. "after_section" همون position واقعیِ سکشنه
        // (نه index آرایه)، پس دیگه هیچ تبدیل ۰-بیس/۱-بیسی لازم نیست.
        //
        // دفاعی: اگه به هر دلیلی (مثلاً نبودِ cast مناسب روی مدل، یا یه
        // ذخیره‌ی دستیِ اشتباه) هنوز رشته‌ی JSON خامه، دیکدش می‌کنیم.
        $rawGallery = $article->image_gallery ?? [];
        if (is_string($rawGallery)) {
            $rawGallery = json_decode($rawGallery, true) ?? [];
        }

        // gallery_index رو همین‌جا به هر آیتم می‌چسبونیم تا فرانت هنگام
        // درخواستِ regenerate دقیقاً بدونه کدوم ایندکس از آرایه‌ی اصلیِ
        // image_gallery رو باید بفرسته (چون بعد از گروه‌بندی بر اساس
        // after_section، ایندکسِ اصلی گم می‌شه).
        $galleryBySection = collect($rawGallery)
            ->values()
            ->map(function ($item, $idx) {
                $item['gallery_index'] = $idx;

                return $item;
            })
            ->filter(fn ($item) => is_array($item) && array_key_exists('after_section', $item) && $item['after_section'] !== null)
            ->groupBy('after_section');

        $data['sections'] = $article->sections->values()->map(function ($section) use ($galleryBySection) {
            $sectionArray = $section->toArray();

            // آرایه‌ی تصاویری که باید بلافاصله بعد از این سکشن نمایش داده بشن
            // (معمولاً خالی یا حداکثر یک آیتم؛ به‌ندرت بیشتر اگه جایگاه‌ها recycle شده باشن).
            $sectionArray['images_after'] = $galleryBySection->get($section->position, collect())->values();

            return $sectionArray;
        });

        return $data;
    }

    private function generateArticleImage(
        ImageGenerationService $imageService,
        $user,
        string $modelId,
        string $prompt,
        bool $userIsPro,
        Article $article,
        string $context,
        ?string $fileName = null,
    ): array {
        try {
            $result = $imageService->generate($user, $modelId, $prompt, $userIsPro, fileName: $fileName);

            return ['ok' => true, 'path' => $result['path'], 'url' => $result['url']];
        } catch (ImageModelRequiresProException|ImageModelNotAvailableException $e) {
            Log::warning('Image regenerate: model access denied', [
                'article_id' => $article->id,
                'context' => $context,
                'model' => $modelId,
                'message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage(), 'status' => 422];
        } catch (Throwable $e) {
            Log::error('Image regenerate failed', [
                'article_id' => $article->id,
                'context' => $context,
                'model' => $modelId,
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['ok' => false, 'message' => 'Image generation failed.', 'status' => 422];
        }
    }

      public function regenerateImage(
        Request $request,
        Site $site,
        Article $article,
        ImageGenerationService $imageService,
        SubscriptionService $subscriptionService,
        ArticleWriterService $writer
    ) {
        abort_if($article->website_id !== $site->id, 404);

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json([
                'message' => 'You don\'t have an active plan or enough credit. Please renew your plan.',
            ], 402);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'model' => ['nullable', 'string', Rule::in(array_keys(config('image_models')))],
        ]);

        // پرامپت کاربر با AI بازنویسی می‌شود تا تصویر حتماً به عنوان مقاله مربوط باشد.
        app()->bind('current_ai_user', fn () => $request->user());
        $prompt = $writer->rewriteUserImagePrompt($article, trim($validated['prompt']));
        // پیش‌فرض: همان مدلی که مقاله با آن ساخته شده، نه flux ثابت.
        $modelId = $validated['model'] ?? $article->image_model ?: 'flux';
        $userIsPro = $subscriptionService->hasActivePaidPlan($request->user());

        // Claim اتمیک: اگر job یا request دیگری در حال تولید است، رد کن.
        // اگر بیش از ۶ دقیقه از آخرین آپدیت گذشته، فرض می‌کنیم گیر کرده و اجازه می‌دهیم.
        $claimed = Article::query()
            ->whereKey($article->id)
            ->where(function ($q) {
                $q->whereNull('image_status')
                    ->orWhere('image_status', '!=', 'generating')
                    ->orWhere('updated_at', '<', now()->subMinutes(6));
            })
            ->update([
                'image_status' => 'generating',
                'image_prompt' => $prompt,
                'image_error' => null,
            ]);

        if ($claimed === 0) {
            return response()->json([
                'message' => 'The image is being generated. Please wait a moment.',
            ], 409);
        }

        $article->refresh();

        try {
            $result = $this->generateArticleImage(
                $imageService, $request->user(), $modelId, $prompt, $userIsPro, $article, 'featured',
                // همان نام سئو-فرندلی قبلی حفظ می‌شود؛ مقاله‌های قدیمی (img_...) از slug نام می‌گیرند.
                ImageFileName::fromStoredPath($article->image_path) ?? ImageFileName::from($article->slug),
            );
        } catch (Throwable $e) {
            report($e);

            $result = [
                'ok' => false,
                'message' => 'Unexpected error while generating the image.',
                'status' => 500,
            ];
        }

        if (! $result['ok']) {
            $article->update(['image_status' => 'failed', 'image_error' => $result['message']]);

            return response()->json([
                'message' => $result['message'],
                'article' => $this->transformArticleForResponse($article->fresh()),
            ], $result['status']);
        }

        $article->update([
            'image_path' => $result['path'],
            'image_url' => $result['url'],
            'image_status' => 'generated',
            'image_error' => null,
        ]);

        return response()->json([
            'message' => 'Image regenerated.',
            'article' => $this->transformArticleForResponse($article->fresh()),
        ]);
    }

    public function regenerateGalleryImage(
        Request $request,
        Site $site,
        Article $article,
        ImageGenerationService $imageService,
        SubscriptionService $subscriptionService,
        ArticleWriterService $writer
    ) {
        abort_if($article->website_id !== $site->id, 404);

        if (! $subscriptionService->hasAvailableCredit($request->user())) {
            return response()->json([
                'message' => 'You don\'t have an active plan or enough credit. Please renew your plan.',
            ], 402);
        }

        $validated = $request->validate([
            // جایگاه آیتم در آرایه‌ی image_gallery (نه فیلد order)
            'index' => ['required', 'integer', 'min:0'],
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'model' => ['nullable', 'string', Rule::in(array_keys(config('image_models')))],
        ]);

        $index = (int) $validated['index'];
        $afterSection = data_get($article->image_gallery, "$index.after_section");
        $sectionHeading = $afterSection !== null
            ? $article->sections()->where('position', $afterSection)->value('heading')
            : null;
        // پرامپت کاربر با AI بازنویسی می‌شود تا تصویر حتماً به عنوان مقاله (و سکشن خودش) مربوط باشد.
        app()->bind('current_ai_user', fn () => $request->user());
        $prompt = $writer->rewriteUserImagePrompt($article, trim($validated['prompt']), sectionHeading: (string) $sectionHeading);
        $modelId = $validated['model'] ?? $article->image_model ?: 'flux';
        $userIsPro = $subscriptionService->hasActivePaidPlan($request->user());

        // Claim اتمیک روی همین آیتم (با قفل ردیف)، تا دابل‌کلیک هزینه‌ی دوبار نسازد.
        $claim = DB::transaction(function () use ($article, $index, $prompt) {
            $fresh = Article::query()->whereKey($article->id)->lockForUpdate()->first();
            $gallery = $fresh->image_gallery ?? [];

            if (! array_key_exists($index, $gallery)) {
                return 'not_found';
            }

            $item = $gallery[$index];
            $startedAt = ! empty($item['started_at']) ? \Illuminate\Support\Carbon::parse($item['started_at']) : null;

            if (($item['status'] ?? null) === 'generating' && $startedAt && $startedAt->gt(now()->subMinutes(3))) {
                return 'busy';
            }

            $gallery[$index]['status'] = 'generating';
            $gallery[$index]['prompt'] = $prompt;
            $gallery[$index]['error'] = null;
            $gallery[$index]['started_at'] = now()->toIso8601String();

            $fresh->update(['image_gallery' => array_values($gallery)]);

            return 'ok';
        });

        if ($claim === 'not_found') {
            return response()->json(['message' => 'Gallery image not found.'], 404);
        }

        if ($claim === 'busy') {
            return response()->json(['message' => 'This image is being generated. Please wait a moment.'], 409);
        }

        try {
            $result = $this->generateArticleImage(
                $imageService, $request->user(), $modelId, $prompt, $userIsPro, $article, "gallery.$index",
                $this->galleryFileName($article, $index),
            );
        } catch (Throwable $e) {
            report($e);

            $result = [
                'ok' => false,
                'message' => 'Unexpected error while generating the image.',
                'status' => 500,
            ];
        }

        if (! $result['ok']) {
            $this->patchGalleryItem($article, $index, [
                'status' => 'failed',
                'error' => $result['message'],
                'started_at' => null,
            ]);

            return response()->json([
                'message' => $result['message'],
                'article' => $this->transformArticleForResponse($article->fresh()),
            ], $result['status']);
        }

        $this->patchGalleryItem($article, $index, [
            'url' => $result['url'],
            'path' => $result['path'],
            'status' => 'generated',
            'error' => null,
            'started_at' => null,
        ]);

        return response()->json([
            'message' => 'Gallery image regenerated.',
            'article' => $this->transformArticleForResponse($article->fresh()),
        ]);
    }


public function generate(
    Request $request,
    Site $site,
    ArticleIdea $idea,
    SubscriptionService $subscriptionService
): JsonResponse {
    $this->authorizeSiteAccess($request, $site);

    abort_if($idea->website_id !== $site->id, 403, 'Forbidden.');

    // ── prompt اختیاری کاربر برای تصویر شاخص و متن مقاله + بازه‌ی تعداد بخش (جدید) ──
    $validated = $request->validate([
        'image_user_prompt'   => ['nullable', 'string', 'max:500'],
        'article_user_prompt' => ['nullable', 'string', 'max:2000'],
        'min_sections'        => ['nullable', 'integer', 'min:1', 'max:50'],
        'max_sections'        => ['nullable', 'integer', 'min:1', 'max:50'],
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

    $imageUserPrompt = $this->normalizeImageUserPrompt($validated['image_user_prompt'] ?? null);
    $articleUserPrompt = $this->normalizeImageUserPrompt($validated['article_user_prompt'] ?? null); // متد مشترک پاک‌سازی، همون منطق قبلی
    $minSections = $validated['min_sections'] ?? null;
    $maxSections = $validated['max_sections'] ?? null;

    if (! $subscriptionService->hasAvailableCredit($request->user())) {
        return response()->json([
            'message' => 'You don\'t have an active plan or enough credit. Please renew your plan.',
        ], 402);
    }

    $existing = Article::where('article_idea_id', $idea->id)
        ->whereNotIn('status', [Article::STATUS_FAILED])
        ->first();

    if ($existing) {
        return response()->json([
            'message' => 'An article for this idea is already being generated or done.',
            'started' => false,
            'article_id' => $existing->token,
        ], 200);
    }

    $isProUser = $subscriptionService->hasActivePaidPlan($request->user());

    $requestedModelId = $request->input('model');
    $resolvedApiModel = ChatModelResolver::resolve($requestedModelId, $isProUser);

    $requestedImageModelId = $request->input('image_model');
    $requestedImageCount = $request->filled('image_count') ? (int) $request->input('image_count') : null;

    [$resolvedImageModel, $resolvedImageCount] = ImageModelResolver::resolve(
        $requestedImageModelId,
        $requestedImageCount,
        $isProUser,
    );

    // ── سبک تصویر — تایپ‌شده با enum، ورودی نامعتبر بی‌صدا به دیفالت فال‌بک می‌کند
    $resolvedImageStyle = ImageStyle::resolve($request->input('image_style'));

    $taxonomy = [
        'category_id'   => $idea->category['id']   ?? null,
        'category_name' => $idea->category['name'] ?? null,
        'tag_ids'       => collect($idea->tags ?? [])->pluck('id')->filter()->values()->all(),
        'new_tags'      => collect($idea->tags ?? [])->whereNull('id')->pluck('name')->values()->all(),
    ];

    $article = Article::create([
        'article_idea_id'    => $idea->id,
        'website_id'         => $site->id,
        'title'              => $idea->title,
        'meta_description'   => $idea->meta_description,
        'status'             => Article::STATUS_PENDING,
        'selected_model'     => $resolvedApiModel,
        'image_model'        => $resolvedImageModel,
        'image_count'        => $resolvedImageCount,
        'image_style'        => $resolvedImageStyle->value,
        'image_user_prompt'  => $imageUserPrompt,
        'article_user_prompt'=> $articleUserPrompt, // ← جدید
        'min_sections'       => $minSections,        // ← جدید
        'max_sections'       => $maxSections,         // ← جدید
        'wp_category_id'     => $taxonomy['category_id'],
        'wp_category_name'   => $taxonomy['category_name'],
        'wp_tag_ids'         => $taxonomy['tag_ids'],
        'wp_new_tags'        => $taxonomy['new_tags'],
    ]);

    StartArticleGenerationJob::dispatch($article);

    return response()->json([
        'message' => 'Article generation started.',
        'started' => true,
        'article_id' => $article->token,
        'model' => $resolvedApiModel,
        'image_model' => $resolvedImageModel,
        'image_count' => $resolvedImageCount,
        'image_style' => $resolvedImageStyle->value,
        'article_user_prompt' => $articleUserPrompt, // ← جدید، برای نمایش/تأیید در فرانت
        'min_sections' => $minSections,               // ← جدید
        'max_sections' => $maxSections,                // ← جدید
    ], 202);
}
    /**
     * فقط یک آیتم گالری را آپدیت می‌کند؛ گالری را با قفل ردیف تازه می‌خواند
     * تا با آپدیت‌های هم‌زمان (job یا regenerate دیگر) overwrite نشود.
     */
    protected function patchGalleryItem(Article $article, int $index, array $changes): void
    {
        DB::transaction(function () use ($article, $index, $changes) {
            $fresh = Article::query()->whereKey($article->id)->lockForUpdate()->first();
            $gallery = $fresh->image_gallery ?? [];

            if (! array_key_exists($index, $gallery)) {
                return;
            }

            $gallery[$index] = array_merge($gallery[$index], $changes);

            $fresh->update(['image_gallery' => array_values($gallery)]);
        });
    }

    /**
     * نام فایل تصویر گالری هنگام تولید مجدد: نام AI ذخیره‌شده، یا نام فایل فعلی، یا slug مقاله + شماره.
     */
    protected function galleryFileName(Article $article, int $index): ?string
    {
        $item = ($article->image_gallery ?? [])[$index] ?? [];

        return ImageFileName::from($item['file_name'] ?? null)
            ?? ImageFileName::fromStoredPath($item['path'] ?? null)
            ?? (($slug = ImageFileName::from($article->slug)) ? "{$slug}-".($index + 1) : null);
    }

    /*
     * ایجاد یا ادامه‌ی تولید مقاله برای یک ایده.
     *
     * این متد idempotent است: اگر برای همین ایده قبلاً مقاله‌ای
     * (در حال تولید یا تکمیل‌شده) وجود داشته باشد، به‌جای خطا،
     * همان article_id را با پرچم started=false برمی‌گرداند تا
     * فرانت‌اند بتواند بدون نمایش خطا همان مقاله را باز کند.
     * این حالت معمولاً وقتی رخ می‌دهد که کلاینت (مثلاً به‌خاطر
     * React StrictMode یا کلیک دوباره‌ی کاربر) درخواست را دوبار بفرستد.
     */
/**
 * prompt تصویر شاخص ورودی نامطمئن است: تگ، خط جدید و فاصله‌ی اضافه حذف می‌شود.
 * اگر بعد از پاک‌سازی خالی بماند null برمی‌گردد (یعنی رفتار قبلیِ بدون prompt کاربر).
 */
protected function normalizeImageUserPrompt(?string $value): ?string
{
    $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
    $value = str_replace(['<', '>'], '', $value);
    $value = mb_substr($value, 0, 500);

    return $value !== '' ? $value : null;
}

    public function show(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        return response()->json([
            'data' => $this->transformArticleForResponse($article),
        ]);
    }

    public function regenerateBanner(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        if (! in_array($article->status, ['completed', 'failed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Banners can only be regenerated for completed articles.',
            ], 422);
        }

        $article->update(['banner_status' => 'generating']);

        GenerateArticleBannerJob::dispatch($article, force: true);

        return response()->json([
            'success' => true,
            'message' => 'Regenerating the banner.',
        ]);
    }

    /**
     * ادامه‌ی تولید مقاله‌ی failed از همان‌جایی که متوقف شد
     * (brief/outline/بخش‌های موفق حفظ شده‌اند و دوباره تولید نمی‌شوند)
     */
    public function retry(Request $request, Site $site, Article $article): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        if (! in_array($article->status, [Article::STATUS_FAILED, Article::STATUS_PAUSED_CREDIT], true)) {
            return response()->json(['message' => 'Only failed articles or ones paused for low credit can be retried.'], 402);
        }

        $article->update(['status' => Article::STATUS_PENDING, 'error_message' => null]);

        StartArticleGenerationJob::dispatch($article);

        return response()->json(['message' => 'Retrying article generation.'], 202);
    }

    /**
     * بازتولید یک بخش خاص (مثلاً کاربر از یک بخش راضی نیست)
     * بعد از بازتولید، Combine و Review دوباره اجرا می‌شود.
     */
    public function regenerateSection(Request $request, Site $site, Article $article, ArticleSection $section): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');
        abort_if($section->article_id !== $article->id, 403, 'Forbidden.');

        if (! in_array($article->status, [Article::STATUS_COMPLETED, Article::STATUS_FAILED])) {
            return response()->json(['message' => 'The article is still being generated.'], 422);
        }

        $section->update(['status' => ArticleSection::STATUS_PENDING, 'content' => null]);
        $article->update(['status' => Article::STATUS_GENERATING_SECTIONS]);

        Bus::chain([
            new GenerateArticleSectionJob($section),
            new FinalizeArticleJob($article),
        ])->dispatch();

        return response()->json(['message' => "Regenerating section \"{$section->heading}\"."], 202);
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    /** ویرایش دستیِ فیلدهای متا/کلی مقاله: title, meta_title, meta_description, excerpt, general(content) */
    public function updateField(Request $request, Site $site, Article $article, ArticleTargetResolver $resolver): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        $data = $request->validate([
            'target_type' => ['required', Rule::in(['title', 'meta_title', 'meta_description', 'excerpt', 'general'])],
            'value' => ['required', 'string'],
        ]);

        $updated = $resolver->apply($article, $data['target_type'], null, $data['value']);

        return response()->json([
            'target' => array_merge(['type' => $data['target_type'], 'id' => null], $updated),
        ]);
    }

    /** ویرایش دستیِ یک بخش (section) مقاله */
    public function updateSection(
        Request $request,
        Site $site,
        Article $article,
        ArticleSection $section,
        ArticleTargetResolver $resolver,
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');
        abort_if($section->article_id !== $article->id, 403, 'Forbidden.');

        $data = $request->validate([
            'heading' => ['sometimes', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        if (isset($data['heading'])) {
            $section->update(['heading' => $data['heading']]);
        }

        $updated = $resolver->apply($article, 'section', $section->id, $data['content']);

        return response()->json([
            'target' => array_merge(['type' => 'section', 'id' => $section->token], $updated),
        ]);
    }

    /** ویرایش دستیِ یک آیتم FAQ (بر اساس ایندکس آرایه) */
    public function updateFaq(
        Request $request,
        Site $site,
        Article $article,
        int $index,
        ArticleTargetResolver $resolver,
    ): JsonResponse {
        $this->authorizeSiteAccess($request, $site);
        abort_if($article->website_id !== $site->id, 403, 'Forbidden.');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string'],
        ]);

        try {
            $updated = $resolver->apply($article, 'faq', $index, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'target' => array_merge(['type' => 'faq', 'id' => $index], $updated),
        ]);
    }
}
