<?php

namespace App\Jobs;

use App\Models\EntityKeyword;
use App\Models\Site;
use App\Services\Research\EntityKeywordSuggestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchEntityKeywordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(
        public Site $site,
        public int $batch,
        public string $mode = 'trend_opportunities',
        public string $prompt = '',
        public ?string $entityName = null,
        public ?string $entityScope = null,
    ) {}

    public function handle(EntityKeywordSuggestionService $service): void
    {
        try {
            $this->site->update([
                'idea_generation_status' => 'connecting_sources',
                'idea_generation_error'  => null,
            ]);

            // اگر برای این سایت قبلاً entity keyword ساخته شده، دیگه نیازی به
            // فراخوانی دوباره‌ی JustSerpAPI + AI نیست؛ از داده‌های موجود استفاده کن.
            $alreadyHasKeywords = EntityKeyword::where('site_id', $this->site->id)->exists();

            if ($alreadyHasKeywords) {
                Log::info("EntityKeywords already exist for site_id={$this->site->id}. Skipping JustSerpAPI/AI fetch.");
            } else {
                app()->bind('current_ai_user', fn () => $this->site->user);

                $keywords = $service->generate($this->site, $this->entityName, $this->entityScope);

                DB::transaction(function () use ($keywords) {
                    EntityKeyword::where('site_id', $this->site->id)->delete();

                    foreach ($keywords as $keyword) {
                        if (empty($keyword['base_keyword'])) continue;

                        EntityKeyword::create([
                            'site_id'         => $this->site->id,
                            'entity_scope'    => $keyword['entity_scope'],
                            'entity_name'     => $keyword['entity_name'],
                            'base_keyword'    => $keyword['base_keyword'],
                            'total_relevance' => $keyword['total_relevance'] ?? 0,
                            'source_variants' => $keyword['source_variants'] ?? [],
                        ]);
                    }
                });
            }

            $this->site->update(['idea_generation_status' => 'analyzing_keywords']);

            GenerateArticleIdeasJob::dispatch(
                $this->site,
                $this->batch,
                $this->mode,
                $this->prompt,
                $this->entityName,
                $this->entityScope,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->site->update(['idea_generation_error' => $exception->getMessage()]);
        }
    }
}
