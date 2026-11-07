<?php

namespace App\Console\Commands;

use App\Jobs\SyncSearchConsoleDataJob;
use App\Models\SiteSearchConsoleProperty;
use Illuminate\Console\Command;

/** داده‌ی سرچ کنسول همه‌ی سایت‌های متصل هر روز تازه می‌شود تا داشبورد، ایده و لینک‌سازی کهنه نمانند. */
class SyncAllSearchConsoleData extends Command
{
    protected $signature = 'search-console:sync-all';

    protected $description = 'همگام‌سازی روزانه‌ی داده‌ی سرچ کنسول برای همه‌ی سایت‌های متصل';

    public function handle(): int
    {
        $properties = SiteSearchConsoleProperty::query()
            ->whereNotIn('sync_status', ['queued', 'syncing'])
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subHours(20)))
            ->whereHas('connection', fn ($q) => $q->where('status', 'connected'))
            ->get();

        foreach ($properties as $property) {
            $property->update(['sync_status' => 'queued', 'last_sync_error' => null]);
            SyncSearchConsoleDataJob::dispatch($property->id)->onConnection('redis-background');
        }

        $this->info("{$properties->count()} property برای همگام‌سازی در صف قرار گرفت.");

        return self::SUCCESS;
    }
}
