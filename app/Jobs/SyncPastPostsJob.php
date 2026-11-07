<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\Wp\PastPostsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/** خواندن یک دسته از پست‌های قدیمی سایت؛ اگر صفحه‌ی دیگری مانده، Job بعدی را خودش در صف می‌گذارد. */
class SyncPastPostsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // زیر retry_after صف؛ خود دسته بعد از ۱۵۰ ثانیه کار را به Job بعدی می‌سپارد
    public int $timeout = 380;

    public function __construct(public int $siteId, public int $page = 1) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-past-posts-'.$this->siteId))->expireAfter($this->timeout + 30)->dontRelease()];
    }

    public function handle(PastPostsService $posts): void
    {
        $site = Site::find($this->siteId);

        if (! $site) {
            return;
        }

        $next = $posts->syncBatch($site, $this->page);

        // کمی بعد، تا قفل WithoutOverlapping همین Job آزاد شده باشد و Job بعدی رد نشود
        if ($next !== null) {
            self::dispatch($this->siteId, $next)->delay(now()->addSeconds(5));
        }
    }
}
