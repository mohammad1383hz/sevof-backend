<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * php artisan products:diagnose omdekoler.ir --product="https://omdekoler.ir/product/..."
 * مسیر: app/Console/Commands/DiagnoseSiteProducts.php
 */
class DiagnoseSiteProducts extends Command
{
    protected $signature = 'products:diagnose {domain} {--product= : آدرس یک صفحه‌ی محصول}';
    protected $description = 'بررسی اینکه چرا یک سایت ووکامرسی تشخیص داده نمی‌شود / crawl می‌شود';

    private const UA = 'Mozilla/5.0 (compatible; SiteProductSync/1.0)';

    public function handle(): int
    {
        $domain = preg_replace('#^https?://|/.*$#i', '', (string) $this->argument('domain'));
        $root = 'https://'.$domain;

        $this->info("== probe مستقیم از این سرور: $root");

        $wp = $this->probe('wp-json root', $root.'/wp-json/', ['_fields' => 'name']);
        $s1 = $this->probe('store per_page=1', $root.'/wp-json/wc/store/v1/products', ['per_page' => 1]);
        $s100 = $this->probe('store per_page=100 (sync)', $root.'/wp-json/wc/store/v1/products', [
            'stock_status' => ['instock'], 'orderby' => 'date', 'order' => 'desc', 'per_page' => 100, 'page' => 1,
        ]);
        $rr = $this->probe('rest_route per_page=1', $root.'/', ['rest_route' => '/wc/store/v1/products', 'per_page' => 1]);

        if ($url = $this->option('product')) {
            $this->newLine();
            $this->info('== صفحه‌ی محصول');
            try {
                $r = Http::withUserAgent(self::UA)->connectTimeout(8)->timeout(30)->get($url);
                $html = $r->body();
                $id = null;
                foreach ([
                    '/<body[^>]*class=["\'][^"\']*\bpostid-(\d+)\b/i',
                    '/<link[^>]+rel=["\']shortlink["\'][^>]*href=["\'][^"\']*[?&]p=(\d+)/i',
                    '/name=["\']add-to-cart["\'][^>]*value=["\'](\d+)["\']/i',
                ] as $p) {
                    if (preg_match($p, $html, $m)) { $id = $m[1]; break; }
                }
                $this->line(sprintf('HTTP %d | JSON-LD: %s | WooCommerce ID: %s',
                    $r->status(), stripos($html, 'ld+json') !== false ? 'yes' : 'NO', $id ?? 'پیدا نشد'));
            } catch (\Throwable $e) {
                $this->error('FAILED: '.class_basename($e).' '.mb_substr($e->getMessage(), 0, 120));
            }
        }

        $this->newLine();
        $this->info('== وضعیت دیتابیس');
        $site = Site::where('url', 'like', '%'.$domain.'%')->first();

        if ($site) {
            $this->line(sprintf('site #%d | wordpress=%s woocommerce=%s shop=%s | synced=%s',
                $site->id, (int) $site->is_wordpress, (int) $site->is_woocommerce, (int) $site->is_shop,
                $site->products_synced_at ?? '-'));

            foreach (SiteProduct::where('site_id', $site->id)->selectRaw('source, is_manual, count(*) c')->groupBy('source', 'is_manual')->get() as $g) {
                $this->line(sprintf('  source=%-12s manual=%d  count=%d', $g->source, (int) $g->is_manual, $g->c));
            }
        } else {
            $this->warn('سایت در دیتابیس پیدا نشد.');
        }

        $this->newLine();
        $this->info('== نتیجه');

        if ($s100 && $s1 && $s100['list']) {
            $this->line('Store API سالم است. اگر باز هم crawl شده، sync قدیمی بوده؛ بعد از اعمال تغییرات force sync کن.');
        } elseif ($s1 && $s1['list'] && ! ($s100['list'] ?? false)) {
            $this->line('علت: صفحه‌ی ۱۰۰تایی خطا/timeout می‌دهد ولی API کار می‌کند. → تغییر ۵ و ۷ (perPage=20).');
        } elseif ($rr && $rr['list']) {
            $this->line('علت: /wp-json بسته/خراب است و فقط ?rest_route کار می‌کند. → تغییر ۳، ۴ و ۷.');
        } elseif ($wp && ! $wp['json'] && ($s1['list'] ?? false)) {
            $this->line('علت: /wp-json/ ریشه بسته است ولی Store API باز است. → تغییر ۷ (وابستگی به isWordpress حذف می‌شود).');
        } else {
            $codes = collect([$wp, $s1, $s100, $rr])->map(fn ($x) => $x['status'] ?? 'ERR')->implode(', ');
            $this->warn("هیچ مسیری لیست محصولات نداد (کدها: $codes).");
            $this->line('۴۰۳/۵۰۳ = WAF یا افزونه‌ی امنیتی (IP/UA سرور را whitelist کن یا Store API را فعال کن)؛ ERR = connection error.');
            $this->line('اگر ووکامرس نصب است ولی Store API غیرفعال شده، این سایت اصلاً از طریق sync قابل ویرایش نمی‌شود.');
        }

        return self::SUCCESS;
    }

    private function probe(string $label, string $url, array $query = []): ?array
    {
        $t = microtime(true);

        try {
            $r = Http::withUserAgent(self::UA)->acceptJson()->connectTimeout(8)->timeout(30)->get($url, $query);
            $json = $r->json();
            $out = ['status' => $r->status(), 'json' => is_array($json), 'list' => $r->successful() && is_array($json) && array_is_list($json)];

            $this->line(sprintf('%-26s HTTP %d | %dms | json:%s | list:%s | pages:%s',
                $label, $r->status(), (microtime(true) - $t) * 1000, $out['json'] ? 'yes' : 'NO',
                $out['list'] ? 'yes' : 'NO', $r->header('X-WP-TotalPages') ?: '-'));

            if (! $out['list']) {
                $this->warn('   → '.mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($r->body()))), 0, 160));
            }

            return $out;
        } catch (\Throwable $e) {
            $this->error(sprintf('%-26s FAILED: %s %s (%dms)', $label, class_basename($e),
                mb_substr($e->getMessage(), 0, 100), (microtime(true) - $t) * 1000));

            return null;
        }
    }
}
