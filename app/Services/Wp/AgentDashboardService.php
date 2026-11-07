<?php

namespace App\Services\Wp;

use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\Plan;
use App\Models\ProductArticle;
use App\Models\Site;
use App\Services\SubscriptionService;
use Illuminate\Support\Carbon;

/**
 * داده‌های موردنیاز پلاگین وردپرس (Dashboard/Statistics/Keywords/Entities)
 * را از همان مدل‌های موجود می‌سازد. هیچ Business Logic جدیدی تولید نمی‌کند،
 * فقط داده‌ی موجود را برای مصرف پلاگین شکل می‌دهد.
 */
class AgentDashboardService
{
    /** حداکثر تعداد مقاله محصول در لیست داشبورد پلاگین. */
    private const PRODUCT_ARTICLES_LIMIT = 50;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    public function summary(Site $site): array
    {
        $agentSetting = AgentSetting::firstOrCreate(['site_id' => $site->id]);
        $articlesQuery = Article::where('website_id', $site->id);
        $productQuery = ProductArticle::where('site_id', $site->id);

        return [
            'agent_status' => $agentSetting->status ?? 'idle',
            'statistics' => [
                'total_articles' => (clone $articlesQuery)->count(),
                'published' => (clone $articlesQuery)->where('publish_status', 'published')->count(),
                'generating' => (clone $articlesQuery)->whereNotIn('status', [
                    Article::STATUS_PENDING, Article::STATUS_COMPLETED, Article::STATUS_FAILED,
                ])->count(),
                'failed' => (clone $articlesQuery)->where('status', Article::STATUS_FAILED)->count(),
            ],
            // کلید جدید و جدا: پلاگین‌های قدیمی فقط 'statistics' را می‌خوانند و تحت‌تأثیر قرار نمی‌گیرند.
            'product_statistics' => [
                'total_articles' => (clone $productQuery)->count(),
                'published' => (clone $productQuery)->where('publish_status', 'published')->count(),
                'generating' => (clone $productQuery)->whereIn('status', self::generatingStatuses())->count(),
                'failed' => (clone $productQuery)->where('status', ProductArticle::STATUS_FAILED)->count(),
            ],
            'ideas' => [
                'enabled' => $agentSetting->ideas_enabled,
                'daily_count' => $agentSetting->daily_idea_count,
            ],
            'articles' => [
                'enabled' => $agentSetting->articles_enabled,
                'daily_limit' => $agentSetting->daily_article_limit,
            ],
        ];
    }

    /**
     * جدیدترین مقاله‌های محصول سایت، با همان شکل فیلدهایی که جدول مقالات پلاگین می‌خواند.
     * وضعیت تولید به پنج مقدار ساده (pending/generating/completed/failed/paused) تبدیل می‌شود
     * تا پلاگین مجبور نباشد وضعیت‌های داخلی مراحل تولید را بشناسد.
     */
    public function productArticles(Site $site): array
    {
        return ProductArticle::where('site_id', $site->id)
            ->latest('id')
            ->limit(self::PRODUCT_ARTICLES_LIMIT)
            ->get()
            ->map(fn (ProductArticle $article) => [
                'id' => $article->id,
                'title' => $article->title,
                'status' => $this->publicStatus($article->status),
                'publish_status' => $article->publish_status,
                'wp_post_id' => $article->wp_post_id,
                'wp_permalink' => $article->wp_permalink,
                'word_count' => $article->word_count,
                'published_at' => $this->isoDate($article->published_at),
                'last_synced_at' => $this->isoDate($article->last_synced_at),
            ])
            ->values()
            ->all();
    }

    public function keywords(Site $site, int $limit = 20): array
    {
        return $site->articleIdeas()
            ->orderByDesc('priority')
            ->limit($limit)
            ->pluck('entity_name')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function entities(Site $site, int $limit = 20): array
    {
        return $site->articleIdeas()
            ->limit($limit)
            ->get(['entity_name', 'entity_scope', 'entity_type'])
            ->filter(fn ($idea) => filled($idea->entity_name))
            ->unique('entity_name')
            ->map(fn ($idea) => [
                'name' => $idea->entity_name,
                'scope' => $idea->entity_scope,
                'type' => $idea->entity_type,
            ])
            ->values()
            ->all();
    }

    /**
     * وضعیت اشتراک صاحبِ سایت برای پلاگین وردپرس؛
     * شامل اشتراک فعال + اطلاعات پلن صف‌شده (در صورت وجود) تا کاربر
     * بدونه بعد از تموم شدن پلن فعلی، چه پلنی با چه کردیتی جایگزین می‌شه.
     */
    public function subscription(Site $site): array
    {
        $user = $site->user;

        $subscription = $this->subscriptionService->getActiveSubscription($user);
        $plan = $subscription?->plan;

        $queued = $this->subscriptionService->getQueuedSubscription($user);
        $queuedPlan = $queued?->plan;

        $cheapestPlan = Plan::active()
            ->where('is_free', false)
            ->orderBy('price')
            ->first();

        return [
            'plan_id'              => $subscription?->plan_id,
            'is_free'              => (bool) ($plan?->is_free ?? true),
            'credit_used'          => (int) ($subscription?->credit_used ?? 0),
            'credit_limit'         => (float) ($plan?->credit ?? 0),
            'price'                => (int) ($plan?->final_price ?? 0),
            'currency'             => strtoupper((string) config('services.stripe.currency', 'usd')),
            'discount_percentage'  => (int) ($plan?->discount_percentage ?? 0),
            'cheapest_plan_amount' => (int) ($cheapestPlan?->final_price ?? 0),

            'has_queued_plan'      => $queued !== null,
            'queued_plan_id'       => $queued?->plan_id,
            'queued_plan_name'     => $queuedPlan?->name,
            'queued_plan_credit'   => (float) ($queuedPlan?->credit ?? 0),
        ];
    }

    /** وضعیت‌های «در حال تولید» مقاله محصول (مراحل میانی job زنجیره‌ای تولید). */
    private static function generatingStatuses(): array
    {
        return [
            ProductArticle::STATUS_CREATING_BRIEF,
            ProductArticle::STATUS_CREATING_ASSIGNMENTS,
            ProductArticle::STATUS_GENERATING_SECTIONS,
            ProductArticle::STATUS_GENERATING_META,
        ];
    }

    /**
     * تاریخ را با همان قالب ISO که Eloquent برای AgentArticleController می‌دهد برمی‌گرداند
     * (پلاگین هر دو تب را یکسان قالب‌بندی می‌کند). Carbon::parse هم با مقدار cast‌شده و هم
     * با رشته‌ی خام کار می‌کند، پس اگر cast تاریخ روی مدل نباشد خطا نمی‌دهد.
     */
    private function isoDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toJSON() : null;
    }

    private function publicStatus(?string $status): string
    {
        return match (true) {
            $status === ProductArticle::STATUS_COMPLETED => 'completed',
            $status === ProductArticle::STATUS_FAILED => 'failed',
            $status === ProductArticle::STATUS_PAUSED_CREDIT => 'paused',
            in_array($status, self::generatingStatuses(), true) => 'generating',
            default => 'pending',
        };
    }
}
