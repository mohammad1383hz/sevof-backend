<?php

namespace App\Jobs\AgentApi;

use App\Jobs\AgentApi\PublicAgentGenerateIdeasJob;
use App\Models\ArticleGenerationTask;
use App\Models\EntityKeyword;
use App\Models\Site;
use App\Services\Research\EntityKeywordSuggestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublicAgentDiscoverKeywordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public Site $site,
        public ArticleGenerationTask $task,
        public int $batch,
        public string $entityName,
        public string $entityScope,
    ) {}

    public function handle(EntityKeywordSuggestionService $service): void
    {
        app()->bind('current_ai_user', fn () => $this->site->user);
        $keywords = $service->generate($this->site, $this->entityName, $this->entityScope);

        DB::transaction(function () use ($keywords): void {
            foreach ($keywords as $keyword) {
                if (empty($keyword['base_keyword'])) continue;
                EntityKeyword::updateOrCreate(
                    ['site_id' => $this->site->id, 'base_keyword' => $keyword['base_keyword']],
                    [
                        'entity_scope' => $keyword['entity_scope'] ?? $this->entityScope,
                        'entity_name' => $keyword['entity_name'] ?? $this->entityName,
                        'total_relevance' => $keyword['total_relevance'] ?? 0,
                        'source_variants' => $keyword['source_variants'] ?? [],
                    ],
                );
            }
        });

        PublicAgentGenerateIdeasJob::dispatch($this->site, $this->task, $this->batch, $this->entityName, $this->entityScope);
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->forceFill(['status' => ArticleGenerationTask::STATUS_FAILED, 'error_message' => $exception?->getMessage()])->save();
    }
}
