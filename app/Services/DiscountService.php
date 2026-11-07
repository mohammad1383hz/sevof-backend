<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DiscountService
{
    /**
     * ست کردن/جایگزینی تخفیف کلی سایت. همیشه فقط یک تخفیف کلی فعال داریم.
     */
    public function setGlobalDiscount(int $percentage, ?int $durationMinutes = null): Discount
    {
        Discount::global()->where('is_active', true)->update(['is_active' => false]);

        return Discount::create([
            'user_id'           => null,
            'percentage'        => $percentage,
            'duration_minutes'  => $durationMinutes,
            'activate_on_login' => false,
            'activated_at'      => now(),
            'expires_at'        => $durationMinutes ? now()->addMinutes($durationMinutes) : null,
            'is_active'         => true,
        ]);
    }

    /**
     * اختصاص تخفیف به یک کاربر خاص.
     * $activateOnLogin = false  => تایمر از همین الان شروع می‌شه (حالت ۲)
     * $activateOnLogin = true   => تایمر از اولین لاگین بعدی کاربر شروع می‌شه (حالت ۳)
     */
    public function assignToUser(User $user, int $percentage, int $durationMinutes, bool $activateOnLogin = false): Discount
    {
        return Discount::create([
            'user_id'           => $user->id,
            'percentage'        => $percentage,
            'duration_minutes'  => $durationMinutes,
            'activate_on_login' => $activateOnLogin,
            'activated_at'      => $activateOnLogin ? null : now(),
            'expires_at'        => $activateOnLogin ? null : now()->addMinutes($durationMinutes),
            'is_active'         => true,
        ]);
    }

    /**
     * این متد رو باید دقیقاً بعد از لاگین موفق کاربر (after the token is issued) صدا بزنی.
     * اگه تخفیف در انتظار فعال‌سازی داشته باشه، فعالش می‌کنه و خودش رو برمی‌گردونه
     * تا بشه توی response لاگین بهش گفت "تخفیف X% برات فعال شد، Y دقیقه فرصت داری".
     */
    public function activatePendingForUser(User $user): ?Discount
    {
        $discount = Discount::forUser($user->id)
            ->where('is_active', true)
            ->where('activate_on_login', true)
            ->whereNull('activated_at')
            ->latest('id')
            ->first();

        if (! $discount) {
            return null;
        }

        $discount->activate();

        Log::info('discount.activated_on_login', [
            'user_id'     => $user->id,
            'discount_id' => $discount->id,
            'percentage'  => $discount->percentage,
            'expires_at'  => $discount->expires_at,
        ]);

        return $discount;
    }

    /**
     * تخفیف قابل‌استفاده‌ی فعلیِ کاربر (اختصاصی) رو برمی‌گردونه، اگه وجود داشته باشه.
     */
    public function usableUserDiscount(User $user): ?Discount
    {
        return Discount::forUser($user->id)
            ->where('is_active', true)
            ->get()
            ->first(fn (Discount $d) => $d->isUsable());
    }

    public function usableGlobalDiscount(): ?Discount
    {
        return Discount::global()
            ->where('is_active', true)
            ->get()
            ->first(fn (Discount $d) => $d->isUsable());
    }

    /**
     * پیدا کردن یک تخفیف بر اساس کدی که کاربر دستی وارد کرده.
     * - کد باید فعال، منقضی‌نشده و قابل‌استفاده باشه (isUsable).
     * - اگه کد اختصاصیِ یک کاربر دیگه باشه (user_id مال یکی دیگه‌ست)، رد می‌شه؛
     *   کدهای کلی (user_id = null) برای همه قابل‌استفاده‌ن.
     */
    public function findUsableDiscountByCode(string $code, User $user): ?Discount
    {
        $discount = Discount::byCode($code)->first();

        if (! $discount || ! $discount->isUsable()) {
            return null;
        }

        if ($discount->user_id !== null && $discount->user_id !== $user->id) {
            return null;
        }

        return $discount;
    }

    /**
     * قیمت یک پلن با اعمال مستقیمِ درصدِ یک تخفیفِ مشخص (مثلاً همون کدی که کاربر وارد کرده).
     *
     * --- فیکس باگ ---
     * قبلاً این متد درصد کد رو روی $plan->price (قیمت خامِ اولیه‌ی پلن، قبل از هر
     * تخفیفی) حساب می‌کرد. این اشتباهه: $plan->final_price همون قیمتیه که قبلاً
     * تخفیفِ خودِ پلن رویش اعمال شده و چیزیه که واقعاً به کاربر نمایش داده می‌شه.
     * کد تخفیفِ دستی باید روی همین final_price اعمال بشه (stack بشه)، نه روی
     * قیمت خام. با نسخه‌ی قبلی، برای پلنی که final_price‌اش مثلاً ۵۰۰ (از قیمت
     * خامِ ۷۰۰) بود، یک کد ۲۵٪ عدد ۵۲۵ رو نتیجه می‌داد (۷۰۰ × ۰.۷۵) که چون از
     * ۵۰۰ بیشتره در کنترلر رد می‌شد و کد عملاً هیچ اثری نداشت.
     */
    public function priceWithDiscount(Plan $plan, Discount $discount, int $periodMonths = 1): int
    {
        $percent = $discount->percentage;
        $base    = $plan->priceFor($periodMonths);

        if ($percent <= 0) {
            return $base;
        }

        $final = $base - ($base * $percent / 100);

        return (int) max(0, round($final));
    }

    /**
     * بعد از پرداخت موفق با یک کد اختصاصی صدا زده می‌شه تا کد یک‌بارمصرف بشه
     * و دیگه قابل استفاده‌ی مجدد نباشه. روی تخفیف‌های کلی (global) اثری نداره،
     * چون اونا مال همه‌ن و نباید با استفاده‌ی یک نفر غیرفعال بشن.
     */
    public function markPersonalDiscountUsed(Discount $discount): void
    {
        if ($discount->user_id === null) {
            return;
        }

        $discount->update(['is_active' => false]);
    }

    /**
     * بیشترین درصد تخفیفِ قابل‌اعمال روی این پلن برای این کاربر.
     * سه منبع رو چک می‌کنه: تخفیف خودِ پلن (discount_percentage توی دیتابیس)،
     * تخفیف اختصاصی کاربر، تخفیف کلی سایت — و بیشترین‌شون رو برمی‌گردونه.
     */
    public function applicablePercentFor(Plan $plan, User $user): int
    {
        $planPercent   = (int) ($plan->discount_percentage ?? 0);
        $userPercent   = $this->usableUserDiscount($user)?->percentage ?? 0;
        $globalPercent = $this->usableGlobalDiscount()?->percentage ?? 0;

        return max($planPercent, $userPercent, $globalPercent);
    }

    /**
     * قیمت نهایی یک پلن برای یک کاربر خاص، با در نظر گرفتن هر سه منبع تخفیف.
     * توجه: این متد بر خلاف priceWithDiscount، «بهترین تخفیفِ تکی» رو روی قیمت
     * خام پلن اعمال می‌کنه (نه استک چندتایی) و منطق مجزاییه برای جاهایی که
     * final_price باید بدون کد دستی محاسبه بشه.
     */
    public function finalPriceFor(Plan $plan, User $user): int
    {
        $percent = $this->applicablePercentFor($plan, $user);

        if ($percent <= 0) {
            return (int) $plan->price;
        }

        $final = $plan->price - ($plan->price * $percent / 100);

        return (int) max(0, round($final));
    }
}
