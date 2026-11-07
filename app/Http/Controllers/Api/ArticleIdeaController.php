<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleIdeaResource;
use App\Jobs\FetchEntityKeywordsJob;
use App\Jobs\GenerateArticleIdeasJob;
use App\Jobs\ScoreArticleIdeasJob;
use App\Models\Site;
use App\Services\AI\EntitySelectionService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ArticleIdeaController extends Controller
{
    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $ideas = $site->articleIdeas()
            // article: برای article_id در Resource. relatedLinks: برای related_links
            // در Resource — بدون این‌ها whenLoaded() همیشه خالی برمی‌گردونه.
            ->with(['article', 'relatedLinks'])
            // ایده‌های امتیازدهی‌شده اول (بر اساس seo_score)، بعد ایده‌های هنوز امتیاز نگرفته بر اساس priority
            ->orderByRaw('seo_score IS NULL')
            ->orderByDesc('seo_score')
            ->orderByDesc('priority')
            ->get();

        return response()->json([
            'data' => ArticleIdeaResource::collection($ideas),
        ]);
    }


public function generate(
    Request $request,
    Site $site,
    SubscriptionService $subscriptionService,
    EntitySelectionService $entitySelectionService,
): JsonResponse {
    $this->authorizeSiteAccess($request, $site);

    $validated = $request->validate([
        'mode' => 'nullable|in:trend_opportunities,keyword_clusters,content_gap',
        'prompt' => 'nullable|string|max:800',
        'entity_name' => 'nullable|string|max:255',
        'entity_scope' => 'nullable|in:primary,core',
    ]);

    $mode = $validated['mode'] ?? 'trend_opportunities';
    $prompt = trim($validated['prompt'] ?? '');

    if (! $site->ensureEntity()) {
        return $this->siteNotReadyResponse($site);
    }

    $subscription = $subscriptionService->getActiveSubscription($request->user());

    if (! $subscription) {
        return response()->json([
            'message' => 'You don\'t have an active plan.',
        ], 402);
    }

    $isFreePlan = $subscription->plan->is_free;

    // محدودیت تولید قبل از هر فراخوانی مدل یا dispatch بررسی می‌شود.
    if ($isFreePlan && $site->idea_generation_batch >= 3) {
        return response()->json([
            'message' => 'The free plan allows up to 3 idea generations. Upgrade your plan to continue.',
        ], 402);
    }

    if (! $subscriptionService->hasAvailableCredit($request->user())) {
        return response()->json([
            'message' => 'You don\'t have enough credit left to generate ideas.',
        ], 402);
    }

    // انتخاب صریح، بدون فراخوانی مدل انجام می‌شود؛ در غیر این صورت فقط
    // resolver سبک gpt-5-nano برای تشخیص یا درخواست entity اجرا می‌شود.
    $selection = $entitySelectionService->resolve(
        site: $site,
        prompt: $prompt,
        requestedName: $validated['entity_name'] ?? null,
        requestedScope: $validated['entity_scope'] ?? null,
    );

    if (($selection['status'] ?? null) !== 'selected') {
        return response()->json([
            'code' => 'entity_required',
            'message' => $selection['message'] ?? 'Please specify the entity you mean.',
            'action' => 'select_entity',
            'entities' => $this->publicEntityOptions($selection['entities'] ?? []),
        ], 422);
    }

    $selectedEntity = $selection['entity'];
    $generationContext = $this->encodeGenerationContext($prompt, $selectedEntity);

    // یکتا با دسته‌های اتوماسیون؛ قبلاً counter+1 گاهی شماره‌ی یک دسته‌ی اتوماسیون را تکرار می‌کرد
    $nextBatch = $site->reserveIdeaBatch();

    $site->update([
        'idea_generation_status' => 'connecting_sources',
        'idea_generation_error'  => null,
        'idea_generation_mode'   => $mode,
        'idea_generation_prompt' => $generationContext,
    ]);

    FetchEntityKeywordsJob::dispatch(
        $site,
        $nextBatch,
        $mode,
        $prompt,
        $selectedEntity['name'],
        $selectedEntity['scope'],
    );

    return response()->json([
        'message' => 'Content analysis started and will be ready soon.',
        'batch'   => $nextBatch,
        'mode'    => $mode,
        'entity'  => [
            'name' => $selectedEntity['name'],
            'scope' => $selectedEntity['scope'],
            'type' => $selectedEntity['type'],
        ],
    ], 202);
}

