<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Wp\PastPostsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** بررسی سئوی متن پست‌های قدیمی سایت، دسته به دسته؛ اگر پستی مانده، Job بعدی را خودش در صف می‌گذارد. */
class AuditPastPostsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // زیر retry_after صف پس‌زمینه؛ خود دسته بعد از ۱۵۰ ثانیه کار را به Job بعدی می‌سپارد
    public int $timeout = 380;

    public function __construct(public int $siteId)
    {
        $this->onConnection('redis-background'); // کار طولانی کاربر، صف اصلی را پر نکند
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('audit-past-posts-'.$this->siteId))->expireAfter($this->timeout + 30)->dontRelease()];
    }

    public function handle(PastPostsService $posts): void
    {
        $site = Site::find($this->siteId);

        if ($site && $posts->auditBatch($site)) {
            self::dispatch($this->siteId)->delay(now()->addSeconds(10));
        }
    }
}
