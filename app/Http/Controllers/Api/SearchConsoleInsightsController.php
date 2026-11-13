<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientCreditException;
use App\Http\Controllers\Controller;
use App\Jobs\ApplyArticleRefreshJob;
use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticleRefresh;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\Site;
use App\Services\AI\ArticleRefreshService;
use App\Services\SearchConsoleInsightsService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/** داشبورد نتیجه‌ی سرچ کنسول در بخش اتوماسیون + به‌روزرسانی مقاله‌ها. */
class SearchConsoleInsightsController extends Controller
{
    public function show(Request $request, Site $site, SearchConsoleInsightsService $insights, ArticleRefreshService $refreshes, SubscriptionService $subscriptions): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = GoogleSearchConsoleConnection::where('user_id', $request->user()->id)->first();
        $property = $insights->property($site);
        $performance = $property ? $insights->articlePerformance($site) : collect();
        $withData = $performance->where('has_data', true);
        $articles = Article::whereIn('id', $performance->where('type', 'article')->pluck('id'))->get()->keyBy('id');

        $opportunities = $property
            ? $insights->refreshCandidates($site, $performance)->map(fn (array $row) => $this->rowPayload($row, $articles) + [
                'reason' => $row['reason'],
                'potential_clicks' => $row['potential_clicks'],
                'estimate' => $refreshes->estimate($articles[$row['id']]),
            ])
            : collect();

        $setting = AgentSetting::firstOrCreate(['site_id' => $site->id]);

