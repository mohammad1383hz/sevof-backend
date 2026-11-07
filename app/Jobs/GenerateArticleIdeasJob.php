<?php

namespace App\Jobs;

use App\Models\ArticleIdea;
use App\Models\EntityKeyword;
use App\Models\Site;
use App\Services\AI\ArticleIdeaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateArticleIdeasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(
        public Site $site,
        public int $batch,
        public string $mode = 'trend_opportunities',
        public string $prompt = '',
        public ?string $entityName = null,
        public ?string $entityScope = null,
        public string $source = 'user', // ⬅️ خط جدید — پیش‌فرض user، سمت agent صراحتاً 'agent' پاس داده می‌شه
    ) {}

    public function handle(ArticleIdeaService $service): void
    {
        try {
            app()->bind('current_ai_user', fn () => $this->site->user);

            $this->site->update([
                'idea_generation_status' => 'generating_ideas',
                'idea_generation_error'  => null,
            ]);

            $keywords = $this->loadStoredKeywords();
            $ideas = $service->generate(
                $this->site,
                $keywords,
                $this->prompt,
                $this->entityName,
                $this->entityScope,
            );

            DB::transaction(function () use ($ideas) {
                foreach ($ideas as $idea) {
                    // Log::info($idea['category']);
                    // Log::info($idea['tags']);

                    ArticleIdea::create([
                        'website_id'       => $this->site->id,
                        'batch'            => $this->batch,
                        'source'           => $this->source, // ⬅️ خط جدید — user یا agent
                        'title'            => $idea['title'],
                        'meta_description' => $idea['meta_description'] ?? null,
                        'hook'             => $idea['hook'] ?? null,
                        'entity_name'      => $idea['entity_name'],
                        'entity_scope'     => $idea['entity_scope'],
                        'entity_type'      => $idea['entity_type'] ?? null,
                        'search_intent'    => $idea['search_intent'] ?? null,
                            'category' => $idea['category'] ?? null,
    'tags'     => $idea['tags'] ?? [],

                        'target_url'       => $idea['target_url'] ?? null,
                        'target_url_title' => $idea['target_url_title'] ?? null,
                        'priority'         => $idea['priority'] ?? 50,
                        'outline'          => $idea['outline'] ?? [],
                        'status'           => 'suggested',
                        'ai_raw_response'  => [
                            ...$idea,
                            'generation_mode' => $this->mode,
                            'selected_entity_name' => $this->entityName,
                            'selected_entity_scope' => $this->entityScope,
                        ],
                    ]);
                }
            });

DetectArticleInternalLinksJob::dispatch($this->site, $this->batch);        } catch (Throwable $exception) {
            report($exception);
            $this->site->update(['idea_generation_error' => $exception->getMessage()]);
        }
    }

    protected function loadStoredKeywords(): array
    {
        $query = EntityKeyword::where('site_id', $this->site->id);

        if ($this->entityName !== null && trim($this->entityName) !== '') {
            $query->where('entity_name', $this->entityName);
        }

        if ($this->entityScope !== null && trim($this->entityScope) !== '') {
            $query->where('entity_scope', $this->entityScope);
        }

        return $query->get()->map(fn (EntityKeyword $k) => [
                'entity_scope'    => $k->entity_scope,
                'entity_name'     => $k->entity_name,
                'base_keyword'    => $k->base_keyword,
                'total_relevance' => $k->total_relevance,
                'source_variants' => $k->source_variants,
            ])
            ->all();
    }
}
