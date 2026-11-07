<?php

namespace App\Jobs\AgentApi;

use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Models\ArticleIdea;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class PublicAgentCreateArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public Site $site, public ArticleGenerationTask $task) {}

    public function handle(): void
    {
        // بهترین ایده‌ی استفاده‌نشده بر اساس priority (بدون نیاز به seo_score)
        $idea = $this->site->articleIdeas()
            ->where('status', '!=', 'used')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->first(fn (ArticleIdea $candidate) => ! Article::where('article_idea_id', $candidate->id)
                ->where('status', '!=', Article::STATUS_FAILED)->exists());

        if (! $idea) {
            throw new RuntimeException('No article idea is available.');
        }

        $article = Article::create([
            'article_idea_id' => $idea->id,
            'website_id' => $this->site->id,
            'title' => $idea->title,
            'meta_description' => $idea->meta_description,
            'status' => Article::STATUS_PENDING,
        ]);

        $this->task->forceFill(['article_id' => $article->getKey()])->save();

        PublicAgentWriteArticleJob::dispatch($article, $this->task);
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->forceFill([
            'status' => ArticleGenerationTask::STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ])->save();
    }
}
