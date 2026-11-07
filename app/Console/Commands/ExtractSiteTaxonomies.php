<?php

namespace App\Console\Commands;

use App\Jobs\SyncSiteWordpressDataJob;
use App\Models\Site;
use App\Services\WordPressTaxonomyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Throwable;

class ExtractSiteTaxonomies extends Command
{
    /**
     * اجرای روزانه (پیشنهادی برای کرون): همه‌ی سایت‌ها رو به صف می‌فرسته و
     * با چند worker به‌صورت موازی پردازش می‌شن.
     * php artisan sites:extract-taxonomies
     *
     * فقط یک سایت خاص:
     * php artisan sites:extract-taxonomies --site=5
     *
     * فقط سایت‌هایی که هنوز اصلاً categories/tags ندارن (اولین بار):
     * php artisan sites:extract-taxonomies --only-new
     *
     * اجرای سریال بدون صف (فقط برای تست/دیباگ چند سایت، توصیه نمی‌شه برای همه‌ی سایت‌ها):
     * php artisan sites:extract-taxonomies --sync
     */
    protected $signature = 'sites:extract-taxonomies
                            {--site= : فقط یک سایت خاص با ID}
                            {--only-new : فقط سایت‌هایی که هنوز categories/tags ندارن (پردازش اولیه)}
                            {--chunk=200 : تعداد رکورد در هر chunk هنگام صف‌بندی}
                            {--sync : اجرای سریال بدون صف (بدون queue worker)، فقط برای تست}
                            {--wait : بعد از صف‌بندی صبر کن تا batch تموم بشه و نتیجه رو نشون بده}';

    protected $description = 'استخراج/به‌روزرسانی categories، tags و ۷۰ مقاله‌ی آخر سایت‌های وردپرسی (idempotent، موازی، مناسب اجرای روزانه)';

    public function handle(WordPressTaxonomyService $service): int
    {
        $siteId  = $this->option('site');
        $onlyNew = (bool) $this->option('only-new');
        $chunk   = (int) $this->option('chunk');
        $sync    = (bool) $this->option('sync');
        $wait    = (bool) $this->option('wait');

        $query = Site::query();

        if ($siteId) {
            $query->where('id', $siteId);
        } elseif ($onlyNew) {
            $query->where(function ($q) {
                $q->whereNull('categories')->orWhereNull('tags');
            });
        }
        // در حالت پیش‌فرض (بدون --site و بدون --only-new): همه‌ی سایت‌ها پردازش می‌شن.
        // sync کاملاً idempotent هست، پس اجرای روزانه روی همه‌ی سایت‌ها مشکلی نداره:
        // - categories/tags: هر بار با آخرین لیست از وردپرس جایگزین می‌شه (نه append)
        // - posts: با updateOrCreate روی (site_id, wp_post_id) => بدون تکراری‌کاری

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('هیچ سایتی برای پردازش پیدا نشد.');

            return self::SUCCESS;
        }

        return $sync
            ? $this->runSync($query, $total, $service)
            : $this->runQueued($query, $total, $chunk, $wait);
    }

    /**
     * اجرای سریال (بدون صف) — هر سایت یکی‌یکی، فقط برای تست چند سایت محدود.
     */
    protected function runSync($query, int $total, WordPressTaxonomyService $service): int
    {
        $this->warn("حالت --sync: {$total} سایت به‌صورت سریال (بدون صف) پردازش می‌شن. برای تعداد زیاد توصیه نمی‌شه.");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success    = 0;
        $failed     = 0;
        $totalPosts = 0;

        $query->chunkById(50, function ($sites) use ($service, &$success, &$failed, &$totalPosts, $bar) {
            foreach ($sites as $site) {
                try {
                    $result = $service->syncSiteWordpressData($site);
                    $totalPosts += $result['posts_count'];
                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->error("خطا برای سایت #{$site->id} ({$site->url}): {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("تمام شد. موفق: {$success} | ناموفق: {$failed} | مجموع پست‌های sync‌شده: {$totalPosts}");

        return self::SUCCESS;
    }

    /**
     * اجرای موازی از طریق صف: هر سایت یک job جدا می‌شه، Bus::batch برای پیگیری
     * پیشرفت استفاده می‌شه. برای اجرای واقعی این job ها باید queue worker(s)
     * در حال اجرا باشن (پایین توضیح داده شده).
     */
    protected function runQueued($query, int $total, int $chunk, bool $wait): int
    {
        $jobs = [];

        $query->chunkById($chunk, function ($sites) use (&$jobs) {
            foreach ($sites as $site) {
                $jobs[] = new SyncSiteWordpressDataJob($site->id);
            }
        });

        $this->info("در حال ساخت batch برای {$total} سایت...");

        $batch = Bus::batch($jobs)
            ->name('sync-site-wordpress-data-' . now()->format('Y-m-d-H-i-s'))
            ->allowFailures()
            ->dispatch();

        $this->info("Batch با ID {$batch->id} ساخته و صف شد.");
        $this->line('برای اجرای واقعی job ها، حداقل یک queue worker باید در حال اجرا باشه، مثلاً:');
        $this->line('  php artisan queue:work --queue=default --tries=2');
        $this->line('برای اجرای موازی بیشتر، چند تا worker همزمان اجرا کن (یا supervisor با numprocs بالا).');
        $this->line('برای دیدن وضعیت batch:');
        $this->line("  php artisan queue:batches --id={$batch->id}   (یا از طریق مدل جدول job_batches)");

        if (! $wait) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('در حال انتظار برای تکمیل batch (--wait)... (نیاز به worker فعال داره)');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $lastProcessed = 0;

        while (! $batch->fresh()->finished()) {
            $fresh = $batch->fresh();
            $advance = $fresh->processedJobs() - $lastProcessed;

            if ($advance > 0) {
                $bar->advance($advance);
                $lastProcessed = $fresh->processedJobs();
            }

            sleep(2);
        }

        $fresh = $batch->fresh();
        $bar->finish();
        $this->newLine(2);

        $this->info("تمام شد. کل: {$fresh->totalJobs} | موفق: " . ($fresh->totalJobs - $fresh->failedJobs) . " | ناموفق: {$fresh->failedJobs}");

        return self::SUCCESS;
    }
}
