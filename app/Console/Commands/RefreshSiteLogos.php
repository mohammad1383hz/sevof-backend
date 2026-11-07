<?php
// app/Console/Commands/RefreshSiteLogos.php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\AI\SiteLogoResolver;
use Illuminate\Console\Command;

/**
 * پاک‌کردنِ کشِ لوگو/رنگِ یک یا همه‌ی سایت‌ها و دانلودِ دوباره.
 * لازم است بعدِ اضافه‌شدنِ LogoColorExtractor یک‌بار اجرا شود تا سایت‌های
 * قدیمی که فقط URL کش داشتند (بدونِ palette)، دوباره پردازش شوند.
 *
 * استفاده:
 *   php artisan sites:refresh-logos           (همه‌ی سایت‌ها)
 *   php artisan sites:refresh-logos 42        (فقط سایت با id=42)
 */
class RefreshSiteLogos extends Command
{
    protected $signature = 'sites:refresh-logos {site_id? : اگه خالی باشه، همه‌ی سایت‌ها رفرش می‌شن}';

    protected $description = 'کشِ لوگو و پالتِ رنگ رو پاک می‌کنه و دوباره از دامنه دانلود/استخراج می‌کنه';

    public function handle(SiteLogoResolver $resolver): int
    {
        $siteId = $this->argument('site_id');

        $sites = $siteId
            ? Site::where('id', $siteId)->get()
            : Site::whereNotNull('domain')->where('domain', '!=', '')->get();

        if ($sites->isEmpty()) {
            $this->error('هیچ سایتی پیدا نشد.');
            return self::FAILURE;
        }

        $this->info("در حال رفرش برای {$sites->count()} سایت...");
        $bar = $this->output->createProgressBar($sites->count());
        $bar->start();

        $results = [];

        foreach ($sites as $site) {
            try {
                $url = $resolver->resolve($site, forceRefresh: true);
                $palette = $resolver->resolvePalette($site);

                $results[] = [
                    'id' => $site->id,
                    'domain' => $site->domain,
                    'logo' => $url ? 'پیدا شد' : 'پیدا نشد',
                    'palette' => $palette ? implode(', ', $palette) : '(بدون رنگِ قابل‌تشخیص)',
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'id' => $site->id,
                    'domain' => $site->domain,
                    'logo' => 'خطا: '.$e->getMessage(),
                    'palette' => '-',
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['ID', 'Domain', 'لوگو', 'پالت'], $results);

        return self::SUCCESS;
    }
}
