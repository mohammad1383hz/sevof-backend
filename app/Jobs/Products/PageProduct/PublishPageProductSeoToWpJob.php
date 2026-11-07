<?php

namespace App\Jobs\Products\PageProduct;

use App\Models\PageProduct\PageProductSeoOptimization;
use App\Services\Wp\PageProduct\PageProductSeoWpPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishPageProductSeoToWpJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // timeout باید از timeout درخواست HTTP به وردپرس بیشتر و retry_after صف بزرگ‌تر از این باشد (مثلاً 180)
    public int $timeout = 120;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    /** جلوگیری از انتشار هم‌زمانِ دوباره با دو کلیک پشت‌سرهم */
    public int $uniqueFor = 300;

    public function __construct(public int $optimizationId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->optimizationId;
    }

    public function handle(PageProductSeoWpPublishService $service): void
    {
        $optimization = PageProductSeoOptimization::find($this->optimizationId);

        if ($optimization) {
            $service->updateProduct($optimization);
        }
    }

    public function failed(\Throwable $e): void
    {
        PageProductSeoOptimization::whereKey($this->optimizationId)->update([
            'status' => PageProductSeoOptimization::STATUS_READY,
            'wp_sync_status' => 'failed',
            'publish_error' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
