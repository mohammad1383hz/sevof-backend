<?php

namespace App\Jobs\AgentApi;

use App\Models\ArticleGenerationTask;
use App\Models\ArticleIdea;
use App\Models\EntityKeyword;
use App\Models\Site;
use App\Services\AI\ArticleIdeaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublicAgentGenerateIdeasJob implements ShouldQueue
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

    public function handle(ArticleIdeaService $service): void
    {
        app()->bind('current_ai_user', fn () => $this->site->user);

        $keywords = EntityKeyword::where('site_id', $this->site->id)
            ->where('entity_name', $this->entityName)
            ->where('entity_scope', $this->entityScope)
            ->get()
            ->map(fn (EntityKeyword $keyword) => [
                'entity_scope' => $keyword->entity_scope,
                'entity_name' => $keyword->entity_name,
                'base_keyword' => $keyword->base_keyword,
                'total_relevance' => $keyword->total_relevance,
                'source_variants' => $keyword->source_variants,
            ])->all();

        $ideas = $service->generate($this->site, $keywords, '', $this->entityName, $this->entityScope);

        DB::transaction(function () use ($ideas): void {
            foreach ($ideas as $idea) {
                ArticleIdea::create([
                    'website_id' => $this->site->id,
                    'batch' => $this->batch,
                    'title' => $idea['title'],
                    'meta_description' => $idea['meta_description'] ?? null,
                    'hook' => $idea['hook'] ?? null,
                    'entity_name' => $idea['entity_name'] ?? $this->entityName,
                    'entity_scope' => $idea['entity_scope'] ?? $this->entityScope,
                    'entity_type' => $idea['entity_type'] ?? null,
                    'search_intent' => $idea['search_intent'] ?? null,
                    'target_url' => $idea['target_url'] ?? null,
                    'target_url_title' => $idea['target_url_title'] ?? null,
                    'priority' => $idea['priority'] ?? 50,
                    'outline' => $idea['outline'] ?? [],
                    'status' => 'suggested',
                    'ai_raw_response' => $idea,
                ]);
            }
        });

        // امتیازدهی حذف شد؛ مستقیم می‌رویم سراغ ساخت مقاله
        PublicAgentCreateArticleJob::dispatch($this->site, $this->task);
    }

    public function failed(?Throwable $exception): void
    {
        $this->task->forceFill([
            'status' => ArticleGenerationTask::STATUS_FAILED,
            'error_message' => $exception?->getMessage(),
        ])->save();
    }
}
