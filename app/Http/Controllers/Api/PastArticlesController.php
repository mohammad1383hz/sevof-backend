<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientCreditException;
use App\Exceptions\WordPressPluginOutdatedException;
use App\Http\Controllers\Controller;
use App\Models\ArticleRefresh;
use App\Models\Site;
use App\Models\WordpressArticle;
use App\Services\AI\PastArticleRefreshService;
use App\Services\SearchConsoleInsightsService;
use App\Services\Wp\PastPostsService;
use App\Support\ContentAudit;
use App\Support\PageUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/** «آپدیت مقاله‌های گذشته»: فهرست پست‌های قدیمی سایت با نتیجه‌شان در گوگل، و شروع به‌روزرسانی. */
class PastArticlesController extends Controller
{
    protected const PER_PAGE = 20;

    /** خواندن پست‌ها اگر بیش از این طول بکشد، گیر کرده حساب می‌شود و می‌شود دوباره شروعش کرد. */
    protected const STUCK_SYNC_MINUTES = 15;

    public function index(Request $request, Site $site, PastPostsService $posts, SearchConsoleInsightsService $insights, PastArticleRefreshService $refreshes): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'filter' => 'nullable|in:all,needs_update,opportunity,updated,not_editable',
            'search' => 'nullable|string|max:200',
            'page' => 'nullable|integer|min:1',
        ]);

        $connection = $posts->connection($site);

        // اولین بار که کاربر صفحه را باز می‌کند، فهرست خودکار از سایت خوانده می‌شود
        if ($connection && ! $connection->past_posts_synced_at && ! $this->syncRunning($connection)) {
            $this->queueSync($connection, $site);
        }

        $rows = $connection ? $this->rows($site, $insights) : collect();
        $counts = [
            'all' => $rows->count(),
            'needs_update' => $rows->where('needs_update', true)->count(),
            'opportunity' => $rows->whereNotNull('reason')->count(),
            'updated' => $rows->filter(fn ($r) => ($r['refresh']['status'] ?? null) === ArticleRefresh::STATUS_APPLIED)->count(),
            'not_editable' => $rows->where('editable', false)->count(),
        ];

        $filter = $validated['filter'] ?? 'all';
        $search = SearchConsoleInsightsService::normalizeText((string) ($validated['search'] ?? ''));
        $filtered = $rows
            ->filter(fn ($r) => match ($filter) {
                'needs_update' => $r['needs_update'],
                'opportunity' => $r['reason'] !== null,
                'updated' => ($r['refresh']['status'] ?? null) === ArticleRefresh::STATUS_APPLIED,
                'not_editable' => $r['editable'] === false,
                default => true,
            })
            ->filter(fn ($r) => $search === '' || str_contains(SearchConsoleInsightsService::normalizeText($r['title']), $search))
            ->values();

        $textModel = $refreshes->textModelForUser($request->user());
        $pages = max(1, (int) ceil($filtered->count() / self::PER_PAGE));
        $page = min((int) ($validated['page'] ?? 1), $pages);

        return response()->json([
            'connection' => [
                'type' => $connection ? 'wordpress' : ($site->shopifyConnection ? 'shopify' : null),
                'plugin_version' => $connection?->plugin_version,
                'min_plugin_version' => PastPostsService::MIN_PLUGIN_VERSION,
            ],
            'sync' => $connection ? [
                'status' => $this->syncRunning($connection) ? 'syncing' : $connection->past_posts_sync_status,
                'synced_at' => $connection->past_posts_synced_at?->toISOString(),
                'error' => $connection->past_posts_sync_error,
                'total' => $connection->past_posts_total,
                'fetched' => $connection->past_posts_fetched,
            ] : null,
            'search_console_connected' => $insights->property($site) !== null,
            'counts' => $counts,
            // تخمین کریدیت فقط برای همین صفحه حساب می‌شود
            'items' => $filtered->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->map(fn (array $r) => $r + [
                'estimate' => $r['editable'] ? $refreshes->estimateForWords($textModel, (int) $r['word_count']) : null,
            ])->values(),
            'page' => $page,
            'pages' => $pages,
            'total' => $filtered->count(),
        ]);
    }

    /**
     * متن کامل و زنده‌ی یک مقاله از سایت، برای نمایش یکجای مقاله قبل از آپدیت.
     */
    public function show(Request $request, Site $site, WordpressArticle $wordpressArticle, PastPostsService $posts): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if((int) $wordpressArticle->site_id !== (int) $site->id, 404);

        $connection = $posts->connection($site);

        if (! $connection) {
            return response()->json(['message' => 'Connect your WordPress site to Sevof first.'], 422);
        }

        try {
            $remote = $posts->fetch($connection, (int) $wordpressArticle->wp_post_id);
            // متن تازه همین الان خوانده شد؛ بررسی سئو هم به‌روز می‌شود
            $posts->storeAudit($wordpressArticle, $remote, $site->domain);
        } catch (WordPressPluginOutdatedException $e) {
            return response()->json(['code' => 'plugin_outdated', 'message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (array_key_exists('editable', $remote) && ! $remote['editable']) {
            $wordpressArticle->update(['editable' => false, 'not_editable_reason' => $remote['not_editable_reason'] ?? null]);
        }

        return response()->json(['data' => [
            'id' => $wordpressArticle->token,
            'title' => (string) ($remote['title'] ?? $wordpressArticle->title),
            'url' => (string) ($remote['link'] ?? $wordpressArticle->url),
            'content' => (string) ($remote['content'] ?? ''),
            'editable' => (bool) ($remote['editable'] ?? false),
            'not_editable_message' => ($remote['editable'] ?? false) ? null : PastArticleRefreshService::notEditableMessage($remote['not_editable_reason'] ?? null),
            'seo' => [
                'plugin' => $remote['seo']['plugin'] ?? null,
                'meta_title' => $remote['seo']['meta_title'] ?? null,
                'meta_description' => $remote['seo']['meta_description'] ?? null,
            ],
        ] + $this->auditPayload($wordpressArticle->fresh())]);
    }

    public function sync(Request $request, Site $site, PastPostsService $posts): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $connection = $posts->connection($site);

        if (! $connection) {
            return response()->json(['message' => 'Connect your WordPress site to Sevof first.'], 422);
        }

        if (! $this->syncRunning($connection)) {
            $this->queueSync($connection, $site);
        }

        return response()->json(['message' => 'Reading articles from your site started.'], 202);
    }

    public function refresh(Request $request, Site $site, WordpressArticle $wordpressArticle, PastArticleRefreshService $refreshes): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_if((int) $wordpressArticle->site_id !== (int) $site->id, 404);

        try {
            $refresh = $refreshes->startForPost($wordpressArticle);
        } catch (InsufficientCreditException $e) {
            return response()->json(['code' => 'insufficient_credit', 'message' => $e->getMessage()], 402);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['id' => $refresh->token, 'status' => $refresh->status]], 202);
    }

    /**
     * بررسی سئوی پست برای نمایش: ایرادها و تعداد لینک داخلی (اگر متن هنوز بررسی نشده، فقط ایرادهای عنوان و تاریخ).
     *
     * @return array{issues: array, internal_links: ?int, recommended_links: ?int, audited: bool}
     */
    protected function auditPayload(WordpressArticle $post): array
    {
        $audit = is_array($post->audit) ? $post->audit : null;

        return [
            'issues' => ContentAudit::issues($audit, (string) $post->title, $post->modified_at ?? $post->published_at),
            'internal_links' => $audit ? (int) ($audit['internal_links'] ?? 0) : null,
            'recommended_links' => $audit ? ContentAudit::recommendedLinks((int) ($audit['words'] ?? 0)) : null,
            'audited' => $audit !== null,
        ];
    }

    /** @return Collection<int, array> همه‌ی پست‌های قدیمی سایت با داده‌ی گوگل؛ فرصت‌ها اول */
    protected function rows(Site $site, SearchConsoleInsightsService $insights): Collection
    {
        $metrics = $insights->pageMetrics($site);
        $topQueries = $insights->topQueryByPage($site);

        $latestRefresh = ArticleRefresh::where('site_id', $site->id)
            ->where('source', ArticleRefresh::SOURCE_WP_POST)
            ->latest('id')
            ->get(['id', 'token', 'wordpress_article_id', 'status', 'stage', 'stage_started_at', 'reason', 'summary', 'credit_used', 'error', 'after', 'created_at', 'applied_at'])
            ->unique('wordpress_article_id')
            ->keyBy('wordpress_article_id');

        return WordpressArticle::where('site_id', $site->id)
            ->where('managed', false)
            ->get(['id', 'token', 'wp_post_id', 'title', 'url', 'published_at', 'modified_at', 'word_count', 'editable', 'not_editable_reason', 'audit', 'audited_at'])
            ->map(function (WordpressArticle $post) use ($metrics, $topQueries, $latestRefresh, $insights) {
                $hash = PageUrl::hash($post->url);
                $m = $hash ? $metrics->get($hash) : null;
                $isOld = $post->published_at && $post->published_at->lte(now()->subDays(SearchConsoleInsightsService::MIN_ARTICLE_AGE_DAYS));
                $reason = $m && $isOld && $post->editable ? $insights->refreshReason($m) : null;
                $refresh = $latestRefresh->get($post->id);
                $audit = $this->auditPayload($post);

                return $audit + [
                    'needs_update' => $reason !== null || ContentAudit::needsUpdate($audit['issues']),
                    'id' => $post->token,
                    'title' => $post->title,
                    'url' => $post->url,
                    'published_at' => $post->published_at?->toISOString(),
                    'modified_at' => $post->modified_at?->toISOString(),
                    'word_count' => $post->word_count,
                    'editable' => (bool) $post->editable,
                    'not_editable_message' => $post->editable ? null : PastArticleRefreshService::notEditableMessage($post->not_editable_reason),
                    'gsc' => $m ? [
                        'clicks' => $m['clicks'],
                        'previous_clicks' => $m['previous_clicks'],
                        'impressions' => $m['impressions'],
                        'ctr' => $m['ctr'],
                        'position' => $m['position'],
                        'top_query' => $topQueries[$hash] ?? null,
                    ] : null,
                    'reason' => $reason['reason'] ?? null,
                    'potential_clicks' => $reason['potential_clicks'] ?? null,
                    'refresh' => $refresh ? [
                        'id' => $refresh->token,
                        'status' => $refresh->status,
                        'stage' => $refresh->stage,
                        'stage_started_at' => $refresh->stage_started_at?->toISOString(),
                        'credit_used' => round((float) $refresh->credit_used, 2),
                        'changed_sections' => count($refresh->after['sections'] ?? []),
                        'meta_changed' => ! empty($refresh->after['meta'] ?? []),
                        'error' => $refresh->error,
                        'created_at' => $refresh->created_at?->toISOString(),
                        'applied_at' => $refresh->applied_at?->toISOString(),
                    ] : null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($b['potential_clicks'] ?? -1) <=> ($a['potential_clicks'] ?? -1),
                // بعد از فرصت‌های گوگل: مقاله‌هایی که بررسی سئو ایراد جدی در آن‌ها پیدا کرده
                fn ($a, $b) => $b['needs_update'] <=> $a['needs_update'],
                fn ($a, $b) => ($b['gsc']['impressions'] ?? -1) <=> ($a['gsc']['impressions'] ?? -1),
                fn ($a, $b) => strcmp((string) $b['published_at'], (string) $a['published_at']),
            ])
            ->values();
    }

    protected function syncRunning($connection): bool
    {
        return in_array($connection->past_posts_sync_status, ['queued', 'syncing'], true)
            && $connection->updated_at?->gt(now()->subMinutes(self::STUCK_SYNC_MINUTES));
    }

    protected function queueSync($connection, Site $site): void
    {
        app(PastPostsService::class)->startSync($site);
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
