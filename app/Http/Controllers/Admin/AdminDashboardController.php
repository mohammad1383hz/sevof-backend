<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

class AdminDashboardController extends Controller
{
    public function __construct(private readonly AdminAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        [$from, $to, $invalidDate] = $this->period($request);

        return view('admin.dashboard', [
            'report' => $this->analytics->dashboard($from, $to),
            // Jalali strings for the header and the filter inputs
            'from' => Jalalian::fromCarbon($from)->format('Y/m/d'),
            'to' => Jalalian::fromCarbon($to)->format('Y/m/d'),
            'invalidDate' => $invalidDate,
        ]);
    }

    /**
     * Filter inputs are Jalali (e.g. 1405/07/25). They are converted to Gregorian
     * Carbon instances here, so every query keeps working in Gregorian.
     *
     * @return array{0:Carbon,1:Carbon,2:bool} [from, to, invalidDate]
     */
    private function period(Request $request): array
    {
        $invalid = false;

        $toInput = $this->parseJalali($request->input('to'));
        if ($request->filled('to') && !$toInput) {
            $invalid = true;
        }
        $to = ($toInput ?? now())->copy()->endOfDay();

        $fromInput = $this->parseJalali($request->input('from'));
        if ($request->filled('from') && !$fromInput) {
            $invalid = true;
        }
        $from = $fromInput
            ? $fromInput->copy()->startOfDay()
            : $to->copy()->subDays(29)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to, $invalid];
    }

    /**
     * Parses 1405/07/25 (also 1405-7-25, Persian/Arabic digits).
     * A year >= 1700 is treated as Gregorian so old ?from=2026-10-01 links still work.
     * Returns null for empty or invalid dates (e.g. 1405/07/31).
     */
    private function parseJalali(mixed $input): ?Carbon
    {
        if (!is_string($input) || trim($input) === '') {
            return null;
        }

        $input = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '/', '،' => '/',
        ]);

        if (!preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $input, $m)) {
            return null;
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($y >= 1700) {
            return checkdate($mo, $d, $y) ? Carbon::create($y, $mo, $d, 0, 0, 0) : null;
        }

        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }

        [$gy, $gm, $gd] = array_map('intval', CalendarUtils::toGregorian($y, $mo, $d));

        // Round trip rejects impossible dates such as 1405/07/31
        if (array_map('intval', CalendarUtils::toJalali($gy, $gm, $gd)) !== [$y, $mo, $d]) {
            return null;
        }

        return Carbon::create($gy, $gm, $gd, 0, 0, 0);
    }
}