public function retry(Request $request, Site $site, SubscriptionService $subscriptionService): JsonResponse
{
    $this->authorizeSiteAccess($request, $site);

    if (! $site->idea_generation_error) {
        return response()->json([
            'message' => 'There is no error to retry.',
        ], 422);
    }

    if (! $subscriptionService->hasAvailableCredit($request->user())) {
        return response()->json([
            'message' => 'You don\'t have enough credit left to retry.',
        ], 402);
    }

    $failedStep = $site->idea_generation_status;
    $batch      = $site->idea_generation_batch; // batch افزایش پیدا نمی‌کنه، تلاش مجدد رو همون batch هست
    $mode = $site->idea_generation_mode ?: 'trend_opportunities';
    [$prompt, $entityName, $entityScope] = $this->decodeGenerationContext($site->idea_generation_prompt ?: '');

    $site->update(['idea_generation_error' => null]);

    match ($failedStep) {
        'connecting_sources', 'analyzing_keywords' => FetchEntityKeywordsJob::dispatch($site, $batch, $mode, $prompt, $entityName, $entityScope),
        'generating_ideas' => GenerateArticleIdeasJob::dispatch($site, $batch, $mode, $prompt, $entityName, $entityScope),
        // 'scoring_ideas'    => ScoreArticleIdeasJob::dispatch($site, $batch),
        default            => FetchEntityKeywordsJob::dispatch($site, $batch, $mode, $prompt, $entityName, $entityScope),
    };

    return response()->json(['message' => 'Retrying…'], 202);
}
    /**
     * تریگر دستی امتیازدهی مجدد برای ایده‌هایی که هنوز seo_score ندارند
     * (مثلاً اگر SERP قبلاً به‌خاطر quota یا خطای موقت fail شده بود).
     */
    public function score(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $pendingCount = $site->articleIdeas()->whereNull('seo_score')->count();

        if ($pendingCount === 0) {
            return response()->json([
                'message' => 'All ideas have already been scored.',
            ]);
        }

        // ScoreArticleIdeasJob::dispatch($site);

        return response()->json([
            'message' => "Scoring started for {$pendingCount} ideas.",
        ], 202);
    }

    public function updateStatus(Request $request, Site $site, \App\Models\ArticleIdea $idea): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        if ($idea->website_id !== $site->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:suggested,approved,rejected,used',
        ]);

        $idea->update(['status' => $validated['status']]);

        // article و relatedLinks باید صریح لود بشن، چون $idea از route-model-binding
        // بدون این رابطه‌ها میاد و whenLoaded() در Resource بدون این‌ها همیشه خالی می‌مونه.
        return response()->json(['data' => new ArticleIdeaResource($idea->load(['article', 'relatedLinks']))]);
    }



