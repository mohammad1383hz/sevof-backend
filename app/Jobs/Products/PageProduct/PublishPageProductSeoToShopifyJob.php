<?php

namespace App\Jobs\Products\PageProduct;

use App\Models\PageProduct\PageProductSeoOptimization;
use App\Services\Shopify\ShopifyPageProductSeoPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Shopify counterpart of PublishPageProductSeoToWpJob. API errors are stored on the
 * optimization by the service; this job only catches unexpected failures.
 */
class PublishPageProductSeoToShopifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Several separate writes (update + tags + alts); an automatic retry would hide the real state.
    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public int $optimizationId)
    {
    }

    public function handle(ShopifyPageProductSeoPublishService $publisher): void
    {
        $optimization = PageProductSeoOptimization::find($this->optimizationId);

        if (! $optimization
            || $optimization->status !== PageProductSeoOptimization::STATUS_APPROVED
            || $optimization->wp_sync_status === 'syncing') {
            return;
        }

        $publisher->publish($optimization);
    }

    public function failed(Throwable $e): void
    {
        PageProductSeoOptimization::whereKey($this->optimizationId)->update([
            'wp_sync_status' => 'failed',
            'publish_error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
