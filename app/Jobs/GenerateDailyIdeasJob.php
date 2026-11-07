<?php

namespace App\Jobs;

use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticleIdea;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDailyArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public Site $site,
        public bool $force = false,
    ) {}

    public function handle(): void
    {
        $setting = AgentSetting::firstOrCreate(['site_id' => $this->site->id]);

        if (! $this->force && ! $setting->isActive()) {
            return;
        }

        if ($setting->daily_article_limit) {
            $todayCount = Article::where('website_id', $this->site->id)
                ->whereDate('created_at', today())
                ->count();

            if ($todayCount >= $setting->daily_article_limit) {
                $setting->update([
                    'status' => 'articles_daily_limit_reached',
                    'last_articles_run_at' => now(),
                ]);

                return;
            }
        }

        $idea = $this->pickBestIdea();

        if (! $idea) {
            $setting->update([
                'status' => 'waiting_for_scored_ideas',
                'last_articles_run_at' => now(),
            ]);

            return;
        }

        // دقیقاً همان منطق ArticleController::generate — بدون هیچ Business Logic تکراری
        $article = Article::create([
            'article_idea_id' => $idea->id,
            'website_id' => $this->site->id,
            'title' => $idea->title,
            'meta_description' => $idea->meta_description,
            'status' => Article::STATUS_PENDING,
        ]);

        StartArticleGenerationJob::dispatch($article);

        $setting->update([
            'status' => 'generating_article',
            'last_articles_run_at' => now(),
            'next_articles_run_at' => now()->addDay(),
        ]);
    }

    /**
     * TODO آینده: این انتخاب باید به یک سرویس AI واسط سپرده شود
     * (که از بین چند ایده‌ی امتیازدهی‌شده، بهترین را برای «امروز» انتخاب کند).
     * فعلاً به‌سادگی بالاترین seo_score را انتخاب می‌کنیم.
     */
    private function pickBestIdea(): ?ArticleIdea
    {
        return $this->site->articleIdeas()
            ->where('status', '!=', 'used')
            ->whereNotNull('seo_score')
            ->orderByDesc('seo_score')
            ->get()
            ->first(function (ArticleIdea $idea) {
                return ! Article::where('article_idea_id', $idea->id)
                    ->whereNotIn('status', [Article::STATUS_FAILED])
                    ->exists();
            });
    }
}
