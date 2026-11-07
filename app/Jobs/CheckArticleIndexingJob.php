<?php

namespace App\Jobs;

use App\Models\SiteSearchConsoleProperty;
use App\Services\ArticleIndexingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/** بررسی ایندکس مقاله‌های یک سایت متصل به سرچ کنسول (حداکثر ۱۰۰ مقاله در هر اجرا). */
class CheckArticleIndexingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // زیر retry_after صف؛ ۱۰۰ بررسی با مکث بین‌شان معمولاً ۲ تا ۳ دقیقه است
    public int $timeout = 380;

    public function __construct(public int $propertyId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('check-indexing-'.$this->propertyId))->expireAfter($this->timeout + 30)->dontRelease()];
    }

    public function handle(ArticleIndexingService $indexing): void
    {
        $property = SiteSearchConsoleProperty::with(['site', 'connection'])->find($this->propertyId);

        if (! $property?->site || $property->connection?->status !== 'connected') {
            return;
        }

        Log::info('search_console.indexing_checked', ['property_id' => $property->id] + $indexing->checkProperty($property));
    }
}
