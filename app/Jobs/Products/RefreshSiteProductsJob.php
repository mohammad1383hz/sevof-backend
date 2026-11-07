<?php

namespace App\Jobs\Products;

use App\Models\Site;
use App\Services\Products\SiteProductRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshSiteProductsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 2;
    public int $uniqueFor = 900; // هم‌زمان دو refresh برای یک سایت اجرا نشود

    public function __construct(public int|string $siteId)
    {
        $this->onConnection('redis-background')->onQueue('products'); // worker جدا؛ صف کاربران را پر نکند
    }

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(SiteProductRefreshService $service): void
    {
        $site = Site::find($this->siteId);

        if (! $site) {
            return;
        }

        $service->refresh($site);
    }
}
