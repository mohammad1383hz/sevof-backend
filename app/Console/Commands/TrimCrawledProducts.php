<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TrimCrawledProducts extends Command
{
    protected $signature = 'products:trim-crawled
                            {--keep=50 : تعداد محصولی که برای هر سایت crawl شده نگه داشته می‌شود}
                            {--dry-run : فقط گزارش بده، چیزی حذف نکن}';

    protected $description = 'محصولات سایت‌های crawl شده را به ۵۰ تای اخیر برساند و دسته‌های بدون محصول را حذف کند';

    // اگر نام جدول‌ها در پروژه‌ی شما فرق دارد، اینجا عوض کنید
    private const PRODUCTS = 'site_products';
    private const CATEGORIES = 'site_product_categories';

    public function handle(): int
    {
        $keep = max(1, (int) $this->option('keep'));
        $dry = (bool) $this->option('dry-run');

        // همه‌ی سایت‌هایی که حداقل یک محصول crawl شده دارند
        $siteIds = DB::table(self::PRODUCTS)
            ->where('source', 'crawled')
            ->distinct()
            ->pluck('site_id');

        $this->info("تعداد سایت‌های crawl شده: {$siteIds->count()}".($dry ? ' (dry-run)' : ''));

        $totalProducts = 0;
        $totalCategories = 0;

        foreach ($siteIds as $siteId) {
            $result = $dry
                ? $this->process($siteId, $keep, true)
                : DB::transaction(fn () => $this->process($siteId, $keep, false));

            $totalProducts += $result['products'];
            $totalCategories += $result['categories'];

            if ($result['products'] > 0 || $result['categories'] > 0) {
                $this->line("site {$siteId}: {$result['products']} محصول و {$result['categories']} دسته ".($dry ? 'حذف می‌شد' : 'حذف شد'));
            }
        }

        $this->info("جمع کل: {$totalProducts} محصول و {$totalCategories} دسته ".($dry ? 'حذف می‌شد.' : 'حذف شد.'));

        return self::SUCCESS;
    }

    /**
     * @return array{products:int, categories:int}
     */
    private function process(int|string $siteId, int $keep, bool $dry): array
    {
        // ---------- ۱) محصولات crawl شده‌ی اضافه ----------
        // تازه‌ترین‌ها (آخرین آپدیت، بعد بالاترین id) نگه داشته می‌شوند. محصولات ووکامرس دست نمی‌خورند.
        $keepIds = DB::table(self::PRODUCTS)
            ->where('site_id', $siteId)
            ->where('source', 'crawled')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($keep)
            ->pluck('id')
            ->all();

        $deleteQuery = DB::table(self::PRODUCTS)
            ->where('site_id', $siteId)
            ->where('source', 'crawled')
            ->whereNotIn('id', $keepIds);

        $productsDeleted = $dry ? $deleteQuery->count() : $deleteQuery->delete();

        // ---------- ۲) دسته‌های بدون محصول ----------
        // دسته‌ای نگه داشته می‌شود که خودش محصول دارد، یا والدِ (در هر سطح) دسته‌ای است که محصول دارد.
        $used = [];

        $products = DB::table(self::PRODUCTS)
            ->where('site_id', $siteId)
            ->get(['id', 'category_id', 'category_ids']);

        $deletedProductIds = $dry
            ? DB::table(self::PRODUCTS)
                ->where('site_id', $siteId)
                ->where('source', 'crawled')
                ->whereNotIn('id', $keepIds)
                ->pluck('id')
                ->flip()
                ->all()
            : [];

        foreach ($products as $p) {
            if (isset($deletedProductIds[$p->id])) {
                continue; // در dry-run این محصول حذف‌شده حساب می‌شود
            }

            if ($p->category_id) {
                $used[(int) $p->category_id] = true;
            }

            $ids = is_string($p->category_ids) ? json_decode($p->category_ids, true) : $p->category_ids;

            foreach ((array) $ids as $id) {
                if ($id) {
                    $used[(int) $id] = true;
                }
            }
        }

        $categories = DB::table(self::CATEGORIES)
            ->where('site_id', $siteId)
            ->get(['id', 'parent_id'])
            ->keyBy('id');

        $keepCategories = [];

        foreach (array_keys($used) as $id) {
            while ($id && isset($categories[$id]) && ! isset($keepCategories[$id])) {
                $keepCategories[$id] = true;
                $id = $categories[$id]->parent_id ? (int) $categories[$id]->parent_id : null;
            }
        }

        $toDelete = $categories->keys()
            ->reject(fn ($id) => isset($keepCategories[$id]))
            ->values()
            ->all();

        if (! $dry && $toDelete !== []) {
            // اول parent_id همه‌ی دسته‌های حذفی null شود تا ترتیب حذف (والد قبل از فرزند) خطای foreign key ندهد
            foreach (array_chunk($toDelete, 500) as $chunk) {
                DB::table(self::CATEGORIES)->whereIn('id', $chunk)->update(['parent_id' => null]);
            }

            // محصولی که هنوز به این دسته‌ها اشاره می‌کند وجود ندارد (در بالا حساب شد)، پس حذف امن است
            foreach (array_chunk($toDelete, 500) as $chunk) {
                DB::table(self::CATEGORIES)->whereIn('id', $chunk)->delete();
            }
        }

        return ['products' => $productsDeleted, 'categories' => count($toDelete)];
    }
}
