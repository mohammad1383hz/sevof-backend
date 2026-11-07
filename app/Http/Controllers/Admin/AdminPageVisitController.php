<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageVisit;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminPageVisitController extends Controller
{
    public function index(Request $request)
    {
        [$from, $to] = $this->period($request);

        $selectedPage = $request->input('page');
        $userId = $request->input('user_id');
        $search = $request->input('search');

        // کوئری پایه بر اساس بازه زمانی و فیلترها
        $baseQuery = PageVisit::query()
            ->whereBetween('entered_at', [$from, $to])
            ->when($selectedPage, fn ($q) => $q->where('page', $selectedPage))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($search, function ($q) use ($search) {
                $q->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                              ->orWhere('email', 'like', "%{$search}%")
                              ->orWhere('phone', 'like', "%{$search}%");
                });
            });

        // کارت‌های KPI (خلاصه آمار)
        $summary = [
            'total_visits' => (clone $baseQuery)->count(),
            'unique_users' => (clone $baseQuery)->distinct('user_id')->count('user_id'),
            'avg_duration' => (int) round((clone $baseQuery)->whereNotNull('duration_seconds')->avg('duration_seconds') ?? 0),
            'total_duration' => (int) ((clone $baseQuery)->whereNotNull('duration_seconds')->sum('duration_seconds') ?? 0),
        ];

        // پربازدیدترین صفحات
        $topPages = (clone $baseQuery)
            ->selectRaw('page, COUNT(*) as visits_count, AVG(duration_seconds) as avg_duration')
            ->groupBy('page')
            ->orderByDesc('visits_count')
            ->take(6)
            ->get();

        // فعال‌ترین کاربران در این بازه
        $topUsers = (clone $baseQuery)
            ->selectRaw('user_id, COUNT(*) as visits_count, SUM(duration_seconds) as total_duration')
            ->with('user:id,name,email,phone')
            ->groupBy('user_id')
            ->orderByDesc('visits_count')
            ->take(6)
            ->get();

        // لیست صفحه‌بندی‌شده ویزیت‌ها
        $visits = (clone $baseQuery)
            ->with('user:id,name,email,phone')
            ->orderByDesc('entered_at')
            ->paginate(25)
            ->withQueryString();

        // لیست تمام صفحات یکتا جهت استفاده در Dropdown فیلتر
        $availablePages = PageVisit::distinct('page')->pluck('page');

        return view('admin.page-visits.index', [
            'summary'        => $summary,
            'topPages'       => $topPages,
            'topUsers'       => $topUsers,
            'visits'         => $visits,
            'availablePages' => $availablePages,
            'from'           => $from->toDateString(),
            'to'             => $to->toDateString(),
            'selectedPage'   => $selectedPage,
            'userId'         => $userId,
            'search'         => $search,
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
