<?php
// app/Console/Commands/RunAgentKeywordResearch.php

namespace App\Console\Commands;

use App\Jobs\RunContentResearchJob;
use App\Models\AgentSetting;
use App\Models\ContentResearchSession;
use App\Services\AI\ContentResearchService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunAgentKeywordResearch extends Command
{
    protected $signature = 'agent:keyword-research
        {--site= : فقط برای یک site_id مشخص}
        {--sync : سنکرون (بدون صف)، مناسب تست}
        {--force : همه‌ی شرط‌های زمان‌بندی را نادیده بگیر (به‌جز نبودن اشتراک/کریدیت/Entity)}
        {--dry-run : فقط نشان بده چه سایت‌هایی اجرا می‌شوند، بدون ساخت session}
        {--stagger=30 : فاصله‌ی تأخیر (ثانیه) بین جاب‌ها}';

    protected $description = 'تحقیق خوشه‌ی کلمات کلیدی: روزانه برای سایت‌های بدون خوشه، هفتگی برای بقیه (فقط Agentهای فعال)';

    /** هر چند روز یک‌بار برای سایت‌هایی که از قبل خوشه دارند */
    private const WEEKLY_INTERVAL_DAYS = 7;

    public function handle(SubscriptionService $subscriptionService): int
    {
        $settings = AgentSetting::query()
            ->where('agent_enabled', true)
            ->when($this->option('site'), fn ($q, $siteId) => $q->where('site_id', $siteId))
            ->with(['site.entity', 'site.user'])
            ->get();

        if ($settings->isEmpty()) {
            $this->warn('هیچ Agent فعالی پیدا نشد.');

            return self::SUCCESS;
        }

        $stagger = max(0, (int) $this->option('stagger'));
        $sync = (bool) $this->option('sync');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $stats = ['dispatched' => 0, 'skipped' => 0];
        $index = 0;

        foreach ($settings as $setting) {
            $site = $setting->site;
            $label = $site?->domain ?? "setting#{$setting->id}";

            if (! $site) {
                $this->skip($label, 'سایت پیدا نشد.', $stats);
                continue;
            }

            $user = $site->user;

            if (! $user) {
                $this->skip($label, 'کاربر سایت پیدا نشد.', $stats);
                continue;
            }

            if (! $subscriptionService->getActiveSubscription($user)) {
                $this->skip($label, 'اشتراک فعال ندارد.', $stats);
                continue;
            }

            if (! $subscriptionService->hasAvailableCredit($user)) {
                $this->skip($label, 'کریدیت کافی نیست.', $stats);
                continue;
            }

            if (! $site->ensureEntity()) {
                $this->skip($label, 'تحلیل Entity انجام نشده.', $stats);
                continue;
            }

            // ---------- تصمیم‌گیری زمان‌بندی ----------
            [$shouldRun, $reason] = $force
                ? [true, 'force']
                : $this->shouldRun($site->id);

            if (! $shouldRun) {
                $this->skip($label, $reason, $stats);
                continue;
            }

            if ($dryRun) {
                $this->info("🧪 {$label}: اجرا می‌شد ({$reason})");
                $stats['dispatched']++;
                continue;
            }

            $session = ContentResearchSession::create([
                'site_id' => $site->id,
                'user_id' => $user->id,
                'type' => ContentResearchService::TYPE_KEYWORD_CLUSTERS,
                'status' => 'queued',
                'prompt' => null,
                'data_source' => DB::table('search_console_query_metrics')->where('site_id', $site->id)->exists()
                    ? 'gsc_justserp'
                    : 'entity_justserp',
                'metadata' => ['trigger' => 'agent_scheduler', 'reason' => $reason],
            ]);

            if ($sync) {
                RunContentResearchJob::dispatchSync($session);
                $session->refresh();
                $this->info("✅ {$label} ({$reason}): وضعیت = {$session->status}".($session->error ? " ({$session->error})" : ''));
            } else {
                $pending = RunContentResearchJob::dispatch($session)->onConnection('redis-background');

                if ($stagger > 0) {
                    $pending->delay(now()->addSeconds($index * $stagger));
                }

                $this->info("🚀 {$label} ({$reason}): session #{$session->id} در صف.");
            }

            $stats['dispatched']++;
            $index++;
        }

        $this->newLine();
        $this->info("پایان — اجرا: {$stats['dispatched']} | رد شده: {$stats['skipped']}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function shouldRun(int $siteId): array
    {
        $type = ContentResearchService::TYPE_KEYWORD_CLUSTERS;

        // ۱) اگر الان یکی در حال اجراست، هیچ‌وقت دوباره نساز
        $running = ContentResearchSession::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->whereIn('status', ['queued', 'processing'])
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();

        if ($running) {
            return [false, 'یک تحقیق در حال اجراست.'];
        }

        // ۲) هر تلاشی که امروز انجام شده، یعنی امروز دیگر تکرار نشود
        // (هم جلوی اجرای دوباره‌ی همان روز را می‌گیرد، هم جلوی retry بی‌پایان روی خطا)
        $ranToday = ContentResearchSession::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        if ($ranToday) {
            return [false, 'امروز قبلاً اجرا شده.'];
        }

        // ۳) تعداد خوشه‌های فعلی سایت
        $clusterCount = DB::table('keyword_clusters')
            ->where('site_id', $siteId)
            ->count();

        // سایت بدون خوشه: هر روز (یک‌بار) تا وقتی خوشه‌ای داشته باشد
        if ($clusterCount === 0) {
            return [true, 'بدون خوشه (اجرای روزانه)'];
        }

        // سایت دارای خوشه: فقط اگر ۷ روز از آخرین تحقیق موفق گذشته باشد
        $lastDone = ContentResearchSession::query()
            ->where('site_id', $siteId)
            ->where('type', $type)
            ->where('status', 'done')
            ->latest('created_at')
            ->first();

        if (! $lastDone) {
            // خوشه دارد ولی session موفقی ثبت نشده (مثلاً دستی ساخته شده)، اجرا کن
            return [true, "دارای {$clusterCount} خوشه ولی بدون تاریخچه (اجرای هفتگی)"];
        }

        // مقایسه روی «تاریخ روز» تا ساعت اجرا باعث drift نشود
        $lastRunDay = $lastDone->created_at->copy()->startOfDay();
        $dueDay = now()->subDays(self::WEEKLY_INTERVAL_DAYS)->startOfDay();

        if ($lastRunDay->gt($dueDay)) {
            $daysAgo = (int) $lastRunDay->diffInDays(now()->startOfDay());

            return [false, "{$clusterCount} خوشه دارد؛ آخرین تحقیق {$daysAgo} روز پیش (نوبت هفتگی نرسیده)."];
        }

        return [true, "دارای {$clusterCount} خوشه (اجرای هفتگی)"];
    }

    protected function skip(string $label, string $reason, array &$stats): void
    {
        $this->line("⏭  {$label}: {$reason}");
        $stats['skipped']++;
    }
}
