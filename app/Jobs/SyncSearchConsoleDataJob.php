<?php

namespace App\Jobs;

use App\Models\SiteSearchConsoleProperty;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSearchConsoleDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $propertyId)
    {
    }

    public function handle(GoogleSearchConsoleService $service): void
    {
        $property = SiteSearchConsoleProperty::findOrFail($this->propertyId);
        $service->sync($property);
    }
}
