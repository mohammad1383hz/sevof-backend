<?php

namespace App\Services\Products;

use App\Jobs\Products\SyncSiteProductsJob;
use App\Models\Site;

class SiteProductUpdateService
{
    public const REFRESH_AFTER_HOURS = 24;

    /**
     * فقط سایت‌هایی که نوبتشان رسیده: هرگز sync نشده، یا آخرین sync بیش از ۲۴ ساعت پیش.
     * سایت‌های غیرفروشگاهی که بررسی شده‌اند اینجا نمی‌آیند (مگر بعد از ۷ روز، برای تشخیص دوباره).
     */
    public function dueSites()
    {
        return Site::query()
            ->whereNotNull('url')
            ->where(function ($q) {
                // فروشگاه‌ها: هر ۲۴ ساعت
                $q->where(function ($q) {
                    $q->where('is_shop', true)
                        ->where(fn ($q) => $q->whereNull('products_synced_at')
                            ->orWhere('products_synced_at', '<=', now()->subHours(self::REFRESH_AFTER_HOURS)));
                })
                // غیرفروشگاه‌ها: هر ۷ روز یک بار چک مجدد
                ->orWhere(function ($q) {
                    $q->where('is_shop', false)
                        ->where(fn ($q) => $q->whereNull('products_synced_at')
                            ->orWhere('products_synced_at', '<=', now()->subDays(7)));
                });
            });
    }

    public function dispatchDue(int $limit = 200): int
    {
        $count = 0;

        $this->dueSites()
            ->orderByRaw('products_synced_at IS NOT NULL, products_synced_at ASC') // قدیمی‌ترین‌ها اول
            ->limit($limit)
            ->pluck('id')
            ->each(function ($id) use (&$count) {
                SyncSiteProductsJob::dispatch($id);
                $count++;
            });

        return $count;
    }
}
