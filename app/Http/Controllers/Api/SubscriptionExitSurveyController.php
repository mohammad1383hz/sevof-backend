<?php
// app/Http/Controllers/Api/SubscriptionExitSurveyController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\SubscriptionExitSurvey;
use App\Models\User;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionExitSurveyController extends Controller
{
    private const REASONS = ['price', 'quality', 'no_website', 'other'];
    private const TRIGGERS = ['back_button', 'timeout'];

    /**
     * فعلاً تخفیف خودکار برای دلیل «قیمت» غیرفعال است.
     * برای فعال‌سازی دوباره: true کن.
     */
    private const PRICE_DISCOUNT_ENABLED = false;

    private const PRICE_DISCOUNT_PERCENTAGE = 15;
    private const PRICE_DISCOUNT_DURATION_MINUTES = 15;

    public function __construct(private DiscountService $discounts)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trigger_type' => 'required|in:' . implode(',', self::TRIGGERS),
            'reason_key'   => 'required|in:' . implode(',', self::REASONS),
            'reason_text'  => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        SubscriptionExitSurvey::create([
            'user_id'      => $user?->id,
            'trigger_type' => $validated['trigger_type'],
            'reason_key'   => $validated['reason_key'],
            'reason_text'  => $validated['reason_text'] ?? null,
            'page'         => 'subscription',
        ]);

        $discount = null;

        if (self::PRICE_DISCOUNT_ENABLED && $validated['reason_key'] === 'price' && $user) {
            $discount = $this->grantPriceDiscount($user);
        }

        return response()->json([
            'message'  => 'Saved. Thanks for your feedback',
            'discount' => $discount ? [
                'code'       => $discount->code,
                'percentage' => $discount->percentage,
                'expires_at' => $discount->expires_at,
            ] : null,
        ]);
    }

    /**
     * اگر کاربر همین الان تخفیف قابل‌استفادهٔ دیگری ندارد (نه کلی نه اختصاصی)،
     * یک تخفیف اختصاصی با تایمر کوتاه که همین الان فعال می‌شود برایش می‌سازد.
     */
    private function grantPriceDiscount(User $user): ?Discount
    {
        if ($this->discounts->usableUserDiscount($user) || $this->discounts->usableGlobalDiscount()) {
            return null;
        }

        return $this->discounts->assignToUser(
            user: $user,
            percentage: self::PRICE_DISCOUNT_PERCENTAGE,
            durationMinutes: self::PRICE_DISCOUNT_DURATION_MINUTES,
            activateOnLogin: false,
        );
    }
}
