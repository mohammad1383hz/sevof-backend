<?php

namespace App\Services\Products\PageProduct;

use App\Models\PageProduct\PageProductSeoOptimization;
use App\Models\SiteProductCategory;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\ContentLanguage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * اجرای فلو تا مرحله‌ی پیش‌نمایش:
 * نقص‌ها (Laravel) → تولید (یک فراخوانی AI برای هر محصول) → کنترل کیفیت.
 *
 * سرچ اینترنتی و کلاسیفایر حذف شده‌اند؛ همه‌ی محصولات از یک مسیر می‌گذرند
 * و اطلاعات بر اساس داده‌ی خود محصول و دانش مطمئن مدل تولید می‌شود.
 */
class PageProductSeoPipelineService
{
    private const NO_CREDIT = 'You don\'t have enough credit left.';

    public function __construct(
        protected PageProductGapDetectionService $gaps,
        protected PageProductContentGeneratorService $generator,
        protected SubscriptionService $subscriptions,
        protected PageProductSeoPublisherResolver $publishers,
    ) {
    }

    /**
     * @param  list<int>  $optimizationIds
     */
    public function run(array $optimizationIds): void
    {
        $items = PageProductSeoOptimization::with(['product.category', 'site.shopifyConnection'])->whereIn('id', $optimizationIds)->get();

        // مصرف توکن و کریدیت روی اشتراک صاحب سایت ثبت می‌شود
        $user = $items->isEmpty() ? null : User::find($items->first()->site->user_id);

        if (! $user) {
            $this->failAll($optimizationIds, new \RuntimeException('This site\'s user was not found.'));

            return;
        }

        $categories = SiteProductCategory::whereIn('id', $items->pluck('product.category_id')->filter()->unique())
            ->pluck('name', 'id')
            ->all();

        // محصولاتی که سئویشان قبلاً منتشر شده، دوباره عنوان/متای سئو نمی‌خواهند
        $seoDone = PageProductSeoOptimization::where('status', PageProductSeoOptimization::STATUS_PUBLISHED)
            ->whereIn('site_product_id', $items->pluck('site_product_id'))
            ->pluck('site_product_id')
            ->flip()
            ->all();

        $snapshots = [];
        $missingMap = [];

        foreach ($items as $item) {
            if (! $item->product) {
                $item->update(['status' => PageProductSeoOptimization::STATUS_FAILED, 'error' => 'Product not found.']);

                continue;
            }

            // platform comes from the site's connection; Shopify has no short description/focus keyword
            $snapshot = $this->gaps->snapshot($item->product, $categories, $this->publishers->isShopify($item->site));
            $missing = $this->gaps->missing($snapshot, isset($seoDone[$item->site_product_id]));

            if ($missing === []) {
                $item->update(['status' => PageProductSeoOptimization::STATUS_SKIPPED, 'missing_fields' => []]);

                continue;
            }

            $item->update(['status' => PageProductSeoOptimization::STATUS_ANALYZING, 'missing_fields' => $missing]);

            $snapshots[$item->id] = $snapshot;
            $missingMap[$item->id] = $missing;
        }

        if ($snapshots === []) {
            return;
        }

        if (! $this->subscriptions->hasAvailableCredit($user)) {
            PageProductSeoOptimization::whereIn('id', array_keys($snapshots))->update([
                'status' => PageProductSeoOptimization::STATUS_FAILED,
                'error' => self::NO_CREDIT,
            ]);

            return;
        }

        foreach ($items->whereIn('id', array_keys($snapshots)) as $item) {
            // قبل از هر تولید، کریدیت چک می‌شود تا مصرف از اشتراک بیشتر نشود
            if (! $this->subscriptions->hasAvailableCredit($user)) {
                $item->update(['status' => PageProductSeoOptimization::STATUS_FAILED, 'error' => self::NO_CREDIT]);

                continue;
            }

            try {
                $result = $this->generator->generate(
                    $snapshots[$item->id],
                    $missingMap[$item->id],
                    $user,
                    ContentLanguage::forSite($item->site),
                );

                $item->update([
                    'needs_search' => false,
                    'search_data' => null,
                    'result' => $result,
                    'status' => PageProductSeoOptimization::STATUS_READY,
                    'error' => null,
                ]);
            } catch (Throwable $e) {
                Log::warning('[PageProductSeo] بهینه‌سازی محصول ناموفق بود', [
                    'optimization_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);

                $item->update(['status' => PageProductSeoOptimization::STATUS_FAILED, 'error' => $e->getMessage()]);
            }
        }
    }

    protected function failAll(array $ids, Throwable $e): void
    {
        Log::warning('[PageProductSeo] pipeline failed', ['error' => $e->getMessage()]);

        PageProductSeoOptimization::whereIn('id', $ids)->update([
            'status' => PageProductSeoOptimization::STATUS_FAILED,
            'error' => $e->getMessage(),
        ]);
    }
}