public function update(Request $request, Site $site, \App\Models\ArticleIdea $idea): JsonResponse
{
    $this->authorizeSiteAccess($request, $site);

    if ($idea->website_id !== $site->id) {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    $validated = $request->validate([
        'title' => 'sometimes|required|string|max:255',
        'meta_description' => 'sometimes|nullable|string|max:255',
        'hook' => 'sometimes|nullable|string|max:255',
        'entity_name' => 'sometimes|required|string|max:255',
        'entity_scope' => 'sometimes|in:primary,core',
        'entity_type' => 'sometimes|nullable|string|max:255',
        'target_url' => 'sometimes|nullable|string|max:255',
        'target_url_title' => 'sometimes|nullable|string|max:255',
        'priority' => 'sometimes|integer|between:0,100',
        'outline' => 'sometimes|nullable|array',
        'outline.*' => 'string|max:255',
        'status' => 'sometimes|in:suggested,approved,rejected,used',
        'autopilot_excluded' => 'sometimes|boolean',
        'tags' => 'sometimes|nullable|array',
        'tags.*' => 'string|max:255',
        'related_links' => 'sometimes|nullable|array',
        'related_links.*.title' => 'required_with:related_links|string|max:255',
        'related_links.*.url' => 'required_with:related_links|string|max:1000',
    ]);

    $relatedLinks = $validated['related_links'] ?? null;
    unset($validated['related_links']);

    if (array_key_exists('tags', $validated)) {
        $validated['tags'] = collect($validated['tags'])
            ->filter(fn ($name) => filled($name))
            ->values()
            ->map(fn ($name, $index) => ['id' => $index + 1, 'name' => $name])
            ->all();
    }

    // اگه کاربر توی همون فرم دوبار یه آدرس رو وارد کرده، فقط اولی رو نگه می‌داریم.
    if ($relatedLinks !== null) {
        $relatedLinks = collect($relatedLinks)
            ->unique(fn (array $link) => mb_strtolower(trim($link['url'])))
            ->values()
            ->all();
    }

    DB::transaction(function () use ($idea, $validated, $relatedLinks) {
        $idea->update($validated);

        if ($relatedLinks !== null) {
            $incomingUrls = collect($relatedLinks)->pluck('url')->all();
            $existing = $idea->relatedLinks()->get()->keyBy('url');

            // لینک‌هایی که دیگه توی فرم نیستن → حذف
            $idea->relatedLinks()
                ->whereNotIn('url', $incomingUrls)
                ->delete();

            // فقط لینک‌هایی که واقعاً جدیدن (url‌شون قبلاً برای این ایده ثبت نشده) ساخته می‌شن
            $newRows = collect($relatedLinks)
                ->reject(fn (array $link) => $existing->has($link['url']))
                ->map(fn (array $link) => [
                    'title' => $link['title'],
                    'url' => $link['url'],
                    'site_id' => $idea->website_id,
                    'match_score' => null,
                    'wordpress_article_id' => null,
                ])
                ->all();

            if (count($newRows) > 0) {
                $idea->relatedLinks()->createMany($newRows);
            }

            foreach ($relatedLinks as $link) {
                if ($existing->has($link['url']) && $existing[$link['url']]->title !== $link['title']) {
                    $existing[$link['url']]->update(['title' => $link['title']]);
                }
            }
        }
    });

    return response()->json(['data' => new ArticleIdeaResource($idea->fresh(['article', 'relatedLinks']))]);
}

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }

    protected function encodeGenerationContext(string $prompt, array $entity): string
    {
        return json_encode([
            'prompt' => $prompt,
            'entity_name' => $entity['name'],
            'entity_scope' => $entity['scope'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{0:string,1:?string,2:?string} */
    protected function decodeGenerationContext(?string $stored): array
    {
        $decoded = json_decode((string) $stored, true);

        if (is_array($decoded) && array_key_exists('prompt', $decoded)) {
            return [
                (string) ($decoded['prompt'] ?? ''),
                $decoded['entity_name'] ?? null,
                $decoded['entity_scope'] ?? null,
            ];
        }

        // Backward compatibility with rows created before entity selection.
        return [(string) ($stored ?? ''), null, null];
    }

    protected function publicEntityOptions(array $entities): array
    {
        return array_map(static fn (array $entity): array => [
            'name' => $entity['name'],
            'scope' => $entity['scope'],
            'type' => $entity['type'] ?? null,
            'description' => $entity['description'] ?? '',
        ], $entities);
    }
}
