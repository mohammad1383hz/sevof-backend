<?php

namespace App\Jobs\Products\PageProduct;

use App\Models\PageProduct\PageProductSeoOptimization;
use App\Services\Products\PageProduct\PageProductSeoPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunPageProductSeoPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // تا ۱۰۰ محصول پشت‌سرهم (دسته‌بندی + جست‌وجو + تولید) پردازش می‌شود؛ ۹۰۰ ثانیه کم بود.
    public int $timeout = 3600;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    /** @param list<int> $optimizationIds */
    public function __construct(public array $optimizationIds)
    {
    }

    public function handle(PageProductSeoPipelineService $service): void
    {
        $service->run($this->optimizationIds);
    }

    public function failed(Throwable $e): void
    {
        Log::error('[PageProductSeo] اجرای job ناموفق بود', [
            'optimization_ids' => $this->optimizationIds,
            'error' => $e->getMessage(),
        ]);

        PageProductSeoOptimization::whereIn('id', $this->optimizationIds)
            ->whereIn('status', [PageProductSeoOptimization::STATUS_PENDING, PageProductSeoOptimization::STATUS_ANALYZING])
            ->update(['status' => PageProductSeoOptimization::STATUS_FAILED, 'error' => 'Processing failed or timed out. Please try again.']);
    }
}