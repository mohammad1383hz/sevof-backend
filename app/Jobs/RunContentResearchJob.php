<?php

namespace App\Jobs;

use App\Models\ContentResearchSession;
use App\Services\AI\CompetitorGapResearchService;
use App\Services\AI\ContentResearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunContentResearchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 900;

    public function __construct(public ContentResearchSession $session)
    {
    }

    public function handle(
        ContentResearchService $keywordClusterService,
        CompetitorGapResearchService $competitorGapService,
    ): void {
        try {
            app()->bind('current_ai_user', fn () => $this->session->user);

            $this->session->update([
                'status' => 'processing',
                'error' => null,
            ]);

            $service = $this->session->type === CompetitorGapResearchService::TYPE_COMPETITOR_GAPS
                ? $competitorGapService
                : $keywordClusterService;

            $service->run($this->session);
        } catch (Throwable $exception) {
            report($exception);

            $this->session->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
