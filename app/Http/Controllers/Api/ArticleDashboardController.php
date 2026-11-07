<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleIdeaResource;
use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\EntityKeyword;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArticleDashboardController extends Controller
{
    /**
     * این کنترلر هیچ Business Logic جدیدی برای تولید مقاله/ایده ندارد.
     * فقط داده‌های موجود را از همان مدل‌ها/Queryهایی که در
     * ArticleController و ArticleIdeaController استفاده می‌شوند جمع‌آوری می‌کند.
     * Agent ایده و Agent مقاله دو موجودیت کاملاً مستقل‌اند (AgentSetting).
     *
     * توجه مهم: تمام آمار/فعالیت‌های این داشبورد فقط مربوط به مقالاتی است که
     * توسط خودِ Agent ساخته شده‌اند (source = Article::SOURCE_AGENT).
     * مقالاتی که کاربر به‌صورت دستی (manual) ساخته را شامل نمی‌شود.
     */
    public function index(Request $request, Site $site): JsonResponse
{
    $this->authorizeSiteAccess($request, $site);

    $agentSetting = AgentSetting::firstOrCreate(['site_id' => $site->id]);

    // ⬅️ فقط مقالاتی که Agent ساخته، نه همه‌ی مقالات سایت
    $articlesQuery = Article::where('website_id', $site->id)
        ->where('source', Article::SOURCE_AGENT);

    $statistics = $this->buildStatistics(clone $articlesQuery, $site);

    // همان ستون‌ها و رابطه‌ای که در ArticleController::index استفاده می‌شود
    $recentArticles = (clone $articlesQuery)
        ->with('idea:id,token,title,entity_name')
        ->latest()
        ->limit(10)
        ->get(['id', 'token', 'article_idea_id', 'title', 'status', 'seo_score', 'word_count', 'completed_at', 'created_at']);

    // همان مرتب‌سازی که در ArticleIdeaController::index استفاده می‌شود
    $ideas = $site->articleIdeas()
        ->orderByRaw('seo_score IS NULL')
        ->orderByDesc('seo_score')
        ->orderByDesc('priority')
        ->limit(10)
        ->get();

    $keywords = $ideas->pluck('entity_name')->filter()->unique()->values();

    $entities = $ideas
        ->map(fn (ArticleIdea $idea) => [
            'name' => $idea->entity_name,
            'scope' => $idea->entity_scope,
            'type' => $idea->entity_type,
        ])
        ->filter(fn (array $entity) => filled($entity['name']))
        ->unique('name')
        ->values();

    $queuedCount = (clone $articlesQuery)->where('status', Article::STATUS_PENDING)->count();

    $runningCount = (clone $articlesQuery)->whereNotIn('status', [
        Article::STATUS_PENDING,
        Article::STATUS_COMPLETED,
        Article::STATUS_FAILED,
    ])->count();

    return response()->json([
        'data' => [
           'agent' => [
    'enabled' => $agentSetting->isActive(),
    'agent_enabled' => $agentSetting->isActive(),
    'status' => $agentSetting->status ?? ($runningCount > 0 ? 'working' : 'idle'),
    'running_jobs' => $runningCount,
    'queued_jobs' => $queuedCount,
    'ideas' => [
        'enabled' => $agentSetting->ideas_enabled,
        'daily_count' => $agentSetting->daily_idea_count,
        'last_run_at' => $agentSetting->last_ideas_run_at,
        'next_run_at' => $agentSetting->next_ideas_run_at,
    ],
    'articles' => [
        'enabled' => $agentSetting->articles_enabled,
        'daily_limit' => $agentSetting->daily_article_limit,
        'last_run_at' => $agentSetting->last_articles_run_at,
        'next_run_at' => $agentSetting->next_articles_run_at,
    ],

    // ⬅️ فیلدهای کامل تنظیمات Auto Pilot که فرانت (AgentSettingsPanel) بهشون نیاز داره
    'default_publish_status' => $agentSetting->default_publish_status,
    'daily_article_limit' => $agentSetting->daily_article_limit,
    'daily_idea_count' => $agentSetting->daily_idea_count,
    'daily_start_hour' => $agentSetting->daily_start_hour,
    'publish_interval_days' => $agentSetting->publish_interval_days,
    'content_model' => $agentSetting->content_model,
    'image_generation_enabled' => $agentSetting->image_generation_enabled,
    'image_model' => $agentSetting->image_model,
    'images_per_article' => $agentSetting->images_per_article,
    'image_user_prompt' => $agentSetting->image_user_prompt,     // ⬅️ خط جدید — قبلاً جا افتاده بود
    'article_user_prompt' => $agentSetting->article_user_prompt, // ⬅️ خط جدید
    'min_sections' => $agentSetting->min_sections,                // ⬅️ خط جدید
    'max_sections' => $agentSetting->max_sections,                // ⬅️ خط جدید

    // ⬅️ کل مدل هم به‌عنوان مرجع کامل (شامل appends مثل schedule_short_preview)
    // تا اگه فردا فیلد جدیدی اضافه شد، مجبور نباشی این متد رو دوباره دستی آپدیت کنی
    'settings' => $agentSetting,
],
            'statistics' => $statistics,
            'activities' => $this->buildActivities($site),
            'recent_articles' => $recentArticles,
            'article_ideas' => ArticleIdeaResource::collection($ideas),
            'keywords' => $keywords,
            'entities' => $entities,
            'schedule' => [
                'ideas' => [
                    'enabled' => $agentSetting->ideas_enabled,
                    'next_run_at' => $agentSetting->next_ideas_run_at,
                ],
                'articles' => [
                    'enabled' => $agentSetting->articles_enabled,
                    'next_run_at' => $agentSetting->next_articles_run_at,
                ],
                // فقط نمایشی — ترتیب مراحل داخلی هر Agent
                'steps_ideas' => ['analyze_site', 'discover_keywords', 'generate_idea', 'score_idea'],
                'steps_articles' => ['pick_best_idea', 'generate_article', 'seo_review'],
            ],
        ],
    ]);
}

    /**
     * منطق انتشار:
     * - wp_sync_status مشخص می‌کند که اصلاً درخواستِ ارسال به وردپرس زده شده یا نه.
     *   مقادیر ممکن: not_synced (هنوز درخواستی نرفته) | syncing | synced (موفق) | failed (ناموفق).
     * - publish_status فقط وقتی معنا دارد که wp_sync_status = synced باشد؛
     *   مشخص می‌کند پستِ ارسال‌شده روی وردپرس published شده یا هنوز draft مانده.
     *
     * publish_requested = هر مقاله‌ای که یک تلاش واقعی برای انتشار رویش زده شده
     *                      (synced یا failed — یعنی صرف‌نظر از موفقیت یا شکست، Job واقعاً اجرا شده)
     *                      این همان عددی است که در فرانت به‌عنوان "مقاله منتشرشده" نمایش داده می‌شود.
     *
     * علاوه بر آن published / draft / publish_failed هم جداگانه برمی‌گردانیم تا در آینده
     * اگر خواستی breakdown دقیق‌تری (مثلاً در مودال کلیک روی کارت) نشان بدهی، آماده باشد.
     *
     * keywords_found دیگر از total_words محاسبه نمی‌شود (باگ قبلی).
     * منبع واقعی کلیدواژه‌ها جدول keyword_clusters است: برای هر ردیف، یک کلیدواژه
     * اصلی (primary_keyword) به‌علاوه‌ی همه‌ی کلیدواژه‌های مرتبط (related_keywords،
     * که یک آرایه JSON است) شمرده و مجموعشان برای کل سایت برگردانده می‌شود.
     *
     * opportunities_discovered دیگر به session تحقیق وابسته نیست؛
     * مستقیماً مجموع رکوردهای واقعی ArticleIdea برای همین سایت شمارش می‌شود
     * تا حتی وقتی هیچ session با وضعیت "done" ثبت نشده، عدد درست نمایش داده شود.
     */
    private function buildStatistics($query, Site $site): array
    {
        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', Article::STATUS_COMPLETED)->count();

        $publishRequested = (clone $query)->where('wp_sync_status', '!=', 'not_synced')->count();
        $published = (clone $query)->where('publish_status', 'published')->count();
        $draft = (clone $query)->where('publish_status', 'draft')->count();
        $publishFailed = (clone $query)->where('wp_sync_status', 'failed')->count();

        $generating = (clone $query)->whereNotIn('status', [
            Article::STATUS_PENDING,
            Article::STATUS_COMPLETED,
            Article::STATUS_FAILED,
        ])->count();

        // شکست در تولیدِ خودِ مقاله (status)، نه شکست انتشار روی وردپرس
        $generationFailed = (clone $query)->where('status', Article::STATUS_FAILED)->count();

        $avgSeo = (clone $query)->whereNotNull('seo_score')->avg('seo_score');
        $totalWords = (clone $query)->sum('word_count');

        // ⬅️ مجموع کل ایده‌های همین سایت که Agent ساخته (نه فقط ایده‌های یک session خاص)
        $ideasCount = ArticleIdea::where('website_id', $site->id)
            // ->where('source', 'agent')
            ->count();

        // ⬅️ تعداد واقعی کلیدواژه‌های کشف‌شده برای این سایت از جدول keyword_clusters.
        // هر ردیف: ۱ کلیدواژه اصلی (primary_keyword) + N کلیدواژه مرتبط (related_keywords، آرایه JSON).
        // هر دو با هم sum می‌شوند تا عدد نهایی، مجموع کل کلیدواژه‌های کشف‌شده باشد.
        $keywordsFound = DB::table('keyword_clusters')
            ->where('site_id', $site->id)
            ->get(['primary_keyword', 'related_keywords'])
            ->sum(function ($row) {
                $primaryCount = filled($row->primary_keyword) ? 1 : 0;
                $related = json_decode($row->related_keywords ?: '[]', true);
                $relatedCount = is_array($related) ? count($related) : 0;

                return $primaryCount + $relatedCount;
            });
                    return [
            'total_articles' => $total,
            'articles_written' => $completed,
            'generating' => $generating,
            'generation_failed' => $generationFailed,

            // آمار انتشار
            'publish_requested' => $publishRequested,
            'published' => $published,
            'draft' => $draft,
            'publish_failed' => $publishFailed,

            'average_seo_score' => $avgSeo !== null ? round((float) $avgSeo, 1) : null,
            'total_words' => (int) $totalWords,

            'keywords_found' => (int) $keywordsFound,
            'opportunities_discovered' => $ideasCount,

            // ⬅️ کلیدهایی که دقیقاً فرانت (AgentStatusCard) استفاده می‌کند:
            // "مقاله منتشرشده" == تعداد مقالاتی که *درخواست* انتشارشان زده شده (موفق یا ناموفق)
            'articles_published' => $publishRequested,
            'failed' => $generationFailed,
        ];
    }

    /**
     * چون هنوز مدل/جدول Event مستقلی برای فعالیت‌های Agent وجود ندارد،
     * Timeline از timestampهای موجود روی Article و ArticleIdea استخراج می‌شود.
     * هیچ داده Fake ساخته نمی‌شود؛ اگر رکوردی نباشد، آرایه خالی برمی‌گردد.
     *
     * ⬅️ فقط مقالاتی که Agent ساخته (source = agent) در این تایم‌لاین می‌آیند.
     */
    private function buildActivities(Site $site, int $limit = 20): array
    {
        $events = collect();

        Article::where('website_id', $site->id)
            ->where('source', Article::SOURCE_AGENT)
            ->latest()
            ->limit($limit)
            ->get(['id', 'token', 'title', 'status', 'wp_sync_status', 'publish_status', 'created_at', 'completed_at', 'published_at'])
            ->each(function (Article $article) use ($events) {
                $events->push([
                    'type' => 'article_generation_started',
                    'label' => 'Article generation started',
                    'subject' => $article->title,
                    'article_id' => $article->token,
                    'at' => $article->created_at,
                ]);

                if ($article->completed_at) {
                    $events->push([
                        'type' => 'article_generated',
                        'label' => 'Article generated',
                        'subject' => $article->title,
                        'article_id' => $article->token,
                        'at' => $article->completed_at,
                    ]);
                }

                if ($article->wp_sync_status === 'failed') {
                    $events->push([
                        'type' => 'article_publish_failed',
                        'label' => 'Publishing the article failed',
                        'subject' => $article->title,
                        'article_id' => $article->token,
                        'at' => $article->completed_at ?? $article->created_at,
                    ]);
                } elseif ($article->publish_status === 'published' && $article->published_at) {
                    $events->push([
                        'type' => 'article_published',
                        'label' => 'Article published',
                        'subject' => $article->title,
                        'article_id' => $article->token,
                        'at' => $article->published_at,
                    ]);
                } elseif ($article->publish_status === 'draft') {
                    $events->push([
                        'type' => 'article_draft_synced',
                        'label' => 'Article saved as a draft on WordPress',
                        'subject' => $article->title,
                        'article_id' => $article->token,
                        'at' => $article->completed_at ?? $article->created_at,
                    ]);
                }
            });

        $site->articleIdeas()
            ->latest()
            ->limit($limit)
            ->get(['id', 'token', 'title', 'entity_name', 'created_at', 'scored_at'])
            ->each(function (ArticleIdea $idea) use ($events) {
                $events->push([
                    'type' => 'idea_created',
                    'label' => 'New idea created',
                    'subject' => $idea->title,
                    'idea_id' => $idea->token,
                    'at' => $idea->created_at,
                ]);

                if ($idea->scored_at) {
                    $events->push([
                        'type' => 'keyword_discovered',
                        'label' => 'Entities/keywords scored',
                        'subject' => $idea->entity_name,
                        'idea_id' => $idea->token,
                        'at' => $idea->scored_at,
                    ]);
                }
            });

        return $events
            ->filter(fn (array $e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->values()
            ->take($limit)
            ->all();
    }

    protected function authorizeSiteAccess(Request $request, Site $site): void
    {
        abort_if($site->user_id !== $request->user()->id, 403, 'Forbidden.');
    }
}
