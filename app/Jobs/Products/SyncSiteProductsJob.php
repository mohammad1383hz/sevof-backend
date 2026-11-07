<?php

namespace App\Jobs\Products;

use App\Models\Site;
use App\Services\Products\SiteProductService;
use App\Services\Products\SiteProductUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncSiteProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 600;
    public int $uniqueFor = 900; // تا یک سایت هم‌زمان دو بار sync نشود

    public function __construct(public int $siteId, public bool $force = false)
    {
        $this->onConnection('redis-background')->onQueue('products'); // worker جدا؛ صف کاربران را پر نکند
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function backoff(): array
    {
        return [120];
    }

    public function handle(SiteProductService $service): void
    {
        $site = Site::find($this->siteId);

        if (! $site || empty($site->url)) {
            return;
        }
          if (! $this->force
        && $site->products_synced_at
        && $site->products_synced_at->gt(now()->subHours(SiteProductUpdateService::REFRESH_AFTER_HOURS))) {
        return;
    }

        $service->sync($site); // ووکامرس: ۱۰۰ تای اخیر | crawl: ۵۰ تای اخیر، با آپدیت قیمت و افزودن جدیدها
    }

    public function failed(Throwable $e): void
    {
        Log::error('[SyncSiteProductsJob] خطا در آپدیت محصولات', [
            'site_id' => $this->siteId,
            'error' => $e->getMessage(),
        ]);
    }
}
