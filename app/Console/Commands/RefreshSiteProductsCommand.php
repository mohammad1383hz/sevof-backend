<?php

namespace App\Console\Commands;

use App\Jobs\Products\RefreshSiteProductsJob;
use App\Models\Site;
use App\Services\Products\SiteProductRefreshService;
use Illuminate\Console\Command;
use Throwable;

class RefreshSiteProductsCommand extends Command
{
    protected $signature = 'products:refresh
                            {ids?* : شناسه‌ی یک یا چند سایت (با فاصله یا کاما). بدون مقدار = همه‌ی سایت‌ها}
                            {--queue : برای هر سایت Job روی صف dispatch شود (با فاصله‌ی ۱ ثانیه بین سایت‌ها)}';

    protected $description = 'آپدیت روزانه‌ی قیمت/موجودی و افزودن محصولات جدید (بدون حذف هیچ محصولی)';

    public function handle(SiteProductRefreshService $service): int
    {
        $ids = collect((array) $this->argument('ids'))
            ->flatMap(fn ($v) => explode(',', (string) $v))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values();

        $query = Site::query()->when($ids->isNotEmpty(), fn ($q) => $q->whereKey($ids->all()));

        if ((clone $query)->count() === 0) {
            $this->error('هیچ سایتی پیدا نشد.');

            return self::FAILURE;
        }

        $useQueue = (bool) $this->option('queue');
        $ok = 0;
        $failed = 0;
        $rows = [];
        $n = 0;

        foreach ($query->lazyById(100) as $site) {
            if ($useQueue) {
                // Spread the load so dozens of sites are not hit at the same time.
                RefreshSiteProductsJob::dispatch($site->id)->delay(now()->addSeconds($n++));
                $ok++;

                continue;
            }

            try {
                $r = $service->refresh($site);

                $rows[] = [
                    $site->id,
                    $site->url,
                    $r['mode'] ?? '-',
                    $r['added'] ?? '-',
                    $r['updated'] ?? '-',
                    $r['went_out_of_stock'] ?? '-',
                    $r['back_in_stock'] ?? '-',
                    $r['unchecked'] ?? '-',
                    $r['seconds'] ?? '-',
                ];

                $ok++;
            } catch (Throwable $e) {
                report($e);
                $this->error("  خطا در #{$site->id}: ".$e->getMessage());
                $failed++;
            }
        }

        if ($rows !== []) {
            $this->table(['ID', 'URL', 'Mode', 'Added', 'Updated', 'OutOfStock', 'BackInStock', 'Unchecked', 'Sec'], $rows);
        }

        $this->info(($useQueue ? "صف شد: {$ok}" : "تمام شد. موفق: {$ok} | ناموفق: {$failed}"));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
