<?php

namespace App\Jobs\AgentApi;

use App\Models\Article;
use App\Models\ArticleGenerationTask;
use App\Models\Banner;
use App\Services\AI\BannerGeneratorService;
use App\Services\AI\SiteLogoResolver;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublicAgentGenerateBannerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 30];
    public int $timeout = 180;

    public function __construct(public Article $article, public ArticleGenerationTask $task) {}

    public function handle(
        BannerGeneratorService $bannerGenerator,
        SiteLogoResolver $logoResolver,
        SubscriptionService $subscriptions,
    ): void
    {
        app()->bind('current_ai_user', fn () => $this->article->site->user);
        $user = $this->article->site->user;
        if (! $subscriptions->hasAvailableCredit($user)) throw new \RuntimeException('No generation credit is available for banner.');

        $this->article->update(['banner_status' => Banner::STATUS_GENERATING]);
        try {
            // BannerGeneratorService هم همین resolver را درون خود استفاده می‌کند؛
            // این resolve صریح تضمین می‌کند لوگوی واقعی سایت قبل از ساخت Banner آماده باشد.
            $logoUrl = $logoResolver->resolve($this->article->site);
            $banner = $bannerGenerator->generateForArticle($this->article);
            if ($logoUrl && $banner->logo_url !== $logoUrl) {
                $banner->update(['logo_url' => $logoUrl]);
            }
            $this->article->banners()->sync([$banner->id]);
            $this->article->update(['banner_status' => $banner->status]);
            if ($banner->status !== Banner::STATUS_GENERATED) throw new \RuntimeException('Banner generation did not complete.');
            PublicAgentFinalizeTaskJob::dispatch($this->article, $this->task);
        } catch (Throwable $exception) {
            $this->article->update(['banner_status' => Banner::STATUS_FAILED]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->article->update(['banner_status' => Banner::STATUS_FAILED]);
        $this->task->forceFill(['status' => ArticleGenerationTask::STATUS_FAILED, 'error_message' => $exception?->getMessage()])->save();
    }
}
