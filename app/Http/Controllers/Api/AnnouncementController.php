<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\GoogleSearchConsoleConnection;
use App\Models\Site;
use App\Models\SiteSearchConsoleProperty;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->getActiveSubscription($request->user());

        // «پولی» یعنی اشتراک فعال و غیررایگان دارد؛ بقیه (رایگان، تمام‌شده، بدون اشتراک) «رایگان» حساب می‌شوند
        $isPaid = $subscription && ! $subscription->plan->is_free;

        $user = $request->user();

        // «وصل» یعنی حساب گوگل سالم وصل است و دست‌کم یکی از سایت‌هایش property سرچ کنسول دارد
        $gscConnected = GoogleSearchConsoleConnection::where('user_id', $user->id)->where('status', 'connected')->exists()
            && SiteSearchConsoleProperty::whereIn('site_id', Site::where('user_id', $user->id)->select('id'))->exists();

        $announcements = Announcement::query()
            ->where('is_active', true)
            ->where(function ($q) use ($subscription, $isPaid, $gscConnected) {
                $q->where('audience', 'all')
                    ->orWhere('audience', $isPaid ? 'paid' : 'free');

                if (! $gscConnected) {
                    $q->orWhere('audience', 'gsc_not_connected');
                }

                if ($subscription) {
                    $q->orWhere(function ($q) use ($subscription) {
                        $q->where('audience', 'plans')
                            ->whereHas('plans', fn ($p) => $p->where('plans.id', $subscription->plan_id));
                    });
                }
            })
            ->latest()
            ->get(['id', 'title', 'body', 'updated_at']);

        return response()->json([
            'has_announcement' => $announcements->isNotEmpty(),
            'announcements' => $announcements,
        ]);
    }
}
