<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\User;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscountController extends Controller
{
    /**
     * وضعیت تخفیف قابل‌استفاده‌ی کاربر لاگین‌کرده (اختصاصی یا کلی، هرکدوم که فعاله)
     * برای نمایش toast/تایمر توی فرانت.
     */
    public function me(Request $request, DiscountService $discountService): JsonResponse
    {
        $user = $request->user();

        $discount = $discountService->usableUserDiscount($user)
            ?? $discountService->usableGlobalDiscount();

        if (! $discount) {
            return response()->json(['discount' => null]);
        }

        return response()->json([
            'discount' => [
                'id'           => $discount->id,
                'code'         => $discount->code,
                'scope'        => $discount->user_id ? 'user' : 'global',
                'percentage'   => $discount->percentage,
                'expires_at'   => $discount->expires_at?->toIso8601String(),
                'seconds_left' => $discount->expires_at
                    ? max(0, now()->diffInSeconds($discount->expires_at, false))
                    : null,
            ],
        ]);
    }

    /**
     * لیست تخفیف‌ها برای پنل ادمین (جدیدترین‌ها اول).
     */
    public function index(Request $request): JsonResponse
    {
        $discounts = Discount::with('user:id,name,phone')
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($discounts);
    }

    /**
     * ساخت/جایگزینی تخفیف کلی سایت.
     * body: { percentage: 20, duration_minutes: 120 }  (duration_minutes اختیاریه، null یعنی بدون انقضا)
     */
    public function storeGlobal(Request $request, DiscountService $discountService): JsonResponse
    {
        $data = $request->validate([
            'percentage'       => 'required|integer|min:1|max:100',
            'duration_minutes' => 'nullable|integer|min:1',
        ]);

        $discount = $discountService->setGlobalDiscount(
            percentage: $data['percentage'],
            durationMinutes: $data['duration_minutes'] ?? null,
        );

        return response()->json(['discount' => $discount], 201);
    }

    /**
     * اختصاص تخفیف به یک کاربر.
     * body: { user_id: 5, percentage: 30, duration_minutes: 20, activate_on_login: true }
     */
    public function storeForUser(Request $request, DiscountService $discountService): JsonResponse
    {
        $data = $request->validate([
            'user_id'           => 'required|exists:users,id',
            'percentage'        => 'required|integer|min:1|max:100',
            'duration_minutes'  => 'required|integer|min:1',
            'activate_on_login' => 'boolean',
        ]);

        $user = User::findOrFail($data['user_id']);

        $discount = $discountService->assignToUser(
            user: $user,
            percentage: $data['percentage'],
            durationMinutes: $data['duration_minutes'],
            activateOnLogin: (bool) ($data['activate_on_login'] ?? false),
        );

        return response()->json(['discount' => $discount], 201);
    }

    /**
     * غیرفعال کردن دستیِ یک تخفیف (چه کلی چه اختصاصی).
     */
    public function revoke(Discount $discount): JsonResponse
    {
        $discount->update(['is_active' => false]);

        return response()->json(['message' => 'Discount disabled.']);
    }
}
