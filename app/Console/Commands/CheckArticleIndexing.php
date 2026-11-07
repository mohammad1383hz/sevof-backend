<?php

namespace App\Console\Commands;

use App\Jobs\CheckArticleIndexingJob;
use App\Models\SiteSearchConsoleProperty;
use Illuminate\Console\Command;

/** هر روز: وضعیت ایندکس مقاله‌های تولیدشده‌ی همه‌ی سایت‌های متصل به سرچ کنسول بررسی می‌شود. */
class CheckArticleIndexing extends Command
{
    protected $signature = 'search-console:check-indexing {--site= : فقط برای یک سایت}';

    protected $description = 'بررسی ایندکس شدن مقاله‌های تولیدشده در گوگل (URL Inspection سرچ کنسول)';

    public function handle(): int
    {
        $properties = SiteSearchConsoleProperty::query()
            ->whereHas('connection', fn ($q) => $q->where('status', 'connected'))
            ->when($this->option('site'), fn ($q, $site) => $q->where('site_id', $site))
            ->get(['id']);

        // پخش در زمان تا همه‌ی سایت‌ها هم‌زمان به API گوگل فشار نیاورند
        foreach ($properties->values() as $i => $property) {
            CheckArticleIndexingJob::dispatch($property->id)->onConnection('redis-background')->delay(now()->addSeconds($i * 20));
        }

        $this->info("{$properties->count()} سایت برای بررسی ایندکس در صف قرار گرفت.");

        return self::SUCCESS;
    }
}
