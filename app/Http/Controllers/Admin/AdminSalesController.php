<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSalesController extends Controller
{
    public function __construct(private readonly AdminAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->period($request);
        $planId = $request->filled('plan_id') ? (int) $request->integer('plan_id') : null;
        $status = $request->string('status')->toString() ?: null;

        return view('admin.sales.index', array_merge(
            $this->analytics->salesReport($from, $to, $status, $planId),
            [
                'plans' => DB::table('plans')->orderBy('price')->get(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'status' => $status,
                'planId' => $planId,
            ],
        ));
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
