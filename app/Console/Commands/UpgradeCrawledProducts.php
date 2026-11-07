<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Products\SiteProductService;
use Illuminate\Console\Command;

/**
 * مسیر: app/Console/Commands/UpgradeCrawledProducts.php
 *
 * php artisan products:upgrade-crawled omdekoler.ir --dry-run
 * php artisan products:upgrade-crawled omdekoler.ir
 * php artisan products:upgrade-crawled omdekoler.ir --protect
 */
class UpgradeCrawledProducts extends Command
{
    protected $signature = 'products:upgrade-crawled {domain}
        {--dry-run : فقط گزارش بده، چیزی تغییر نکند}
        {--protect : ردیف‌های ارتقایافته is_manual شوند تا sync آن‌ها را به‌خاطر «خارج از ۱۰۰ اخیر» پاک نکند}';

    protected $description = 'محصولات crawled یک سایت ووکامرسی را بدون حذف، درجا به woocommerce (با external_id واقعی) تبدیل می‌کند';

    public function handle(SiteProductService $service): int
    {
        $domain = preg_replace('#^https?://|/.*$#i', '', (string) $this->argument('domain'));
        $site = Site::where('url', 'like', '%'.$domain.'%')->first();

        if (! $site) {
            $this->error('سایت در دیتابیس پیدا نشد.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $this->info(sprintf('site #%d (%s)%s', $site->id, $site->url, $dry ? ' [dry-run]' : ''));

        try {
            $r = $service->upgradeCrawledToWoocommerce($site, (bool) $this->option('protect'), $dry);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['مورد', 'تعداد'], [
            ['کل ردیف‌های crawled', $r['total']],
            [$dry ? 'قابل ارتقا' : 'ارتقا یافت', $r['upgraded']],
            ['ناموجود (دست نخورد؛ sync حذفشان می‌کند)', $r['outofstock']],
            ['تداخل slug (ردیف woocommerce دیگری هست)', count($r['clash'])],
            ['پیدا نشد در Store API', count($r['unmatched'])],
        ]);

        $this->line('لیست کامل محصولات Store API گرفته شد: '.($r['complete'] ? 'بله' : 'خیر (ناقص)'));

        foreach (array_slice($r['clash'], 0, 10) as $c) {
            $this->warn(sprintf('  تداخل: ردیف #%d ← slug "%s" قبلاً مال ردیف #%d است', $c['id'], $c['slug'], $c['existing_id']));
        }

        foreach (array_slice($r['unmatched'], 0, 10) as $u) {
            $this->warn(sprintf('  پیدا نشد: #%d %s', $u['id'], $u['url']));
        }

        return self::SUCCESS;
    }
}
