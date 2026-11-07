<?php

namespace App\Jobs\AgentApi;

use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Models\Site;
use App\Services\AI\EntitySelectionService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class PublicAgentStartJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public Site $site, public ArticleGenerationTask $task) {}

    public function handle(EntitySelectionService $selector, SubscriptionService $subscriptions): void
    {
        $user = $this->site->user;
        if (! $user || ! $subscriptions->getActiveSubscription($user) || ! $subscriptions->hasAvailableCredit($user)) {
            throw new RuntimeException('Agent preflight checks failed.');
        }

        // اگر ایده‌ی آماده‌ی استفاده‌نشده داریم، مستقیم سراغ ساخت مقاله می‌رویم
        $hasIdea = $this->site->articleIdeas()
            ->where('status', '!=', 'used')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->contains(fn ($idea) => ! Article::where('article_idea_id', $idea->id)
                ->where('status', '!=', Article::STATUS_FAILED)->exists());

        if ($hasIdea) {
            PublicAgentCreateArticleJob::dispatch($this->site, $this->task);
            return;
        }

        $entity = $selector->pickAutomationEntity($this->site);
        $batch = $this->site->reserveIdeaBatch();
        $this->site->update([
            'idea_generation_status' => 'connecting_sources',
            'idea_generation_error' => null,
            'idea_generation_mode' => 'public_api_agent',
        ]);

        PublicAgentDiscoverKeywordsJob::dispatch($this->site, $this->task, $batch, $entity['name'], $entity['scope']);
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->forceFill([
            'status' => ArticleGenerationTask::STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ])->save();
    }
}
