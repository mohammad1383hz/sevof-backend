<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditException;
use App\Models\Article;
use App\Models\Banner;
use App\Services\AI\BannerGeneratorService;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class GenerateArticleBannerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [10, 30];
    public int $timeout = 120;

    public function __construct(
        public Article $article,
        public bool $force = false,
    ) {}

    public function handle(BannerGeneratorService $bannerGenerator, SubscriptionService $subscriptionService): void
    {
        $site = $this->article->site;
        $user = $site->user;

        app()->bind('current_ai_user', fn () => $user);

        // ۱) خودِ مقاله از قبل بنر آماده دارد
        if (! $this->force
            && $this->article->banners()->where('status', Banner::STATUS_GENERATED)->exists()) {
            $this->article->update(['banner_status' => Banner::STATUS_GENERATED]);
            return;
        }

        // lock روی سایت تا دو job هم‌زمان برای یک سایت، دو بنر نسازند
        Cache::lock("site-banner:{$site->id}", 150)->block(60, function () use ($site, $user, $bannerGenerator, $subscriptionService) {

            // ۲) سایت بنر دارد → یکی را رندوم وصل کن (بدون تولید، بدون کم شدن اعتبار)
            if (! $this->force) {
                $random = Banner::query()
                    ->where('site_id', $site->id)
                    ->where('status', Banner::STATUS_GENERATED)
                    ->inRandomOrder()
                    ->first();

                if ($random) {
                    $this->article->banners()->sync([$random->id]);
                    $this->article->update(['banner_status' => Banner::STATUS_GENERATED]);
                    return;
                }
            }

            // ۳) بنری وجود ندارد (یا force) → تولید جدید
            if (! $subscriptionService->hasAvailableCredit($user)) {
                $this->fail(new InsufficientCreditException());
                return;
            }

            $this->article->update(['banner_status' => 'generating']);

            try {
                $banner = $bannerGenerator->generateForArticle($this->article);

                $this->article->banners()->sync([$banner->id]);
                $this->article->update(['banner_status' => $banner->status]);
            } catch (Throwable $e) {
                if ($this->attempts() >= $this->tries) {
                    $this->article->update(['banner_status' => 'failed']);
                }

                throw $e;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof InsufficientCreditException) {
            return;
        }

        $this->article->update(['banner_status' => 'failed']);
    }
}
