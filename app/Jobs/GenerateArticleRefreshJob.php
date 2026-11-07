<?php

namespace App\Jobs;

use App\Models\ArticleRefresh;
use App\Services\AI\ArticleRefreshService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateArticleRefreshJob implements ShouldQueue
{
    use Queueable;

    // هر تلاش دوباره یعنی پرداخت دوباره‌ی کریدیت؛ خطا به کاربر نشان داده می‌شود و خودش دوباره می‌سازد
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $refreshId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('article-refresh-'.$this->refreshId))->expireAfter($this->timeout + 60)->dontRelease()];
    }

    public function handle(): void
    {
        $refresh = ArticleRefresh::find($this->refreshId);

        if (! $refresh || $refresh->status !== ArticleRefresh::STATUS_QUEUED) {
            return;
        }

        ArticleRefreshService::for($refresh)->generate($refresh);
    }

    public function failed(?Throwable $exception): void
    {
        $refresh = ArticleRefresh::find($this->refreshId);

        if ($refresh) {
            ArticleRefreshService::for($refresh)->markFailed($refresh, $exception?->getMessage() ?? 'Unknown error');
        }
    }
}
