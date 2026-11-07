<?php

namespace App\Jobs;

use App\Models\ArticleRefresh;
use App\Services\AI\ArticleRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/** انتشار نسخه‌ی به‌روزشده روی سایت (apply) یا برگرداندن نسخه‌ی قبلی (revert). */
class ApplyArticleRefreshJob implements ShouldQueue
{
    use Queueable;

    // انتشار ممکن است قبل از timeout به سایت رسیده باشد؛ مثل PublishArticleJob تکرار خودکار نداریم
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $refreshId, public string $action = 'apply') {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('article-refresh-'.$this->refreshId))->expireAfter($this->timeout + 60)->dontRelease()];
    }

    public function handle(): void
    {
        $refresh = ArticleRefresh::find($this->refreshId);

        if (! $refresh || $refresh->status !== ArticleRefresh::STATUS_APPLYING) {
            return;
        }

        $service = ArticleRefreshService::for($refresh);
        $this->action === 'revert' ? $service->revert($refresh) : $service->apply($refresh);
    }

    public function failed(?Throwable $exception): void
    {
        $refresh = ArticleRefresh::find($this->refreshId);

        if ($refresh?->status === ArticleRefresh::STATUS_APPLYING) {
            $refresh->update([
                'status' => $this->action === 'revert' ? ArticleRefresh::STATUS_APPLIED : ArticleRefresh::STATUS_READY,
                'error' => 'Publishing failed: '.mb_substr($exception?->getMessage() ?? 'Unknown error', 0, 500),
            ]);
        }
    }
}
