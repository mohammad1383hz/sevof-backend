<?php

namespace App\Console\Commands;

use App\Models\AgentSetting;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly check of each subscription's article cap.
 * Cap = plan article_limit × months bought (1/3/6). Only articles created since that subscription's
 * starts_at count, so a renewal or a queued plan starts from zero.
 * When the cap is reached: the subscription expires, the next queued plan (if any) is activated,
 * and if no active plan is left, the Agent on all of the user's sites is turned off.
 */
class EnforceArticleLimits extends Command
{
    protected $signature = 'subscriptions:enforce-article-limits
                            {--dry-run : Only report, change nothing}';

    protected $description = 'Expire subscriptions that reached their plan article cap and turn off their Agent';

    public function handle(SubscriptionService $subscriptions): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $expired = 0;

        User::query()
            ->whereIn('id', Subscription::active()->select('user_id'))
            ->chunkById(200, function ($users) use ($subscriptions, $dryRun, &$expired) {
                foreach ($users as $user) {
                    // In a live run, date/credit expiry and queued-plan activation are applied first
                    $subscription = $dryRun
                        ? $user->activeSubscription()->with('plan')->first()
                        : $subscriptions->getActiveSubscription($user);

                    // A plan activated from the queue is checked again too
                    while ($subscription && $this->reachedLimit($subscription)) {
                        $expired++;
                        $this->warn("user #{$user->id} subscription #{$subscription->id} ({$subscription->plan->slug}, {$subscription->period_months}m): "
                            . "{$subscription->articlesUsed()}/{$subscription->articleLimit()} articles → expire");

                        if ($dryRun) {
                            break;
                        }

                        $subscription->update(['status' => Subscription::STATUS_EXPIRED]);

                        Log::info('subscription.article_limit_reached', [
                            'user_id' => $user->id,
                            'subscription_id' => $subscription->id,
                            'plan_slug' => $subscription->plan->slug,
                            'period_months' => $subscription->period_months,
                            'articles_used' => $subscription->articlesUsed(),
                            'article_limit' => $subscription->articleLimit(),
                        ]);

                        $subscription = $subscriptions->activateNextQueuedSubscription($user);

                        if (! $subscription) {
                            $this->disableAgents($user);
                        }
                    }
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '') . "subscriptions over article limit: {$expired}");

        return self::SUCCESS;
    }

    protected function reachedLimit(Subscription $subscription): bool
    {
        $limit = $subscription->articleLimit();

        return $limit > 0 && $subscription->articlesUsed() >= $limit;
    }

    protected function disableAgents(User $user): void
    {
        $count = AgentSetting::query()
            ->whereIn('site_id', Site::withTrashed()->where('user_id', $user->id)->select('id'))
            ->where('agent_enabled', true)
            ->update(['agent_enabled' => false, 'next_articles_run_at' => null]);

        if ($count) {
            $this->line("user #{$user->id}: {$count} agent(s) disabled");
            Log::info('subscription.article_limit_agents_disabled', ['user_id' => $user->id, 'count' => $count]);
        }
    }
}
