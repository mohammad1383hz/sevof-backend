<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\WordPressTaxonomyService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSiteWordpressDataJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * تعداد تلاش مجدد در صورت شکست (مثلاً تایم‌اوت شبکه)
     */
    public int $tries = 2;

    /**
     * حداکثر زمان مجاز اجرای هر job (ثانیه). چون داخلش از Http::pool
     * استفاده می‌شه (۳ ریکوئست هم‌زمان با timeout=10)، ۶۰ ثانیه کاملاً کافیه.
     */
    public int $timeout = 60;

    /**
     * فاصله‌ی زمانی قبل از retry (ثانیه)
     */
    public int $backoff = 10;

    public function __construct(public int $siteId)
    {
    }

    public function handle(WordPressTaxonomyService $service): void
    {
        // اگه batch لغو شده باشه، ادامه نده
        if ($this->batch()?->cancelled()) {
            return;
        }

        $site = Site::find($this->siteId);

        if (! $site) {
            return;
        }

        try {
            $result = $service->syncSiteWordpressData($site);

            Log::info("SyncSiteWordpressDataJob: site #{$site->id} synced", $result);
        } catch (Throwable $e) {
            Log::warning("SyncSiteWordpressDataJob: failed for site #{$site->id} ({$site->url}): {$e->getMessage()}");

            // اجازه بده Laravel خودش retry رو مدیریت کنه (طبق $tries)
            throw $e;
        }
    }

    /**
     * اگه بعد از همه‌ی تلاش‌ها هم شکست خورد، اینجا لاگ نهایی ثبت می‌شه
     */
    public function failed(Throwable $exception): void
    {
        Log::error("SyncSiteWordpressDataJob: permanently failed for site #{$this->siteId}: {$exception->getMessage()}");
    }
}
