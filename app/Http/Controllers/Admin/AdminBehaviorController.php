<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\BehaviorAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AdminBehaviorController extends Controller
{
    public function __construct(private readonly BehaviorAnalyticsService $analytics)
    {
    }

public function index(Request $request)
{
    [$from, $to] = $this->period($request);
    [$comparisonFrom, $comparisonTo] = $this->comparisonPeriod($from, $to, $request);
    $report = $this->analytics->report($from, $to, $comparisonFrom, $comparisonTo);
    $report['executive_summary'] = $this->analytics->executiveSummary($report);
    $simple = $this->analytics->simpleReport($from, $to);

    return view('admin.behavior.index', [
        'report' => $report,
        'simple' => $simple,
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
        'preset' => $request->string('preset')->toString(),
        'comparisonFrom' => $comparisonFrom?->toDateString(),
        'comparisonTo' => $comparisonTo?->toDateString(),
    ]);
}

    private function period(Request $request): array
    {
        $preset = $request->string('preset')->toString();
        $to = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : match ($preset) {
            'today' => $to->copy()->startOfDay(),
            'yesterday' => $to->copy()->subDay()->startOfDay(),
            '7d' => $to->copy()->subDays(6)->startOfDay(),
            '90d' => $to->copy()->subDays(89)->startOfDay(),
            default => $to->copy()->subDays(29)->startOfDay(),
        };

        if ($preset === 'yesterday' && !$request->filled('from') && !$request->filled('to')) {
            $to = now()->subDay()->endOfDay();
        }
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }
        return [$from, $to];
    }

    private function comparisonPeriod(Carbon $from, Carbon $to, Request $request): array
    {
        if (!$request->boolean('compare')) {
            return [null, null];
        }
        $days = $from->diffInDays($to) + 1;
        $comparisonTo = $from->copy()->subDay()->endOfDay();
        return [$comparisonTo->copy()->subDays($days - 1)->startOfDay(), $comparisonTo];
    }
}
