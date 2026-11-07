<?php
// app/Console/Commands/RunAutopilotAgents.php

namespace App\Console\Commands;

use App\Jobs\AgentWP\RunAgentArticleGenerationJob;
use App\Models\AgentSetting;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunAutopilotAgents extends Command
{
    protected $signature = 'autopilot:run
                            {--dry-run : فقط بررسی و نمایش بده، هیچ جاب/تغییری ثبت نکن}
                            {--id= : فقط برای یک AgentSetting مشخص اجرا کن}';

    protected $description = 'بررسی Agent Settingهای فعال و دیسپچ تولید مقاله بر اساس ساعت/بازه‌ی تنظیم‌شده';

    protected bool $dryRun = false;

    public function handle(SubscriptionService $subscriptionService): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $this->line('---------------------------------------------');
        $this->info('now()          = ' . now()->toDateTimeString());
        $this->info('app timezone   = ' . config('app.timezone'));
        $this->info('mode           = ' . ($this->dryRun ? 'DRY-RUN (بدون تغییر)' : 'LIVE'));
        $this->line('---------------------------------------------');

        $query = AgentSetting::query()
            ->where('agent_enabled', true)
            ->with('site.user');

        if ($id = $this->option('id')) {
            $query->where('id', $id);
        }

        $settings = $query->get();

        $this->info('تعداد تنظیمات فعال پیدا شده: ' . $settings->count());

        if ($settings->isEmpty()) {
            $this->warn('هیچ AgentSetting ای با agent_enabled=1 پیدا نشد.');
            return self::SUCCESS;
        }

        foreach ($settings as $setting) {
            $this->line('');
            $this->processSetting($setting, $subscriptionService);
        }

        return self::SUCCESS;
    }

    protected function processSetting(AgentSetting $setting, SubscriptionService $subscriptionService): void
    {
        $tag = "[setting #{$setting->id}]";

        $this->line("{$tag} site_id={$setting->site_id} daily_start_hour=" . ($setting->daily_start_hour ?? 'NULL')
            . " next_run_db=" . ($setting->next_articles_run_at ?? 'NULL')
            . " last_run_db=" . ($setting->last_articles_run_at ?? 'NULL'));

        $site = $setting->site;

        if (! $site) {
            $this->error("{$tag} ❌ site پیدا نشد (site_id={$setting->site_id}) → رد شد");
            return;
        }

        $user = $site->user;

        if (! $user) {
            $this->error("{$tag} ❌ site #{$site->id} کاربر (user) ندارد → " . ($this->dryRun ? 'در حالت واقعی Agent خاموش می‌شد' : 'Agent خاموش شد'));

            if (! $this->dryRun) {
                $setting->update(['agent_enabled' => false, 'next_articles_run_at' => null]);
            }
            return;
        }

        $hasCredit = $subscriptionService->hasAvailableCredit($user);
        $this->line("{$tag} user_id={$user->id} hasAvailableCredit=" . ($hasCredit ? 'YES' : 'NO'));

        if (! $hasCredit) {
            $this->error("{$tag} ❌ کردیت/اشتراک ندارد → " . ($this->dryRun ? 'در حالت واقعی Agent خاموش می‌شد' : 'Agent خاموش شد'));

            if (! $this->dryRun) {
                $setting->update(['agent_enabled' => false, 'next_articles_run_at' => null]);
            }
            return;
        }

        $nextRun = $this->resolveNextRun($setting);
        $diff = now()->diffForHumans($nextRun, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);

        $this->line("{$tag} nextRun محاسبه‌شده = {$nextRun->toDateTimeString()} ({$nextRun->timezoneName})");

        if ($nextRun->gt(now())) {
            $this->warn("{$tag} ⏳ هنوز زمانش نرسیده (حدود {$diff} مونده)");

            if (! $setting->next_articles_run_at) {
                $this->line("{$tag} → next_articles_run_at ذخیره می‌شد: {$nextRun->toDateTimeString()}" . ($this->dryRun ? ' (dry-run: ذخیره نشد)' : ''));

                if (! $this->dryRun) {
                    $setting->update(['next_articles_run_at' => $nextRun]);
                }
            }
            return;
        }

        $count = max(1, $setting->daily_article_limit ?? 1);
        $interval = max(1, $setting->publish_interval_days ?? 1);
        $newNext = $nextRun->copy()->addDays($interval);

        $this->info("{$tag} ✅ زمانش رسیده (حدود {$diff} از موعد گذشته)");
        $this->info("{$tag} → {$count} جاب RunAgentArticleGenerationJob دیسپچ " . ($this->dryRun ? 'می‌شد (dry-run)' : 'شد'));
        $this->info("{$tag} → next_articles_run_at جدید = {$newNext->toDateTimeString()}");

        if ($this->dryRun) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            RunAgentArticleGenerationJob::dispatch($site, $setting)->onConnection('redis-background');
        }

        $setting->update([
            'last_articles_run_at' => now(),
            'next_articles_run_at' => $newNext,
        ]);
    }

    protected function resolveNextRun(AgentSetting $setting): Carbon
    {
        if ($setting->next_articles_run_at) {
            return $setting->next_articles_run_at->copy();
        }

        return now()->copy()->setTime($setting->daily_start_hour ?? 9, 0, 0);
    }
}
