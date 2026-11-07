<?php

namespace App\Services\Image;

use App\Models\CreditCloudflare;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Load balancer مرکزی برای کلیدهای جدول credit_cloudflares (shortapi، kieai).
 * همون الگوی active_requests که جدول gapgpt استفاده می‌کنه، ولی اینجا با
 * lockForUpdate اتمیک شده تا دو job هم‌زمان دقیقاً همون یک کلید «کم‌بارترین»
 * رو انتخاب نکنن (race که در نسخه‌ی فعلی gapgpt هم هست، ولی چون امتیازش با
 * RAND() تصادفی‌سازی می‌شه کمتر بروز می‌کنه؛ اینجا چون فقط سه نوع کلید داریم
 * و concurrency بالاتره، بهتره درست lock بشه).
 */
class CreditPool
{
    /**
     * حداکثر درخواست هم‌زمان مجاز روی هر کلید. بعد از این سقف، حتی اگه
     * کلید status=active باشه، دیگه انتخاب نمی‌شه و نوبت کلید بعدی می‌رسه.
     * این عدد رو با توجه به rate-limit واقعی هر provider تیون کن؛ ۳ فقط
     * یک نقطه‌ی شروع منطقیه.
     */
    public const MAX_CONCURRENT_PER_KEY = 3;

    /**
     * فعال‌ترین (کم‌بارترین) کلید یک type رو رزرو می‌کنه: بلافاصله
     * active_requests رو +۱ می‌کنه تا هیچ پروسه‌ی دیگه‌ای همون سهمیه رو
     * نگیره. حتماً بعد از اتمام کار (موفق یا ناموفق) release() صدا زده بشه.
     *
     * @return CreditCloudflare|null  null یعنی هیچ کلید فعالی زیر سقف ظرفیت نیست
     *                                 (caller باید بره سراغ fallback بعدی، نه صف بکشه)
     */
    public static function acquire(string $type, array $excludedIds = [], ?int $cooldownHours = null): ?CreditCloudflare
    {
        if ($cooldownHours !== null) {
            self::reactivateIfCooledDown($type, $cooldownHours);
        }

        return DB::transaction(function () use ($type, $excludedIds) {
            $credit = CreditCloudflare::where('type', $type)
                ->where('status', 'active')
                ->where('active_requests', '<', self::MAX_CONCURRENT_PER_KEY)
                ->whereNotIn('id', $excludedIds)
                ->orderBy('active_requests') // کم‌بارترین اول
                ->orderBy('updated_at')      // بین هم‌سطح‌ها، قدیمی‌ترین/کمتر-اخیراً-استفاده‌شده اول
                ->lockForUpdate()
                ->first();

            if (! $credit) {
                return null;
            }

            $credit->increment('active_requests');

            return $credit;
        });
    }

    /**
     * سهمیه‌ی رزرو‌شده رو آزاد می‌کنه. باید دقیقاً یک‌بار به ازای هر acquire()
     * موفق صدا زده بشه (در finally، نه فقط مسیر موفقیت).
     */
    public static function release(?int $creditId): void
    {
        if (! $creditId) {
            return;
        }

        CreditCloudflare::whereKey($creditId)
            ->where('active_requests', '>', 0)
            ->decrement('active_requests');
    }

    /**
     * محافظ در برابر «گیر کردن» شمارنده روی عدد غلط اگر یک پروسه بدون صدا
     * زدن release() بمیره (kill شدن ورکر، crash غیرمنتظره). پیشنهاد می‌شه
     * این متد از یک Scheduled Command هر چند دقیقه یک‌بار صدا زده بشه:
     *
     *   $schedule->call(fn () => CreditPool::resetStale('shortapi'))->everyFiveMinutes();
     *   $schedule->call(fn () => CreditPool::resetStale('kieai'))->everyFiveMinutes();
     */
    public static function resetStale(string $type, int $staleMinutes = 15): int
    {
        $affected = CreditCloudflare::where('type', $type)
            ->where('active_requests', '>', 0)
            ->where('updated_at', '<=', now()->subMinutes($staleMinutes))
            ->update(['active_requests' => 0]);

        if ($affected > 0) {
            Log::warning('CreditPool: active_requests گیرکرده بازنشانی شد', [
                'type' => $type,
                'affected_rows' => $affected,
            ]);
        }

        return $affected;
    }

   protected static function reactivateIfCooledDown(string $type, int $cooldownHours): void
{
    // فیکس باگ: قبلاً فقط چک می‌شد "آیا اصلاً یک کلید active وجود داره".
    // یعنی اگه یک کلید active داشتیم ولی همون یکی هم به سقف
    // MAX_CONCURRENT_PER_KEY رسیده بود (اشباع، نه inactive)، این تابع فکر
    // می‌کرد کلید فعال داریم و کلیدهای دیگه‌ای که cooldown‌شون تموم شده رو
    // دوباره فعال نمی‌کرد — در نتیجه acquire() فوراً null برمی‌گردوند و
    // مستقیم می‌رفت fallback، حتی وقتی چند کلید دیگه فقط منتظر پایان
    // cooldown بودن.
    $hasCapacity = CreditCloudflare::where('type', $type)
        ->where('status', 'active')
        ->where('active_requests', '<', self::MAX_CONCURRENT_PER_KEY)
        ->exists();

    if ($hasCapacity) {
        return;
    }

    $oldest = CreditCloudflare::where('type', $type)
        ->where('status', 'inactive')
        ->where('updated_at', '<=', now()->subHours($cooldownHours))
        ->orderBy('updated_at')
        ->first();

    if ($oldest) {
        Log::info('CreditPool: کردیت غیرفعال دوباره فعال شد (پایان کول‌داون)', [
            'type' => $type,
            'credit_id' => $oldest->id,
        ]);

        $oldest->update(['status' => 'active']);
    }
}
}
