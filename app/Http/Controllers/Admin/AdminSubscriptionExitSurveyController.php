<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionExitSurvey;
use App\Models\User;
use App\Services\DiscountService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class AdminSubscriptionExitSurveyController extends Controller
{
    public function index(Request $request)
    {
        $reasonKey = $request->string('reason_key')->toString() ?: null;
        $triggerType = $request->string('trigger_type')->toString() ?: null;

        $surveys = SubscriptionExitSurvey::query()
            ->with('user:id,name,email,phone')
            ->when($reasonKey, fn ($q) => $q->where('reason_key', $reasonKey))
            ->when($triggerType, fn ($q) => $q->where('trigger_type', $triggerType))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.subscription-exit-surveys.index', [
            'surveys' => $surveys,
            'reasonKey' => $reasonKey,
            'triggerType' => $triggerType,
        ]);
    }

    /**
     * اختصاص تخفیف به کاربری که در همین لیست نظرسنجی خروج از اشتراک نمایش داده می‌شود.
     * از همون DiscountService::assignToUser استفاده می‌کنه (حالت تخفیف اختصاصی کاربر).
     */
    public function assignDiscount(Request $request, User $user, DiscountService $discountService): RedirectResponse
    {
        $data = $request->validate([
            'percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'activate_on_login' => ['nullable', 'boolean'],
        ], [], [
            'percentage' => 'Discount percentage',
            'duration_minutes' => 'Duration (minutes)',
        ]);

        $discountService->assignToUser(
            user: $user,
            percentage: $data['percentage'],
            durationMinutes: $data['duration_minutes'],
            activateOnLogin: (bool) ($data['activate_on_login'] ?? false),
        );

        return back()->with('success', "{$data['percentage']}% discount saved for {$user->name}.");
    }
}
