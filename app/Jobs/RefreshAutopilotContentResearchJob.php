<?php

namespace App\Jobs;

use App\Models\AgentSetting;
use App\Models\ContentResearchSession;
use App\Models\Site;
use App\Services\AI\CompetitorGapResearchService;
use App\Services\AI\ContentResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class RefreshAutopilotContentResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public Site $site)
    {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('autopilot-content-research:'.$this->site->getKey()))
                ->releaseAfter(60)
                ->expireAfter(1800),
        ];
    }

    public function handle(): void
    {
        $site = Site::query()
            ->with(['entity', 'user'])
            ->find($this->site->getKey());

        if (! $site) {
            return;
        }

        $setting = AgentSetting::firstOrCreate(['site_id' => $site->id]);

        if (! $setting->isActive()) {
            return;
        }

        if (! $site->ensureEntity() || ! $site->user) {
            return;
        }

        $dataSource = DB::table('search_console_query_metrics')
            ->where('site_id', $site->id)
            ->exists()
            ? 'gsc_justserp'
            : 'entity_justserp';

        foreach ([
            ContentResearchService::TYPE_KEYWORD_CLUSTERS,
            CompetitorGapResearchService::TYPE_COMPETITOR_GAPS,
        ] as $type) {
            $session = DB::transaction(function () use ($site, $type, $dataSource) {
                $activeSession = ContentResearchSession::query()
                    ->where('site_id', $site->id)
                    ->where('user_id', $site->user_id)
                    ->where('type', $type)
                    ->whereIn('status', ['queued', 'processing'])
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->first();

                if ($activeSession) {
                    return null;
                }

                return ContentResearchSession::create([
                    'site_id' => $site->id,
                    'user_id' => $site->user_id,
                    'type' => $type,
                    'status' => 'queued',
                    'prompt' => null,
                    'data_source' => $dataSource,
                ]);
            });

            if ($session) {
                RunContentResearchJob::dispatch($session);
            }
        }
    }
}
