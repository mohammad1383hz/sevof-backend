<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    public function ensureFreeSubscription(User $user): Subscription
    {
        $existing = $user->activeSubscription()->first();

        if ($existing) {
            return $existing;
        }

        return $this->assignFreePlan($user);
    }

    public function assignFreePlan(User $user): Subscription
    {
        $slug = config('seobot.subscription.free_plan_slug', 'free');
        $plan = Plan::findBySlug($slug);

        if (! $plan) {
            $plan = $this->createDefaultFreePlan();
        }

        // هر کاربر در کل عمر حسابش فقط مجاز به یک‌بار دریافت پلن رایگان است.
        // قبل از ساخت، تاریخچه‌ی کامل (نه فقط active) کاربر برای همین plan_id
        // چک می‌شه و اگه قبلاً گرفته باشه، همون ردیف قبلی برگردونده می‌شه.
        $previousFreeSubscription = Subscription::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->orderByDesc('created_at')
            ->first();

        if ($previousFreeSubscription) {
            Log::warning('subscription.free_plan_reissue_blocked', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'previous_subscription_id' => $previousFreeSubscription->id,
                'previous_status' => $previousFreeSubscription->status,
            ]);

            return $previousFreeSubscription;
        }

        return $this->createSubscription($user, $plan);
    }

    /**
     * چک می‌کنه آیا کاربر تا حالا (حتی اگه الان expired/cancelled باشه) حداقل
     * یک‌بار پلن رایگان گرفته یا نه. هر کاربر فقط یک‌بار در کل عمر حسابش مجاز
     * به دریافت پلن رایگانه.
     */
    public function hasEverHadFreePlan(User $user): bool
    {
        return Subscription::where('user_id', $user->id)
            ->whereHas('plan', fn ($query) => $query->where('is_free', true))
            ->exists();
    }

    /**
     * فقط بر اساس getActiveSubscription تصمیم می‌گیره
     * (که خودش هم تاریخ انقضا و هم اتمام کریدیت رو چک می‌کنه)
     */
    public function hasActivePaidPlan(User $user): bool
    {
        $subscription = $this->getActiveSubscription($user);

        return $subscription !== null && ! $subscription->plan->is_free;
    }

    /**
     * --- جدید: منطق خرید بعد از پرداخت موفق ---
     *  - اشتراک پولی فعال دارد   → پلن جدید queued می‌شود
     *  - اشتراک رایگان فعال دارد → رایگان cancel و پلن جدید فوری فعال می‌شود
     *  - اشتراک فعالی ندارد      → پلن جدید فوری فعال می‌شود
     *
     * ردیف رایگان حذف نمی‌شود (فقط cancelled می‌شود) تا hasEverHadFreePlan
     * همچنان true بماند و کاربر دوباره نتواند پلن رایگان بگیرد.
     */
    public function purchaseSubscription(User $user, Plan $plan, int $periodMonths = 1): Subscription
    {
        $active = $this->getActiveSubscription($user);

        // اشتراک پولی فعال → صف
        if ($active && ! $active->plan->is_free) {
            return $this->queueSubscription($user, $plan, $periodMonths);
        }

        // اشتراک رایگان فعال → جایگزین فوری
        if ($active && $active->plan->is_free) {
            Log::info('subscription.free_replaced_by_paid', [
                'user_id' => $user->id,
                'free_subscription_id' => $active->id,
                'new_plan_slug' => $plan->slug,
                'free_credit_remaining' => $active->remainingCredit(),
            ]);
        }

        // createSubscription خودش deactivatePreviousSubscriptions رو صدا می‌زنه
        // و اشتراک رایگان active رو cancelled می‌کنه.
        return $this->createSubscription($user, $plan, periodMonths: $periodMonths);
    }

    /**
     * فقط برای فعال‌سازی فوری (اولین پلن کاربر، یا پلن رایگان، یا جایگزینی رایگان).
     * وقتی کاربر از قبل یک اشتراک پولی active داره، این متد رو صدا نزن —
     * از queueSubscription (یا بهتر: purchaseSubscription) استفاده کن،
     * چون این متد هر اشتراک active فعلی رو cancel می‌کنه.
     */
    public function createSubscription(User $user, Plan $plan, ?string $status = null, int $periodMonths = 1): Subscription
    {
        $periodMonths = $this->normalizePeriod($plan, $periodMonths);

        $this->deactivatePreviousSubscriptions($user);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period_months' => $periodMonths,
            'credit_limit' => $plan->creditFor($periodMonths),
            'status' => $status ?? Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays($this->periodDays($plan, $periodMonths)),
            'credit_used' => 0,
        ]);

        Log::info('subscription.created', [
            'user_id' => $user->id,
            'plan_slug' => $plan->slug,
            'subscription_id' => $subscription->id,
            'period_months' => $periodMonths,
            'credit_limit' => $subscription->credit_limit,
        ]);

        return $subscription;
    }

    /**
     * خرید پلن بعدی (صف): اشتراک active فعلی رو دست نمی‌زنه؛
     * فقط یک ردیف با status=queued و starts_at/ends_at خالی می‌سازه.
     * فعال‌سازی واقعی‌ش توسط activateNextQueuedSubscription انجام می‌شه.
     */
    public function queueSubscription(User $user, Plan $plan, int $periodMonths = 1): Subscription
    {
        $periodMonths = $this->normalizePeriod($plan, $periodMonths);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period_months' => $periodMonths,
            'credit_limit' => $plan->creditFor($periodMonths),
            'status' => Subscription::STATUS_QUEUED,
            'starts_at' => null,
            'ends_at' => null,
            'credit_used' => 0,
            'tokens_used' => 0,
        ]);

        Log::info('subscription.queued', [
            'user_id' => $user->id,
            'plan_slug' => $plan->slug,
            'subscription_id' => $subscription->id,
            'period_months' => $periodMonths,
        ]);

        return $subscription;
    }

    /** مدت نامعتبر یا مدتِ غیرمجاز برای این پلن (مثلاً رایگان ۳ ماهه) → ماهانه */
    public function normalizePeriod(Plan $plan, ?int $periodMonths): int
    {
        return in_array($periodMonths, $plan->availablePeriods(), true) ? $periodMonths : 1;
    }

    protected function periodDays(Plan $plan, int $periodMonths): int
    {
        $days = $plan->is_free
            ? config('seobot.subscription.default_subscription_days', 30)
            : config('seobot.subscription.paid_subscription_days', 30);

        return (int) $days * $periodMonths;
    }

    public function getQueuedSubscription(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->where('status', Subscription::STATUS_QUEUED)
            ->orderBy('created_at') // FIFO
            ->first();
    }

    public function hasQueuedSubscription(User $user): bool
    {
        return $this->getQueuedSubscription($user) !== null;
    }

    /**
     * ردیف queued رو به active تبدیل می‌کنه (update همون ردیف، نه ساخت ردیف جدید).
     * starts_at از همین لحظه شروع می‌شه. اگه می‌خوای دقیقاً لحظه‌ی اتمام پلن قبلی
     * مبنا باشه، یک artisan command زمان‌بندی‌شده اضافه کن که این متد رو دوره‌ای
     * صدا بزنه.
     */
    public function activateNextQueuedSubscription(User $user): ?Subscription
    {
        $queued = $this->getQueuedSubscription($user);

        if (! $queued) {
            return null;
        }

        $queued->update([
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays($this->periodDays($queued->plan, (int) ($queued->period_months ?: 1))),
        ]);

        Log::info('subscription.queued_activated', [
            'user_id' => $user->id,
            'subscription_id' => $queued->id,
            'plan_slug' => $queued->plan->slug,
        ]);

        return $queued->fresh();
    }

    /**
     * تمام اشتراک‌های active کاربر رو cancel می‌کنه تا همیشه فقط یک اشتراک
     * active داشته باشه. روی ردیف‌های queued هیچ تاثیری نداره.
     */
    protected function deactivatePreviousSubscriptions(User $user): void
    {
        $activeSubscriptions = Subscription::where('user_id', $user->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->get();

        foreach ($activeSubscriptions as $old) {
            $old->update([
                'status' => Subscription::STATUS_CANCELLED,
                'ends_at' => now(),
            ]);

            Log::info('subscription.replaced', [
                'user_id' => $user->id,
                'old_subscription_id' => $old->id,
                'old_plan_id' => $old->plan_id,
            ]);
        }
    }

    /**
     * اشتراک واقعاً «قابل استفاده»‌ی کاربر رو برمی‌گردونه.
     * حالت‌هایی که اشتراک دیگه فعال حساب نمی‌شه:
     *  ۱) اصلاً اشتراکی وجود نداره
     *  ۲) تاریخ انقضاش گذشته
     *  ۳) کریدیتش تموم شده (برای پلن‌هایی که سقف کریدیت دارن)
     *
     * در هر سه حالت، اگه پلن queued وجود داشته باشه به‌صورت lazy فعال می‌شه.
     */
    public function getActiveSubscription(User $user): ?Subscription
    {
        $subscription = $user->activeSubscription()->first();

        if ($subscription) {
            if ($subscription->isExpired()) {
                $subscription->update(['status' => Subscription::STATUS_EXPIRED]);

                Log::info('subscription.expired', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                ]);

                $subscription = null;
            } elseif ($subscription->creditLimit() > 0 && $subscription->remainingCredit() <= 0) {
                // شرط plan->credit > 0 لازمه چون پلن رایگان پیش‌فرض credit=0 داره
                // و نباید بلافاصله بعد از فعال‌سازی «تموم‌شده» حساب بشه.
                $subscription->update(['status' => Subscription::STATUS_EXPIRED]);

                Log::info('subscription.credit_exhausted', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'plan_slug' => $subscription->plan->slug,
                ]);

                $subscription = null;
            }
        }

        if (! $subscription) {
            $subscription = $this->activateNextQueuedSubscription($user);
        }

        return $subscription;
    }

    public function canUseTokens(User $user, int $requestedTokens = 0): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (! $subscription) {
            return false;
        }

        return $subscription->remainingTokens() >= $requestedTokens;
    }

    public function deductTokens(User $user, int $tokens): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (! $subscription) {
            return false;
        }

        if ($subscription->remainingTokens() < $tokens) {
            Log::warning('subscription.insufficient_tokens', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'requested' => $tokens,
                'remaining' => $subscription->remainingTokens(),
            ]);

            return false;
        }

        $subscription->increment('tokens_used', $tokens);

        Log::info('subscription.tokens_deducted', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'tokens' => $tokens,
            'new_tokens_used' => $subscription->fresh()->tokens_used,
        ]);

        return true;
    }

    /**
     * چک می‌کنه که آیا کاربر کریدیت کافی برای مصرف مورد نظر داره یا نه
     */
    public function canUseCredit(User $user, float $requestedCredit = 0): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (! $subscription) {
            return false;
        }

        return $subscription->remainingCredit() >= $requestedCredit;
    }

    /**
     * چک می‌کنه که آیا کاربر اصلاً کریدیت باقیمونده (بیشتر از صفر) داره یا نه.
     * برای gate کردن ورودی درخواست‌ها قبل از پردازش AI استفاده می‌شه.
     */
    public function hasAvailableCredit(User $user): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (! $subscription) {
            return false;
        }

        return $subscription->remainingCredit() > 0;
    }

    /**
     * کسر کریدیت مصرف‌شده از اشتراک فعال کاربر
     * (مشابه deductTokens ولی برای credit)
     */
    public function deductCredit(User $user, float $credit): bool
    {
        if ($credit <= 0) {
            return true;
        }

        $subscription = $this->getActiveSubscription($user);

        if (! $subscription) {
            Log::warning('subscription.no_active_subscription_for_credit', [
                'user_id' => $user->id,
                'requested_credit' => $credit,
            ]);

            return false;
        }

        if ($subscription->remainingCredit() < $credit) {
            Log::warning('subscription.insufficient_credit', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'requested' => $credit,
                'remaining' => $subscription->remainingCredit(),
            ]);

            // توجه: با وجود کمبود کریدیت باز هم کسر انجام می‌شه تا با رفتار
            // deductTokens هماهنگ بمونه. اگه می‌خوای مصرف بلاک بشه، اینجا
            // return false برگردون.
        }

        $subscription->increment('credit_used', $credit);

        Log::info('subscription.credit_deducted', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'credit' => $credit,
            'new_credit_used' => $subscription->fresh()->credit_used,
        ]);

        return true;
    }

    public function getUsageStats(User $user): array
    {
        $subscription = $this->getActiveSubscription($user);
        $queued = $this->getQueuedSubscription($user);

        if (! $subscription) {
            $lastSubscription = Subscription::where('user_id', $user->id)
                ->where('status', '!=', Subscription::STATUS_QUEUED)
                ->orderByDesc('created_at')
                ->with('plan')
                ->first();

            return [
                'has_active_subscription' => false,
                'plan_name' => null,
                'is_free' => true,
                'token_limit' => 0,
                'tokens_used' => 0,
                'remaining_tokens' => 0,
                'usage_percentage' => 0,
                'credit_limit' => 0,
                'credit_used' => 0,
                'remaining_credit' => 0,
                'credit_usage_percentage' => 0,
                'previous_plan_name' => $lastSubscription?->plan?->name,
                'previous_plan_ended' => $lastSubscription !== null,
                'has_used_free_plan' => $this->hasEverHadFreePlan($user),
                'has_queued_subscription' => $queued !== null,
                'queued_plan_name' => $queued?->plan?->name,
                'queued_plan_credit' => $queued?->creditLimit(),
                'queued_period_months' => $queued?->period_months,
            ];
        }

        return [
            'has_active_subscription' => true,
            'subscription_id' => $subscription->id,
            'plan_name' => $subscription->plan->name,
            'plan_slug' => $subscription->plan->slug,
            'is_free' => $subscription->plan->is_free,
            'period_months' => (int) ($subscription->period_months ?: 1),
            'token_limit' => $subscription->plan->token_limit,
            'tokens_used' => $subscription->tokens_used,
            'remaining_tokens' => $subscription->remainingTokens(),
            'usage_percentage' => $subscription->tokenUsagePercentage(),
            'credit_limit' => $subscription->creditLimit(),
            'credit_used' => $subscription->credit_used,
            'remaining_credit' => $subscription->remainingCredit(),
            'credit_usage_percentage' => $subscription->creditUsagePercentage(),
            'has_used_free_plan' => $this->hasEverHadFreePlan($user),
            'has_queued_subscription' => $queued !== null,
            'queued_plan_name' => $queued?->plan?->name,
            'queued_plan_credit' => $queued?->creditLimit(),
            'queued_period_months' => $queued?->period_months,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }

    protected function createDefaultFreePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free',
            'slug' => 'free',
            'description' => 'Free plan with basic features',
            'token_limit' => config('seobot.subscription.free_token_limit', 100000),
            'site_limit' => config('seobot.subscription.free_site_limit', 1),
            'article_limit' => config('seobot.subscription.free_article_limit', 5),
            'is_active' => true,
            'is_free' => true,
            'price' => 0,
            'credit' => config('seobot.subscription.free_credit_limit', 0),
            'features' => [
                'ai_content_generation',
                'basic_seo_analysis',
                'wordpress_publishing',
            ],
        ]);
    }
}
