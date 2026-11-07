<?php

namespace App\Console\Commands;

use App\Jobs\Products\SyncSiteProductsJob;
use App\Models\Site;
use App\Services\Products\SiteProductService;
use Illuminate\Console\Command;
use Throwable;

class SyncSiteProductsCommand extends Command
{
    protected $signature = 'products:sync
                            {ids?* : شناسه‌ی یک یا چند سایت (با فاصله یا کاما). بدون مقدار = همه‌ی سایت‌ها}
                            {--queue : به‌جای اجرای مستقیم، برای هر سایت Job روی صف dispatch شود}';

    protected $description = 'همگام‌سازی محصولات و دسته‌بندی‌ها برای همه‌ی سایت‌ها یا سایت‌های مشخص';

    public function handle(SiteProductService $service): int
    {
        $ids = collect((array) $this->argument('ids'))
            ->flatMap(fn ($v) => explode(',', (string) $v))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values();

        $query = Site::query()->when($ids->isNotEmpty(), fn ($q) => $q->whereKey($ids->all()));

        if ($ids->isNotEmpty()) {
            $found = Site::whereKey($ids->all())->pluck('id')->map(fn ($id) => (string) $id);
            $missing = $ids->diff($found);

            if ($missing->isNotEmpty()) {
                $this->warn('سایتی با این شناسه‌ها پیدا نشد: '.$missing->implode(', '));
            }
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->error('هیچ سایتی برای همگام‌سازی پیدا نشد.');

            return self::FAILURE;
        }

        $useQueue = (bool) $this->option('queue');
        $this->info(($useQueue ? 'صف‌کردن' : 'همگام‌سازی')." {$total} سایت...");

        $ok = 0;
        $failed = 0;
        $rows = [];

        foreach ($query->lazyById(100) as $site) {
            $label = "#{$site->id} {$site->url}";

            if ($useQueue) {
SyncSiteProductsJob::dispatch($site->id);
                $this->line("  صف شد: {$label}");
                $ok++;

                continue;
            }

            $this->line("  در حال بررسی: {$label}");
            $startedAt = microtime(true);

            try {
                $result = $service->sync($site);

                $rows[] = [
                    $site->id,
                    $site->url,
                    $result['isWordpress'] ? 'yes' : 'no',
                    $result['isWoocommerce'] ? 'yes' : 'no',
                    $result['categoriesCount'],
                    $result['productsCount'],
                    $result['complete'] ? 'yes' : 'NO',
                    round(microtime(true) - $startedAt, 1),
                ];

                $ok++;
            } catch (Throwable $e) {
                report($e);
                $this->error("  خطا در {$label}: ".$e->getMessage());
                $failed++;
            }
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['ID', 'URL', 'WP', 'Woo', 'Cats', 'Products', 'Complete', 'Sec'], $rows);
        }

        $this->info("تمام شد. موفق: {$ok} | ناموفق: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
