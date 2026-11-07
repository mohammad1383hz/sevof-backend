<?php

namespace App\Jobs\AgentApi;

use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Models\Banner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublicAgentFinalizeTaskJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;
    public int $timeout = 30;

    public function __construct(public Article $article, public ArticleGenerationTask $task) {}

    public function handle(): void
    {
        $this->article->refresh();
        $bannerReady = $this->article->banners()->where('status', Banner::STATUS_GENERATED)->exists();
        $assetsReady = $this->article->image_status === 'generated'
            && $this->article->banner_status === Banner::STATUS_GENERATED
            && $bannerReady;

        if (! $assetsReady) {
            $this->release(5);
            return;
        }

        if ($this->article->status === Article::STATUS_COMPLETED) {
            $this->task->forceFill(['status' => ArticleGenerationTask::STATUS_COMPLETED, 'error_message' => null])->save();
        }
    }
}
