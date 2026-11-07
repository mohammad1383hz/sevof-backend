<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PageVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageVisitController extends Controller
{
    /**
     * حداکثر مدت زمان معقول برای یک ویزیت (ثانیه).
     * اگر فاصله محاسبه‌شده از این بیشتر بود (مثلاً ویزیت رهاشده که هفته‌ها
     * left_at نگرفته)، به‌جای مقدار واقعی، همین سقف ثبت می‌شه تا هم
     * duration بی‌معنی توی آمار (report) وارد نشه و هم خطای احتمالی
     * out-of-range روی ستون جلوگیری بشه.
     */
    private const MAX_REASONABLE_DURATION_SECONDS = 60 * 60 * 4; // ۴ ساعت

    /**
     * شروع ویزیت — وقتی صفحه لود می‌شه صدا زده می‌شه.
     * یک رکورد با entered_at = الان می‌سازه و id رو برمی‌گردونه
     * تا فرانت اونو نگه داره و موقع خروج به /end بفرسته.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|string|max:100',
        ]);

        $visit = PageVisit::create([
            'user_id'    => $request->user()->id,
            'page'       => $request->input('page', 'subscription'),
            'entered_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['visit_id' => $visit->token],
        ]);
    }

    /**
     * heartbeat اختیاری — هر چند ثانیه یک‌بار از فرانت صدا زده می‌شه
     * تا اگه کاربر تب رو ببنده و درخواست end نرسه، بشه از روی آخرین
     * heartbeat مدت حضور رو تخمین زد (به‌جای اینکه visit برای همیشه باز بمونه).
     */
    public function heartbeat(Request $request, PageVisit $visit): JsonResponse
    {
        if ($visit->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $visit->touch();

        return response()->json(['success' => true]);
    }

    /**
     * پایان ویزیت — وقتی کاربر صفحه رو ترک می‌کنه (unmount / beforeunload) صدا زده می‌شه.
     * duration_seconds کاملاً سمت سرور از روی entered_at تا الان محاسبه می‌شه
     * (به مقدار duration ارسالی از کلاینت اعتماد نمی‌کنیم).
     *
     * نکته مهم: از abs() استفاده می‌کنیم چون در Carbon 3 رفتار پیش‌فرض
     * diffInSeconds() نسبت به Carbon 2 عوض شده و می‌تونه مقدار منفی
     * برگردونه (بسته به جهت مقایسه). چون ستون duration_seconds از نوع
     * unsignedInteger هست، مقدار منفی باعث خطای PDO "Out of range" می‌شه.
     * abs() این وابستگی به نسخه/جهت رو کاملاً حذف می‌کنه.
     */
    public function end(Request $request, PageVisit $visit): JsonResponse
    {
        if ($visit->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if ($visit->left_at) {
            // قبلاً بسته شده — دوباره پردازش نکن
            return response()->json(['success' => true]);
        }

        $now = now();

        // مستقل از نسخه Carbon و جهت آرگومان‌ها: همیشه مقدار مثبت
        $duration = abs($now->diffInSeconds($visit->entered_at));

        // محافظت در برابر ویزیت‌های رهاشدهٔ خیلی قدیمی (کاربر تب رو بسته،
        // end هیچ‌وقت نرسیده، و حالا بعد از روزها یه end دیرهنگام می‌رسه)
        $duration = min($duration, self::MAX_REASONABLE_DURATION_SECONDS);

        $visit->update([
            'left_at'          => $now,
            'duration_seconds' => $duration,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * گزارش برای ادمین — لیست ویزیت‌ها + آمار خلاصه.
     * فیلترهای اختیاری: from, to (تاریخ)، user_id
     */
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'page'     => 'nullable|string',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
            'user_id'  => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = PageVisit::query()
            ->with('user:id,name,email')
            ->whereNotNull('duration_seconds') // فقط ویزیت‌های بسته‌شده
            ->when($request->page, fn ($q) => $q->where('page', $request->page))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->from, fn ($q) => $q->whereDate('entered_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('entered_at', '<=', $request->to));

        $summary = [
            'total_visits'         => (clone $query)->count(),
            'unique_users'         => (clone $query)->distinct('user_id')->count('user_id'),
            'avg_duration_seconds' => (int) round((clone $query)->avg('duration_seconds') ?? 0),
            'max_duration_seconds' => (int) ((clone $query)->max('duration_seconds') ?? 0),
        ];

        $visits = $query
            ->orderByDesc('entered_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'summary' => $summary,
                'visits'  => $visits,
            ],
        ]);
    }
}