        return response()->json([
            'connection' => $connection ? ['status' => $connection->status, 'last_error' => $connection->last_error, 'email' => $connection->google_email] : null,
            'property' => $property ? [
                'property_url' => $property->property_url,
                'sync_status' => $property->sync_status,
                'last_synced_at' => $property->last_synced_at?->toISOString(),
                'last_sync_error' => $property->last_sync_error,
            ] : null,
            'site_totals' => $property?->totals,
            'articles' => [
                'count' => $performance->count(),
                'with_data' => $withData->count(),
                'clicks' => (int) $withData->sum('clicks'),
                'previous_clicks' => $withData->contains(fn ($r) => $r['previous_clicks'] !== null) ? (int) $withData->sum('previous_clicks') : null,
                'impressions' => (int) $withData->sum('impressions'),
                'previous_impressions' => $withData->contains(fn ($r) => $r['previous_impressions'] !== null) ? (int) $withData->sum('previous_impressions') : null,
                'items' => $withData->take(20)->map(fn (array $row) => $this->rowPayload($row, $articles))->values(),
            ],
            'opportunities' => $opportunities->values(),
            // جستجوهایی که سایت در گوگل دیده می‌شود ولی صفحه‌ی خوبی برایشان ندارد: موضوع مقاله‌ی تازه
            'query_opportunities' => $property
                ? $insights->queryOpportunities($site, $insights->coveredTexts($site), 10)->map(fn ($r) => [
                    'query' => $r->query,
                    'impressions' => $r->impressions,
                    'clicks' => $r->clicks,
                    'best_position' => round($r->best_position, 1),
                    'question' => SearchConsoleInsightsService::isQuestion($r->query),
                ])
                : [],
            'refreshes' => ArticleRefresh::where('site_id', $site->id)
                ->with(['article:id,token,title', 'wordpressArticle:id,token,title'])
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (ArticleRefresh $r) => $this->refreshPayload($r)),
            'automation' => [
                'agent_enabled' => (bool) $setting->agent_enabled,
                'refresh_enabled' => (bool) $setting->refresh_enabled,
                'auto_publish' => $setting->default_publish_status === 'publish',
                'has_paid_plan' => $subscriptions->hasActivePaidPlan($request->user()),
            ],
        ]);
    }

    public function start(Request $request, Site $site, Article $article, ArticleRefreshService $refreshes): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if((int) $article->website_id !== (int) $site->id, 404);

        try {
            $refresh = $refreshes->start($article);
        } catch (InsufficientCreditException $e) {
            return response()->json(['code' => 'insufficient_credit', 'message' => $e->getMessage()], 402);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->refreshPayload($refresh->load('article:id,token,title'))], 202);
    }

    public function showRefresh(Request $request, Site $site, ArticleRefresh $articleRefresh): JsonResponse
    {
        $this->authorizeRefresh($request, $site, $articleRefresh);
        $articleRefresh->load(['article:id,token,title', 'wordpressArticle:id,token,title']);

        $changedIds = array_column($articleRefresh->after['sections'] ?? [], 'id');
        $before = collect($articleRefresh->before['sections'] ?? [])->keyBy('id');

        return response()->json(['data' => $this->refreshPayload($articleRefresh) + [
            'queries' => $articleRefresh->queries ?? [],
            'meta' => [
                'before' => $articleRefresh->before['meta'] ?? [],
                'after' => $articleRefresh->after['meta'] ?? [],
            ],
            'sections' => collect($articleRefresh->after['sections'] ?? [])->map(fn (array $s) => [
                'id' => $s['id'],
                'heading' => $s['heading'],
                'instruction' => $s['instruction'] ?? null,
                'before' => $before[$s['id']]['content'] ?? '',
                'after' => $s['content'],
            ])->values(),
            'changed_section_count' => count($changedIds),
            // لینک‌های داخلی تازه: متن لینک، صفحه‌ی مقصد و شماره‌ی تغییری که در آن نشسته
            'links' => collect($articleRefresh->after['links'] ?? [])->map(fn (array $l) => array_intersect_key($l, array_flip(['n', 'anchor', 'url', 'title', 'kind', 'section'])))->values(),
            // مقاله‌ی یوتیکس Markdown است؛ پست قدیمی سایت HTML خام وردپرس
            'format' => $articleRefresh->source === ArticleRefresh::SOURCE_WP_POST ? 'html' : 'markdown',
            // کل مقاله به ترتیب، برای نمایش یکجا: بخش تغییرکرده با پاراگراف‌های برجسته (display)
            'document' => $this->document($articleRefresh),
            'edits' => collect($articleRefresh->after['sections'] ?? [])
                ->flatMap(fn (array $s) => array_map(fn (array $e) => $e + ['section' => $s['heading']], $s['edits'] ?? []))
                ->sortBy('n')
                ->values(),
        ]]);
    }

    /** @return array<int, array{id:int, heading:string, html:string, before_html:string, changed:bool}> */
    protected function document(ArticleRefresh $refresh): array
    {
        $after = collect($refresh->after['sections'] ?? [])->keyBy('id');

        return collect($refresh->before['sections'] ?? [])->map(function (array $s) use ($after) {
            $changed = $after->get($s['id']);

            return [
                'id' => $s['id'],
                'heading' => $s['heading'],
                'html' => $changed ? ($changed['display'] ?? $changed['content']) : $s['content'],
                'before_html' => $s['content'],
                'changed' => (bool) $changed,
            ];
        })->values()->all();
    }

    public function apply(Request $request, Site $site, ArticleRefresh $articleRefresh): JsonResponse
    {
        return $this->transition($request, $site, $articleRefresh, ArticleRefresh::STATUS_READY, 'apply');
    }

    public function revert(Request $request, Site $site, ArticleRefresh $articleRefresh): JsonResponse
    {
        return $this->transition($request, $site, $articleRefresh, ArticleRefresh::STATUS_APPLIED, 'revert');
    }

    public function discard(Request $request, Site $site, ArticleRefresh $articleRefresh): JsonResponse
    {
        $this->authorizeRefresh($request, $site, $articleRefresh);

        if (! in_array($articleRefresh->status, [ArticleRefresh::STATUS_READY, ArticleRefresh::STATUS_FAILED], true)) {
            return response()->json(['message' => 'This update can\'t be discarded in its current state.'], 422);
        }

        $articleRefresh->update(['status' => ArticleRefresh::STATUS_DISCARDED, 'error' => null]);

        return response()->json(['data' => $this->refreshPayload($articleRefresh->load(['article:id,token,title', 'wordpressArticle:id,token,title']))]);
    }

    protected function transition(Request $request, Site $site, ArticleRefresh $refresh, string $requiredStatus, string $action): JsonResponse
    {
        $this->authorizeRefresh($request, $site, $refresh);

        // تغییر وضعیت اتمیک: دو کلیک پشت هم فقط یک بار منتشر می‌کند
        $claimed = ArticleRefresh::whereKey($refresh->id)
            ->where('status', $requiredStatus)
            ->update(['status' => ArticleRefresh::STATUS_APPLYING, 'stage' => 'publishing', 'stage_started_at' => now(), 'error' => null]);

        if (! $claimed) {
            return response()->json(['message' => 'This update\'s status changed; refresh the page.'], 409);
        }

        ApplyArticleRefreshJob::dispatch($refresh->id, $action);

        return response()->json(['data' => $this->refreshPayload($refresh->fresh()->load(['article:id,token,title', 'wordpressArticle:id,token,title']))], 202);
    }

    /** @param  Collection<int, Article>  $articles  مقاله‌های همین ردیف‌ها با کلید آی‌دی، برای توکن */
    protected function rowPayload(array $row, Collection $articles): array
    {
        return [
            // مقاله‌ی محصول از قبل ULID دارد
            'id' => $row['type'] === 'article' ? $articles->get($row['id'])?->token : $row['id'],
            'type' => $row['type'],
            'title' => $row['title'],
            'url' => $row['url'],
            'clicks' => $row['clicks'],
            'previous_clicks' => $row['previous_clicks'],
            'impressions' => $row['impressions'],
            'ctr' => $row['ctr'],
            'position' => $row['position'],
            'previous_position' => $row['previous_position'],
            'top_query' => $row['top_query'],
        ];
    }

    protected function refreshPayload(ArticleRefresh $refresh): array
    {
        return [
            'id' => $refresh->token,
            'source' => $refresh->source ?? ArticleRefresh::SOURCE_ARTICLE,
            'article_id' => $refresh->article_id ? $refresh->article?->token : null,
            'article_title' => $refresh->title(),
            'status' => $refresh->status,
            // مرحله‌ی زنده: reading | writing | checking | publishing
            'stage' => $refresh->stage,
            'stage_started_at' => $refresh->stage_started_at?->toISOString(),
            'trigger' => $refresh->trigger,
            'reason' => $refresh->reason,
            'summary' => $refresh->summary,
            'metrics' => $refresh->metrics,
            'credit_used' => round((float) $refresh->credit_used, 2),
            'changed_sections' => count($refresh->after['sections'] ?? []),
            'meta_changed' => ! empty($refresh->after['meta'] ?? []),
            'new_links' => count($refresh->after['links'] ?? []),
            'error' => $refresh->error,
            'created_at' => $refresh->created_at?->toISOString(),
            'applied_at' => $refresh->applied_at?->toISOString(),
        ];
    }

    protected function authorizeRefresh(Request $request, Site $site, ArticleRefresh $refresh): void
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if((int) $refresh->site_id !== (int) $site->id, 404);
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
