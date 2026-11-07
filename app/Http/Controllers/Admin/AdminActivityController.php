<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminActivityController extends Controller
{
    public function __construct(private readonly AdminAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->period($request);
        $userId = $request->filled('user_id') ? (int) $request->integer('user_id') : null;
        $type = $request->string('type')->toString() ?: null;

        return view('admin.activities.index', [
            'activities' => $this->analytics->globalActivities($from, $to, 150, $userId, $type),
            'users' => User::query()->select('id', 'name', 'email')->latest()->limit(500)->get(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'userId' => $userId,
            'type' => $type,
        ]);
    }

    private function period(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : now()->endOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }
}
