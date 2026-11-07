<?php

namespace App\Console\Commands;

use App\Models\AgentSetting;
use App\Models\Article;
use App\Models\ArticleRefresh;
use App\Services\AI\ArticleRefreshService;
use App\Services\SearchConsoleInsightsService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * اتوماسیون: روزی حداکثر یک مقاله در هر سایت، همان که به‌روزرسانی‌اش بیشترین کلیک را برمی‌گرداند.
 * با «انتشار مستقیم» خودش منتشر می‌شود، وگرنه برای تأیید کاربر می‌ماند.
 */
class AutoRefreshArticles extends Command
{
    protected $signature = 'articles:auto-refresh {--dry-run : فقط نشان بده چه مقاله‌ای انتخاب می‌شود}';

    protected $description = 'به‌روزرسانی خودکار مقاله‌ها با داده‌ی سرچ کنسول برای سایت‌هایی که این گزینه را روشن کرده‌اند';

    public function handle(SearchConsoleInsightsService $insights, ArticleRefreshService $refreshes, SubscriptionService $subscriptions): int
    {
        $settings = AgentSetting::query()
            ->where('agent_enabled', true)
            ->where('refresh_enabled', true)
            ->where(fn ($q) => $q->whereNull('last_refresh_run_at')->orWhere('last_refresh_run_at', '<', now()->subHours(20)))
            ->with('site.user')
            ->get();

        foreach ($settings as $setting) {
            $site = $setting->site;
            $user = $site?->user;

            if (! $user || ! $subscriptions->hasActivePaidPlan($user) || ! $subscriptions->hasAvailableCredit($user)) {
                continue;
            }

            if (ArticleRefresh::where('site_id', $site->id)->whereIn('status', ArticleRefresh::OPEN_STATUSES)->exists()) {
                continue;
            }

            $candidate = $insights->refreshCandidates($site, limit: 1)->first();

            if (! $candidate) {
                continue;
            }

            $this->line("site #{$site->id}: article #{$candidate['id']} ({$candidate['reason']}, ~{$candidate['potential_clicks']} clicks)");

            if ($this->option('dry-run')) {
                continue;
            }

            try {
                $refreshes->start(Article::findOrFail($candidate['id']), ArticleRefresh::TRIGGER_AGENT, $candidate);
            } catch (Throwable $e) {
                $this->warn("site #{$site->id}: {$e->getMessage()}");
            }

            $setting->update(['last_refresh_run_at' => now()]);
        }

        return self::SUCCESS;
    }
}
