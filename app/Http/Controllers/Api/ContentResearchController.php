<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunContentResearchJob;
use App\Models\ContentResearchSession;
use App\Models\Site;
use App\Services\AI\CompetitorGapResearchService;
use App\Services\AI\ContentResearchService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContentResearchController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
    ) {
    }

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $sessions = ContentResearchSession::query()
            ->where('site_id', $site->id)
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->sortBy('created_at')
            ->values()
            ->map(fn (ContentResearchSession $session) => $this->sessionPayload($session));

        return response()->json(['sessions' => $sessions]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        if (! $site->ensureEntity()) {
            return $this->siteNotReadyResponse($site);
        }

        $user = $request->user();

        // --- چک اشتراک/کریدیت قبل از هرگونه ساخت session یا dispatch جاب ---
        $subscription = $this->subscriptionService->getActiveSubscription($user);

        if (! $subscription) {
            return response()->json([
                'message' => 'You don\'t have an active plan. Please choose a plan first.',
            ], 402);
        }

        if (! $this->subscriptionService->hasAvailableCredit($user)) {
            return response()->json([
                'message' => 'You don\'t have enough credit left.',
            ], 402);
        }

        $validated = $request->validate([
            'type' => 'required|in:keyword_clusters,competitor_gaps',
            'prompt' => 'nullable|string|max:800',
        ]);

        $session = ContentResearchSession::create([
            'site_id' => $site->id,
            'user_id' => $user->id,
            'type' => $validated['type'],
            'status' => 'queued',
            'prompt' => trim($validated['prompt'] ?? '') ?: null,
            'data_source' => DB::table('search_console_query_metrics')->where('site_id', $site->id)->exists()
                ? 'gsc_justserp'
                : 'entity_justserp',
        ]);

        RunContentResearchJob::dispatch($session);

        return response()->json([
            'message' => 'Content research started.',
            'session' => $this->sessionPayload($session),
        ], 202);
    }


    public function latest(Request $request, Site $site, string $type): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        abort_unless(
            in_array($type, [ContentResearchService::TYPE_KEYWORD_CLUSTERS, CompetitorGapResearchService::TYPE_COMPETITOR_GAPS], true),
            404,
            'Content research type not found.'
        );

        $session = ContentResearchSession::query()
            ->where('site_id', $site->id)
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->latest('created_at')
            ->first();

        return response()->json([
            'session' => $session ? $this->sessionPayload($session) : null,
            'result' => $session?->status === 'done' ? $this->resultPayload($session) : null,
        ]);
    }

    public function show(Request $request, Site $site, ContentResearchSession $session): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if($session->site_id !== $site->id, 404, 'Research session not found.');

        return response()->json([
            'session' => $this->sessionPayload($session),
            'result' => $session->status === 'done' ? $this->resultPayload($session) : null,
        ]);
    }

    protected function sessionPayload(ContentResearchSession $session): array
    {
        return [
            'id' => $session->token,
            'type' => $session->type,
            'status' => $session->status,
            'prompt' => $session->prompt,
            'data_source' => $session->data_source,
            'error' => $session->error,
            'manus_task_id' => data_get($session->metadata, 'manus.task_id'),
            'manus_task_url' => data_get($session->metadata, 'manus.task_url'),
            'manus_progress' => data_get($session->metadata, 'research.progress'),
            'completed_at' => $session->completed_at?->toISOString(),
            'created_at' => $session->created_at?->toISOString(),
        ];
    }

    protected function resultPayload(ContentResearchSession $session): array
    {
        return match ($session->type) {
            ContentResearchService::TYPE_KEYWORD_CLUSTERS => [
                'clusters' => DB::table('keyword_clusters')
                    ->where('content_research_session_id', $session->id)
                    ->orderByDesc('opportunity_score')
                    ->get()
                    ->map(fn ($cluster) => [
                        'id' => $cluster->id,
                        'name' => $cluster->name,
                        'primary_keyword' => $cluster->primary_keyword,
                        'related_keywords' => json_decode($cluster->related_keywords, true) ?: [],
                        'search_intent' => $cluster->search_intent,
                        'estimated_search_volume' => $cluster->estimated_search_volume,
                        'volume_label' => $cluster->volume_label,
                        'trend_direction' => $cluster->trend_direction,
                        'trend_percentage' => in_array($cluster->trend_direction, ['unavailable', 'estimated'], true) ? null : (int) $cluster->trend_percentage,
                        'difficulty' => $cluster->difficulty,
                        'opportunity_score' => $cluster->opportunity_score,
                        'competitor_count' => $cluster->competitor_count,
                        'data_source' => $cluster->data_source,
                        'evidence' => json_decode($cluster->evidence, true) ?: [],
                    ]),
            ],
            CompetitorGapResearchService::TYPE_COMPETITOR_GAPS => [
                'summary' => data_get($session->metadata, 'research.summary'),
                'site_analysis' => data_get($session->metadata, 'research.site_analysis', []),
                'recommendations' => data_get($session->metadata, 'research.recommendations', []),
                'manus_progress' => data_get($session->metadata, 'research.progress'),
                'manus_task_id' => data_get($session->metadata, 'manus.task_id'),
                'manus_task_url' => data_get($session->metadata, 'manus.task_url'),
                'competitors' => DB::table('site_competitors')
                    ->where('content_research_session_id', $session->id)
                    ->orderByDesc('threat_score')
                    ->get()
                    ->map(fn ($competitor) => [
                        'id' => $competitor->id,
                        'domain' => $competitor->domain,
                        'display_name' => $competitor->display_name,
                        'best_position' => $competitor->best_position,
                        'visibility_score' => $competitor->visibility_score,
                        'threat_level' => $competitor->threat_level,
                        'threat_score' => $competitor->threat_score,
                        'data_source' => $competitor->data_source,
                        'evidence' => json_decode($competitor->evidence, true) ?: [],
                    ]),
                'gaps' => DB::table('content_gaps')
                    ->leftJoin('site_competitors', 'content_gaps.site_competitor_id', '=', 'site_competitors.id')
                    ->where('content_gaps.content_research_session_id', $session->id)
                    ->orderByDesc('content_gaps.opportunity_score')
                    ->get([
                        'content_gaps.*',
                        'site_competitors.display_name as competitor_name',
                    ])
                    ->map(fn ($gap) => [
                        'id' => $gap->id,
                        'query' => $gap->query,
                        'title' => $gap->title,
                        'gap_type' => $gap->gap_type,
                        'description' => $gap->description,
                        'recommended_angle' => $gap->recommended_angle,
                        'opportunity_score' => $gap->opportunity_score,
                        'threat_level' => $gap->threat_level,
                        'competitor' => $gap->competitor_name,
                        'data_source' => $gap->data_source,
                        'evidence' => json_decode($gap->evidence, true) ?: [],
                    ]),
            ],
            default => [],
        };
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
