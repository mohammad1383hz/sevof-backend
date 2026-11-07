<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\TokenUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Morilog\Jalali\Jalalian;

/**
 * داشبورد آنالیز مصرف هوش مصنوعی (کریدیت / هزینه دلاری / توکن)
 *
 * منبع تمام آمارها جدول token_usage_records است:
 *   - credit    : کریدیتی که واقعاً سوزانده شده (معیار اصلی)
 *   - cost_usd  : در دیتابیس «هزینه با سود» ذخیره شده؛ اما در این داشبورد
 *                 همیشه «هزینه‌ی واقعی پرداخت‌شده به ارائه‌دهنده» (بدون سود)
 *                 نمایش داده می‌شود. (ضریب سود موقع خواندن از هر ردیف برداشته
 *                 می‌شود؛ به rawCostExpr() نگاه کنید.)
 *   - *_tokens  : فقط برای اطلاعات تکمیلی
 *
 * فیلترهای سراسری (بازه شمسی، سرویس، مدل، کاربر، دسته، نوع کاربر) روی همه‌ی
 * بخش‌های صفحه (کارت‌ها، نمودارها، جدول‌ها و لیست کاربران) اعمال می‌شوند.
 *
 * «نوع کاربر» (plan_type):
 *   - paid : کاربری که حداقل یک‌بار (با هر وضعیتی: فعال/کنسل/اکسپایر) یک
 *            اشتراک غیررایگان گرفته است.
 *   - free : کاربری که هرگز اشتراک پولی نگرفته (فقط رایگان بوده یا اصلاً
 *            اشتراکی نداشته).
 */
class AdminUserUsageController extends Controller
{
    /**
     * نام فارسی سرویس‌ها (کلید = service_name در دیتابیس).
     * سرویس جدید اضافه شد و اینجا نبود، خودکار با نام خامش نمایش داده می‌شود.
     */
    private const SERVICE_LABELS = [
        'ai.site_analysis' => 'Site analysis',
        'ai.keyword_normalization' => 'Keyword normalization',
        'ai.article_idea_generation' => 'Article idea generation',
        'ai.idea_scoring' => 'Idea scoring',
    ];

    /**
     * کلماتی که اگر داخل service_name یا model باشند، آن ردیف «تصویر» حساب می‌شود.
     * اگر سرویس/مدل تصویری شما اسم دیگری دارد، اینجا اضافه کنید.
     */
    private const IMAGE_KEYWORDS = ['image', 'dall-e', 'dalle', 'imagen', 'flux', 'midjourney', 'stable-diffusion', 'sdxl'];

    /**
     * ضرایب سودی که موقع ذخیره روی cost_usd خورده‌اند.
     * باید با TokenUsageService یکی باشند. فقط برای برگرداندن cost_usd
     * به هزینه‌ی واقعی در نمایش ادمین استفاده می‌شوند.
     */
    private const TEXT_MARKUP = 1.20;   // اگر ضریب واقعی متن 1.32 است، فقط همین عدد را عوض کن
    private const IMAGE_MARKUP = 2.0;   // تصاویر OpenAI (gpt-image-2) و GapGPT (gpt-image-1-mini)

    /**
     * مدل‌های تصویری که روی cost_usd آن‌ها سود خورده (مقدار دقیق ستون model).
     * تصاویر با هزینه‌ی ثابت (Cloudflare / ShortAPI / Kie.ai) سود ندارند.
     */
    private const IMAGE_MARKUP_MODELS = ['gpt-image-2', 'gpt-image-1-mini'];

    public function index(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'credit_operator' => ['nullable', 'in:gte,lte,eq'],
            'credit_value' => ['nullable', 'numeric', 'min:0'],
            'period' => ['nullable', 'in:today,yesterday,this_week,last_week,this_month,last_month,all_time,custom'],
            'from_date' => ['nullable', 'regex:/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/'],
            'to_date' => ['nullable', 'regex:/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/'],
            'sort' => ['nullable', 'in:credit_desc,credit_asc,tokens_desc,tokens_asc,last_usage_desc'],
            'service' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'users_scope' => ['nullable', 'in:with_usage,all'],
            'category' => ['nullable', 'in:all,image,text'],
            'plan_type' => ['nullable', 'in:all,free,paid'],
            'group' => ['nullable', 'in:day,week,month'],
            'export_json' => ['nullable', 'boolean'],
            'download' => ['nullable', 'boolean'],
        ]);
        $group = $request->input('group', 'day');

        $period = $request->string('period')->toString() ?: 'all_time';
        [$from, $to] = $this->resolvePeriod($period, $request);

        $scope = [
            'service' => $request->input('service') ?: null,
            'model' => $request->input('model') ?: null,
            'user_id' => $request->filled('user_id') ? (int) $request->input('user_id') : null,
            'category' => $request->input('category', 'all'),
            'plan_type' => $request->input('plan_type', 'all'),
        ];

        /* ------------------------------------------------------------------
         | ۱) آمار کلی (KPI)
         * ------------------------------------------------------------------ */
        $t = $this->scoped($from, $to, $scope)
            ->selectRaw('COALESCE(SUM(credit),0) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('COALESCE(SUM(total_tokens),0) as tokens')
            ->selectRaw('COALESCE(SUM(input_tokens),0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens),0) as output_tokens')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COUNT(DISTINCT user_id) as users_count')
            ->first();

        $totals = [
            'credit' => (float) $t->credit,
            'cost_usd' => (float) $t->cost_usd,
            'tokens' => (int) $t->tokens,
            'input_tokens' => (int) $t->input_tokens,
            'output_tokens' => (int) $t->output_tokens,
            'requests' => (int) $t->requests,
            'users_count' => (int) $t->users_count,
        ];
        $totals['avg_credit_per_request'] = $totals['requests'] > 0 ? $totals['credit'] / $totals['requests'] : 0;
        $totals['avg_cost_per_request'] = $totals['requests'] > 0 ? $totals['cost_usd'] / $totals['requests'] : 0;
        $totals['credit_per_usd'] = $totals['cost_usd'] > 0 ? $totals['credit'] / $totals['cost_usd'] : 0;

        // مقایسه با بازه‌ی قبلی هم‌طول (فقط وقتی بازه محدود است)
        $comparison = null;
        if ($from && $to) {
            $len = max(1, $to->timestamp - $from->timestamp);
            $prevTo = $from->copy()->subSecond();
            $prevFrom = $prevTo->copy()->subSeconds($len);

            $p = $this->scoped($prevFrom, $prevTo, $scope)
                ->selectRaw('COALESCE(SUM(credit),0) as credit')
                ->selectRaw($this->rawCostSum('cost_usd'))
                ->first();

            $comparison = [
                'prev_credit' => (float) $p->credit,
                'prev_cost_usd' => (float) $p->cost_usd,
                'credit_delta_pct' => $p->credit > 0 ? (($totals['credit'] - $p->credit) / $p->credit) * 100 : null,
                'cost_delta_pct' => $p->cost_usd > 0 ? (($totals['cost_usd'] - $p->cost_usd) / $p->cost_usd) * 100 : null,
                'label' => $this->rangeLabel($prevFrom, $prevTo),
            ];
        }

        /* ------------------------------------------------------------------
         | ۱.۵) تفکیک کریدیت: کاربر رایگان در برابر کاربر پولی
         | مستقل از فیلتر «نوع کاربر» انتخاب‌شده محاسبه می‌شود تا همیشه هر دو
         | طرف مقایسه دیده شوند؛ بقیه‌ی فیلترها (بازه/سرویس/مدل/دسته) اعمال می‌شود.
         * ------------------------------------------------------------------ */
        $forcedScope = fn (string $type) => array_merge($scope, ['plan_type' => $type]);

        $freeStats = $this->scoped($from, $to, $forcedScope('free'))
            ->selectRaw('COALESCE(SUM(credit),0) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COUNT(DISTINCT user_id) as users_count')
            ->first();

        $paidStats = $this->scoped($from, $to, $forcedScope('paid'))
            ->selectRaw('COALESCE(SUM(credit),0) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COUNT(DISTINCT user_id) as users_count')
            ->first();

        $planBreakdown = [
            'free' => [
                'credit' => (float) $freeStats->credit,
                'cost_usd' => (float) $freeStats->cost_usd,
                'requests' => (int) $freeStats->requests,
                'users_count' => (int) $freeStats->users_count,
            ],
            'paid' => [
                'credit' => (float) $paidStats->credit,
                'cost_usd' => (float) $paidStats->cost_usd,
                'requests' => (int) $paidStats->requests,
                'users_count' => (int) $paidStats->users_count,
            ],
        ];

        $planCreditTotal = $planBreakdown['free']['credit'] + $planBreakdown['paid']['credit'];
        $planCostTotal = $planBreakdown['free']['cost_usd'] + $planBreakdown['paid']['cost_usd'];
        $planBreakdown['free']['share_pct'] = $planCreditTotal > 0 ? ($planBreakdown['free']['credit'] / $planCreditTotal) * 100 : 0;
        $planBreakdown['paid']['share_pct'] = $planCreditTotal > 0 ? ($planBreakdown['paid']['credit'] / $planCreditTotal) * 100 : 0;
        $planBreakdown['free']['cost_share_pct'] = $planCostTotal > 0 ? ($planBreakdown['free']['cost_usd'] / $planCostTotal) * 100 : 0;
        $planBreakdown['paid']['cost_share_pct'] = $planCostTotal > 0 ? ($planBreakdown['paid']['cost_usd'] / $planCostTotal) * 100 : 0;

        /* ------------------------------------------------------------------
         | ۲) روند روزانه
         * ------------------------------------------------------------------ */
        $daily = $this->scoped($from, $to, $scope)
            ->selectRaw('DATE(created_at) as usage_date')
            ->selectRaw('SUM(credit) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('COUNT(*) as requests')
            ->groupBy('usage_date')
            ->orderBy('usage_date')
            ->get()
            ->map(function ($row) {
                $row->label = Jalalian::fromCarbon(Carbon::parse($row->usage_date))->format('m/d');

                return $row;
            });

        // تجمیع روند بر اساس گام زمانی: روزانه / هفتگی (شنبه تا جمعه) / ماهانه (شمسی)
        $trend = $daily->groupBy(function ($row) use ($group) {
            $d = Carbon::parse($row->usage_date);

            if ($group === 'week') {
                return $d->copy()->startOfWeek(Carbon::SATURDAY)->format('Y-m-d');
            }
            if ($group === 'month') {
                $j = Jalalian::fromCarbon($d);

                return sprintf('%04d-%02d', $j->getYear(), $j->getMonth());
            }

            return $d->format('Y-m-d');
        })->map(function ($rows, $key) use ($group) {
            $label = match ($group) {
                'week' => 'Week ' . Jalalian::fromCarbon(Carbon::parse($key))->format('m/d'),
                'month' => str_replace('-', '/', $key),
                default => $rows->first()->label,
            };

            return (object) [
                'key' => $key,
                'label' => $label,
                'credit' => (float) $rows->sum('credit'),
                'cost_usd' => (float) $rows->sum('cost_usd'),
                'requests' => (int) $rows->sum('requests'),
            ];
        })->sortKeys()->values();

        /* ------------------------------------------------------------------
         | ۳) به تفکیک مدل
         * ------------------------------------------------------------------ */
        $byModel = $this->scoped($from, $to, $scope)
            ->select('model')
            ->selectRaw('SUM(credit) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('COUNT(*) as requests')
            ->groupBy('model')
            ->orderByDesc('credit')
            ->get()
            ->map(fn ($r) => $this->decorate($r, $totals))
            ->sortByDesc('credit')
            ->values();

        /* ------------------------------------------------------------------
         | ۴) به تفکیک سرویس (کاری که هوش مصنوعی انجام داده)
         * ------------------------------------------------------------------ */
        $byService = $this->scoped($from, $to, $scope)
            ->select('service_name')
            ->selectRaw('SUM(credit) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('COUNT(*) as requests')
            ->groupBy('service_name')
            ->orderByDesc('credit')
            ->get()
            ->map(function ($r) use ($totals) {
                $r->service_label = $this->serviceLabel($r->service_name);

                return $this->decorate($r, $totals);
            })
            ->sortByDesc('credit')
            ->values();

        /* ------------------------------------------------------------------
         | ۵) ماتریس سرویس × مدل  (مثلاً «تولید ایده مقاله» با «gpt-5.4-mini» چقدر خرج داشته)
         * ------------------------------------------------------------------ */
        $matrix = $this->scoped($from, $to, $scope)
            ->select('service_name', 'model')
            ->selectRaw('SUM(credit) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('COUNT(*) as requests')
            ->groupBy('service_name', 'model')
            ->orderByDesc('credit')
            ->get()
            ->map(function ($r) use ($totals) {
                $r->service_label = $this->serviceLabel($r->service_name);

                return $this->decorate($r, $totals);
            })
            ->sortByDesc('credit')
            ->values();

        /* ------------------------------------------------------------------
         | ۶) داده‌ی نمودارها
         * ------------------------------------------------------------------ */
        $matrixLookup = $matrix->keyBy(fn ($r) => $r->service_name . '|' . $r->model);

        $stackedDatasets = $byModel->map(function ($m) use ($byService, $matrixLookup) {
            return [
                'label' => $m->model,
                'data' => $byService->map(function ($s) use ($m, $matrixLookup) {
                    $cell = $matrixLookup->get($s->service_name . '|' . $m->model);

                    return $cell ? round((float) $cell->credit, 4) : 0;
                })->values(),
            ];
        })->values();

        $topUsers = collect();
        if (! $scope['user_id']) {
            $top = $this->scoped($from, $to, $scope)
                ->select('user_id')
                ->selectRaw('SUM(credit) as credit')
                ->selectRaw($this->rawCostSum('cost_usd'))
                ->groupBy('user_id')
                ->orderByDesc('credit')
                ->limit(10)
                ->get();

            $names = User::whereIn('id', $top->pluck('user_id'))->get()->keyBy('id');
            $topUsers = $top->map(function ($r) use ($names) {
                $u = $names->get($r->user_id);
                $r->display = $u ? ($u->name ?: ($u->phone ?: $u->email ?: ('#' . $u->id))) : ('#' . $r->user_id);

                return $r;
            });
        }

        $charts = [
            'daily' => [
                'labels' => $trend->pluck('label')->values(),
                'credit' => $trend->pluck('credit')->map(fn ($v) => round((float) $v, 4))->values(),
                'cost' => $trend->pluck('cost_usd')->map(fn ($v) => round((float) $v, 6))->values(),
                'requests' => $trend->pluck('requests')->map(fn ($v) => (int) $v)->values(),
            ],
            'models' => [
                'labels' => $byModel->pluck('model')->values(),
                'credit' => $byModel->pluck('credit')->map(fn ($v) => round((float) $v, 4))->values(),
                'cost' => $byModel->pluck('cost_usd')->map(fn ($v) => round((float) $v, 6))->values(),
            ],
            'services' => [
                'labels' => $byService->pluck('service_label')->values(),
                'credit' => $byService->pluck('credit')->map(fn ($v) => round((float) $v, 4))->values(),
                'cost' => $byService->pluck('cost_usd')->map(fn ($v) => round((float) $v, 6))->values(),
            ],
            'stacked' => [
                'labels' => $byService->pluck('service_label')->values(),
                'datasets' => $stackedDatasets,
            ],
            'topUsers' => [
                'labels' => $topUsers->pluck('display')->values(),
                'credit' => $topUsers->pluck('credit')->map(fn ($v) => round((float) $v, 4))->values(),
            ],
            'planType' => [
                'labels' => ['Free', 'Paid'],
                'credit' => [
                    round($planBreakdown['free']['credit'], 4),
                    round($planBreakdown['paid']['credit'], 4),
                ],
                'cost' => [
                    round($planBreakdown['free']['cost_usd'], 6),
                    round($planBreakdown['paid']['cost_usd'], 6),
                ],
            ],
        ];

        /* ------------------------------------------------------------------
         | گزارش کامل برای خروجی (کپی/دانلود جهت تحلیل با AI)
         | برای حفظ حریم خصوصی، کاربران فقط با شناسه نمایش داده می‌شوند.
         * ------------------------------------------------------------------ */
        $r = fn ($v, $d = 4) => round((float) $v, $d);
        $row = fn ($x) => [
            'credit' => $r($x->credit),
            'cost_usd' => $r($x->cost_usd, 6),
            'requests' => (int) $x->requests,
            'tokens' => (int) $x->tokens,
            'share_of_total_credit_pct' => $r($x->share, 2),
            'avg_credit_per_request' => $r($x->avg_credit),
            'avg_cost_usd_per_request' => $r($x->avg_cost, 6),
        ];

        $report = [
            'generated_at_jalali' => Jalalian::now()->format('Y/m/d H:i'),
            'filters' => [
                'period' => $this->periodLabel($period),
                'range' => $this->rangeLabel($from, $to),
                'category' => ['all' => 'All', 'image' => 'Image only', 'text' => 'No image'][$scope['category']] ?? 'All',
                'plan_type' => ['all' => 'All', 'free' => 'Free only', 'paid' => 'Paid only'][$scope['plan_type']] ?? 'All',
                'service' => $scope['service'],
                'model' => $scope['model'],
                'user' => $scope['user_id'] ? ('User #' . $scope['user_id']) : 'All users',
                'trend_granularity' => ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'][$group],
            ],
            'totals' => [
                'credit_burned' => $r($totals['credit']),
                'cost_usd' => $r($totals['cost_usd'], 6),
                'requests' => $totals['requests'],
                'active_users' => $totals['users_count'],
                'avg_credit_per_request' => $r($totals['avg_credit_per_request']),
                'avg_cost_usd_per_request' => $r($totals['avg_cost_per_request'], 6),
                'credits_per_usd' => $r($totals['credit_per_usd'], 2),
                'tokens_total' => $totals['tokens'],
                'tokens_input' => $totals['input_tokens'],
                'tokens_output' => $totals['output_tokens'],
            ],
            'free_vs_paid_breakdown' => [
                'note' => 'paid = a user who has had a non-free plan at least once (active, cancelled or expired). free = a user who never had a paid plan.',
                'free' => [
                    'credit' => $r($planBreakdown['free']['credit']),
                    'cost_usd' => $r($planBreakdown['free']['cost_usd'], 6),
                    'requests' => $planBreakdown['free']['requests'],
                    'users_count' => $planBreakdown['free']['users_count'],
                    'share_of_credit_pct' => $r($planBreakdown['free']['share_pct'], 2),
                    'share_of_cost_pct' => $r($planBreakdown['free']['cost_share_pct'], 2),
                ],
                'paid' => [
                    'credit' => $r($planBreakdown['paid']['credit']),
                    'cost_usd' => $r($planBreakdown['paid']['cost_usd'], 6),
                    'requests' => $planBreakdown['paid']['requests'],
                    'users_count' => $planBreakdown['paid']['users_count'],
                    'share_of_credit_pct' => $r($planBreakdown['paid']['share_pct'], 2),
                    'share_of_cost_pct' => $r($planBreakdown['paid']['cost_share_pct'], 2),
                ],
            ],
            'previous_period_comparison' => $comparison ? [
                'range' => $comparison['label'],
                'prev_credit' => $r($comparison['prev_credit']),
                'prev_cost_usd' => $r($comparison['prev_cost_usd'], 6),
                'credit_change_pct' => is_null($comparison['credit_delta_pct']) ? null : $r($comparison['credit_delta_pct'], 1),
                'cost_change_pct' => is_null($comparison['cost_delta_pct']) ? null : $r($comparison['cost_delta_pct'], 1),
            ] : null,
            'trend' => $trend->map(fn ($t) => [
                'period' => $t->label,
                'credit' => $r($t->credit),
                'cost_usd' => $r($t->cost_usd, 6),
                'requests' => $t->requests,
            ])->values(),
            'by_model' => $byModel->map(fn ($m) => ['model' => $m->model] + $row($m))->values(),
            'by_service' => $byService->map(fn ($m) => ['service' => $m->service_label, 'service_key' => $m->service_name] + $row($m))->values(),
            'by_service_and_model' => $matrix->map(fn ($m) => ['service' => $m->service_label, 'service_key' => $m->service_name, 'model' => $m->model] + $row($m))->values(),
            'top_users' => $topUsers->map(fn ($u) => [
                'user' => 'User #' . $u->user_id,
                'credit' => $r($u->credit),
                'cost_usd' => $r($u->cost_usd, 6),
            ])->values(),
        ];

        /* ------------------------------------------------------------------
         | خروجی JSON مستقیم (برای دادن به AI یا مصرف بیرونی)
         | با افزودن ?export_json=1 به همین URL فیلترشده فعال می‌شود.
         | با ?export_json=1&download=1 به‌صورت فایل دانلود می‌شود.
         * ------------------------------------------------------------------ */
        if ($request->boolean('export_json')) {
            $response = response()->json($report, 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            if ($request->boolean('download')) {
                $filename = 'ai-usage-report-' . Jalalian::now()->format('Y-m-d') . '.json';
                $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            }

            return $response;
        }

        /* ------------------------------------------------------------------
         | ۷) جدول کاربران (با همان فیلترهای سراسری)
         * ------------------------------------------------------------------ */
        // نکته: alias نباید «usage» باشد چون USAGE در MySQL کلمه‌ی رزرو‌شده است
        $statsSub = $this->scoped($from, $to, $scope, false)
            ->select('user_id')
            ->selectRaw('SUM(credit) as period_credit')
            ->selectRaw('SUM(total_tokens) as period_tokens')
            ->selectRaw($this->rawCostSum('period_cost_usd'))
            ->selectRaw('COUNT(*) as period_requests')
            ->selectRaw('MAX(created_at) as last_usage_at')
            ->groupBy('user_id');

        $lifetimeSub = TokenUsage::query()
            ->select('user_id')
            ->selectRaw('SUM(credit) as lifetime_credit')
            ->selectRaw($this->rawCostSum('lifetime_cost_usd'))
            ->groupBy('user_id');

        $usersQuery = User::query()
            ->select('users.id', 'users.name', 'users.phone', 'users.email')
            ->leftJoinSub($statsSub, 'u_stats', 'u_stats.user_id', '=', 'users.id')
            ->leftJoinSub($lifetimeSub, 'u_life', 'u_life.user_id', '=', 'users.id')
            ->addSelect([
                'u_stats.period_credit',
                'u_stats.period_tokens',
                'u_stats.period_cost_usd',
                'u_stats.period_requests',
                'u_stats.last_usage_at',
                'u_life.lifetime_credit',
                'u_life.lifetime_cost_usd',
            ]);

        if ($scope['user_id']) {
            $usersQuery->where('users.id', $scope['user_id']);
        }

        // پیش‌فرض: فقط کاربرانی که در این فیلتر مصرف داشته‌اند
        $usersScope = $request->input('users_scope', 'with_usage');
        if ($usersScope === 'with_usage') {
            $usersQuery->whereNotNull('u_stats.user_id');
        }

        if ($search = $request->string('search')->toString()) {
            $usersQuery->where(function ($q) use ($search) {
                $q->where('users.phone', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('credit_value')) {
            $operator = match ($request->string('credit_operator')->toString() ?: 'gte') {
                'lte' => '<=',
                'eq' => '=',
                default => '>=',
            };

            $usersQuery->whereRaw('COALESCE(u_stats.period_credit, 0) ' . $operator . ' ?', [(float) $request->input('credit_value')]);
        }

        match ($request->string('sort')->toString() ?: 'credit_desc') {
            'credit_asc' => $usersQuery->orderByRaw('COALESCE(u_stats.period_credit, 0) asc'),
            'tokens_desc' => $usersQuery->orderByRaw('COALESCE(u_stats.period_tokens, 0) desc'),
            'tokens_asc' => $usersQuery->orderByRaw('COALESCE(u_stats.period_tokens, 0) asc'),
            'last_usage_desc' => $usersQuery->orderByRaw('u_stats.last_usage_at IS NULL, u_stats.last_usage_at desc'),
            default => $usersQuery->orderByRaw('COALESCE(u_stats.period_credit, 0) desc'),
        };

        $users = $usersQuery->paginate(20)->withQueryString();

        $latestSubs = Subscription::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($group) => $group->first());

        // شناسه‌ی کاربرانی که در همین صفحه «پولی» حساب می‌شوند (برای ستون نوع کاربر)
        $paidUserIdsOnPage = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.is_free', false)
            ->whereIn('subscriptions.user_id', $users->pluck('id'))
            ->distinct()
            ->pluck('subscriptions.user_id')
            ->flip();

        $users->getCollection()->transform(function ($user) use ($latestSubs, $totals, $paidUserIdsOnPage) {
            $user->active_subscription = $latestSubs->get($user->id);
            $user->is_paid_user = $paidUserIdsOnPage->has($user->id);
            $user->share = $totals['credit'] > 0 ? ((float) $user->period_credit / $totals['credit']) * 100 : 0;
            $user->last_usage_at_jalali = $user->last_usage_at
                ? Jalalian::fromCarbon(Carbon::parse($user->last_usage_at))->format('Y/m/d H:i')
                : null;

            return $user;
        });

        /* ------------------------------------------------------------------
         | گزینه‌های فیلتر
         * ------------------------------------------------------------------ */
        $optionScope = ['service' => null, 'model' => null, 'user_id' => null, 'category' => $scope['category'], 'plan_type' => 'all'];
        $serviceOptions = $this->scoped(null, null, $optionScope)->distinct()->orderBy('service_name')->pluck('service_name')
            ->mapWithKeys(fn ($s) => [$s => $this->serviceLabel($s)]);
        $modelOptions = $this->scoped(null, null, $optionScope)->distinct()->orderBy('model')->pluck('model');

        return view('admin.usage.index', [
            'users' => $users,
            'totals' => $totals,
            'comparison' => $comparison,
            'planBreakdown' => $planBreakdown,
            'byModel' => $byModel,
            'byService' => $byService,
            'matrix' => $matrix,
            'charts' => $charts,
            'report' => $report,
            'serviceOptions' => $serviceOptions,
            'modelOptions' => $modelOptions,
            'selectedUser' => $scope['user_id'] ? User::find($scope['user_id']) : null,
            'filters' => [
                'search' => $request->input('search'),
                'credit_operator' => $request->input('credit_operator', 'gte'),
                'credit_value' => $request->input('credit_value'),
                'period' => $period,
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
                'sort' => $request->input('sort', 'credit_desc'),
                'service' => $scope['service'],
                'model' => $scope['model'],
                'user_id' => $scope['user_id'],
                'users_scope' => $usersScope,
                'category' => $scope['category'],
                'plan_type' => $scope['plan_type'],
                'group' => $group,
            ],
            'periodLabel' => $this->periodLabel($period),
            'rangeLabel' => $this->rangeLabel($from, $to),
        ]);
    }

    /**
     * جزئیات مصرف یک کاربر خاص (برای مودال).
     */
    public function show(Request $request, User $user)
    {
        $request->validate([
            'period' => ['nullable', 'in:today,yesterday,this_week,last_week,this_month,last_month,all_time,custom'],
            'from_date' => ['nullable', 'regex:/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/'],
            'to_date' => ['nullable', 'regex:/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/'],
        ]);

        $period = $request->string('period')->toString() ?: 'all_time';
        [$from, $to] = $this->resolvePeriod($period, $request);

        $dailyUsage = TokenUsage::query()
            ->selectRaw('DATE(created_at) as usage_date')
            ->selectRaw('SUM(credit) as credit')
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('COUNT(*) as requests')
            ->where('user_id', $user->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->groupBy('usage_date')
            ->orderByDesc('usage_date')
            ->get()
            ->map(function ($row) {
                $row->usage_date_jalali = Jalalian::fromCarbon(Carbon::parse($row->usage_date))->format('Y/m/d');

                return $row;
            });

        $usageByModel = TokenUsage::query()
            ->select('service_name', 'model')
            ->selectRaw('SUM(credit) as credit')
            ->selectRaw($this->rawCostSum('cost_usd'))
            ->selectRaw('SUM(total_tokens) as tokens')
            ->selectRaw('COUNT(*) as requests')
            ->where('user_id', $user->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->groupBy('service_name', 'model')
            ->orderByDesc('credit')
            ->get()
            ->map(function ($r) {
                $r->service_label = $this->serviceLabel($r->service_name);

                return $r;
            });

        // جمع‌ها از روی dailyUsage محاسبه می‌شوند (cost_usd آن‌ها قبلاً واقعی شده)
        $totals = [
            'credit' => $dailyUsage->sum('credit'),
            'tokens' => $dailyUsage->sum('tokens'),
            'cost_usd' => $dailyUsage->sum('cost_usd'),
            'requests' => $dailyUsage->sum('requests'),
        ];

        $lifetimeCredit = TokenUsage::where('user_id', $user->id)->sum('credit');

        $subscriptions = Subscription::where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->map(function ($sub) {
                $sub->starts_at_jalali = $sub->starts_at ? Jalalian::fromCarbon(Carbon::parse($sub->starts_at))->format('Y/m/d H:i') : null;
                $sub->ends_at_jalali = $sub->ends_at ? Jalalian::fromCarbon(Carbon::parse($sub->ends_at))->format('Y/m/d H:i') : null;

                return $sub;
            });

        $recentRecords = TokenUsage::where('user_id', $user->id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(30, ['*'], 'records_page')
            ->withQueryString();

        $recentRecords->getCollection()->transform(function ($record) {
            $record->created_at_jalali = Jalalian::fromCarbon(Carbon::parse($record->created_at))->format('Y/m/d H:i:s');
            $record->service_label = $this->serviceLabel($record->service_name);
            $record->cost_usd = $this->rawCostOf($record); // هزینه‌ی واقعی (بدون سود) — فقط برای نمایش

            return $record;
        });

        return view('admin.usage.show-modal', [
            'user' => $user,
            'dailyUsage' => $dailyUsage,
            'usageByModel' => $usageByModel,
            'totals' => $totals,
            'lifetimeCredit' => $lifetimeCredit,
            'subscriptions' => $subscriptions,
            'recentRecords' => $recentRecords,
            'filters' => [
                'period' => $period,
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date'),
            ],
            'periodLabel' => $this->periodLabel($period),
            'rangeLabel' => $this->rangeLabel($from, $to),
        ]);
    }

    /* ======================================================================
     | Helpers
     * ==================================================================== */

    /**
     * عبارت SQL «هزینه‌ی واقعی» هر ردیف (بدون سود):
     *  - تصویر OpenAI/GapGPT (IMAGE_MARKUP_MODELS) : cost_usd / IMAGE_MARKUP
     *  - سایر تصاویر (هزینه‌ی ثابت، بدون سود)       : همان cost_usd
     *  - متن                                        : cost_usd / TEXT_MARKUP
     */
    private function rawCostExpr(): string
    {
        $markupModels = implode(',', array_map(
            fn ($m) => "'" . addslashes($m) . "'",
            self::IMAGE_MARKUP_MODELS
        ));

        $imageLike = [];
        foreach (self::IMAGE_KEYWORDS as $kw) {
            $kw = addslashes($kw);
            $imageLike[] = "COALESCE(service_name, '') LIKE '%{$kw}%'";
            $imageLike[] = "COALESCE(model, '') LIKE '%{$kw}%'";
        }
        $imageLike = implode(' OR ', $imageLike);

        $imageMarkup = self::IMAGE_MARKUP;
        $textMarkup = self::TEXT_MARKUP;

        return "(CASE
            WHEN model IN ({$markupModels}) THEN cost_usd / {$imageMarkup}
            WHEN ({$imageLike}) THEN cost_usd
            ELSE cost_usd / {$textMarkup}
        END)";
    }

    /**
     * جایگزین «SUM(cost_usd) as ...» — جمع هزینه‌ی واقعی با همان alias.
     */
    private function rawCostSum(string $alias = 'cost_usd'): string
    {
        return "COALESCE(SUM({$this->rawCostExpr()}), 0) as {$alias}";
    }

    /**
     * همان منطق rawCostExpr برای یک رکورد تکی در PHP (جدول رکوردهای اخیر).
     */
    private function rawCostOf($record): float
    {
        $cost = (float) $record->cost_usd;

        if (in_array($record->model, self::IMAGE_MARKUP_MODELS, true)) {
            return $cost / self::IMAGE_MARKUP;
        }

        $haystack = strtolower(($record->service_name ?? '') . '|' . ($record->model ?? ''));
        foreach (self::IMAGE_KEYWORDS as $kw) {
            if (str_contains($haystack, $kw)) {
                return $cost; // تصویر با هزینه‌ی ثابت
            }
        }

        return $cost / self::TEXT_MARKUP;
    }

    /**
     * کوئری پایه‌ی مصرف با فیلترهای سراسری (بازه، سرویس، مدل، کاربر، دسته، نوع کاربر).
     */
    private function scoped(?Carbon $from, ?Carbon $to, array $scope, bool $includeUser = true)
    {
        return TokenUsage::query()
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($scope['service'], fn ($q, $v) => $q->where('service_name', $v))
            ->when($scope['model'], fn ($q, $v) => $q->where('model', $v))
            ->when($includeUser && $scope['user_id'], fn ($q, $v) => $q->where('user_id', $v))
            // دسته‌بندی: image = فقط تصویر، text = همه‌ی مصرف‌ها به‌جز تصویر
            ->when(($scope['category'] ?? 'all') === 'image', function ($q) {
                [$sql, $bindings] = $this->imageMatchSql();

                return $q->whereRaw($sql, $bindings);
            })
            ->when(($scope['category'] ?? 'all') === 'text', function ($q) {
                [$sql, $bindings] = $this->imageMatchSql();

                return $q->whereRaw("NOT {$sql}", $bindings);
            })
            // نوع کاربر: paid = هر کاربری که حداقل یک‌بار (با هر وضعیتی) اشتراک
            // غیررایگان گرفته؛ free = کاربری که هرگز اشتراک پولی نگرفته.
            ->when(($scope['plan_type'] ?? 'all') === 'paid', function ($q) {
                $q->whereIn('user_id', $this->paidUserIdsSubQuery());
            })
            ->when(($scope['plan_type'] ?? 'all') === 'free', function ($q) {
                $q->whereNotIn('user_id', $this->paidUserIdsSubQuery());
            });
    }

    /**
     * ساب‌کوئری شناسه‌ی کاربرانی که تا حالا (با هر وضعیتی: فعال/کنسل/اکسپایر)
     * حداقل یک‌بار یک اشتراک غیررایگان گرفته‌اند.
     */
    private function paidUserIdsSubQuery()
    {
        return function ($query) {
            $query->select('user_id')
                ->from('subscriptions')
                ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->where('plans.is_free', false);
        };
    }

    /**
     * شرط SQL تشخیص ردیف‌های تصویر (روی service_name و model).
     */
    private function imageMatchSql(): array
    {
        $parts = [];
        $bindings = [];

        foreach (self::IMAGE_KEYWORDS as $kw) {
            $parts[] = "COALESCE(service_name, '') LIKE ?";
            $parts[] = "COALESCE(model, '') LIKE ?";
            $bindings[] = "%{$kw}%";
            $bindings[] = "%{$kw}%";
        }

        return ['(' . implode(' OR ', $parts) . ')', $bindings];
    }

    /**
     * سهم درصدی و میانگین‌ها را به هر ردیف اضافه می‌کند.
     */
    private function decorate($row, array $totals)
    {
        $row->credit = (float) $row->credit;
        $row->cost_usd = (float) $row->cost_usd;
        $row->requests = (int) $row->requests;
        $row->share = $totals['credit'] > 0 ? ($row->credit / $totals['credit']) * 100 : 0;
        $row->cost_share = $totals['cost_usd'] > 0 ? ($row->cost_usd / $totals['cost_usd']) * 100 : 0;
        $row->avg_credit = $row->requests > 0 ? $row->credit / $row->requests : 0;
        $row->avg_cost = $row->requests > 0 ? $row->cost_usd / $row->requests : 0;

        return $row;
    }

    private function serviceLabel(?string $name): string
    {
        if (! $name) {
            return '—';
        }

        return self::SERVICE_LABELS[$name] ?? $name;
    }

    private function resolvePeriod(string $period, Request $request): array
    {
        $now = Carbon::now();

        return match ($period) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'yesterday' => [
                $now->copy()->subDay()->startOfDay(),
                $now->copy()->subDay()->endOfDay(),
            ],
            // هفته ایرانی: شنبه تا جمعه
            'this_week' => [
                $now->copy()->startOfWeek(Carbon::SATURDAY),
                $now->copy()->endOfWeek(Carbon::FRIDAY),
            ],
            'last_week' => [
                $now->copy()->subWeek()->startOfWeek(Carbon::SATURDAY),
                $now->copy()->subWeek()->endOfWeek(Carbon::FRIDAY),
            ],
            'this_month' => $this->currentJalaliMonthRange($now),
            'last_month' => $this->previousJalaliMonthRange($now),
            'custom' => $this->customRange($request),
            default => [null, null], // all_time
        };
    }

    private function currentJalaliMonthRange(Carbon $anchor): array
    {
        $j = Jalalian::fromCarbon($anchor);

        return $this->jalaliMonthRangeFor($j->getYear(), $j->getMonth());
    }

    private function previousJalaliMonthRange(Carbon $anchor): array
    {
        $j = Jalalian::fromCarbon($anchor);
        $year = $j->getYear();
        $month = $j->getMonth() - 1;

        if ($month < 1) {
            $month = 12;
            $year--;
        }

        return $this->jalaliMonthRangeFor($year, $month);
    }

    private function jalaliMonthRangeFor(int $year, int $month): array
    {
        $start = Jalalian::fromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month))->toCarbon()->startOfDay();

        $nextYear = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;

        $end = Jalalian::fromFormat('Y-m-d', sprintf('%04d-%02d-01', $nextYear, $nextMonth))
            ->toCarbon()
            ->startOfDay()
            ->subSecond();

        return [$start, $end];
    }

    private function customRange(Request $request): array
    {
        $from = $request->filled('from_date')
            ? $this->jalaliToCarbon($request->input('from_date'))->startOfDay()
            : null;

        $to = $request->filled('to_date')
            ? $this->jalaliToCarbon($request->input('to_date'))->endOfDay()
            : null;

        return [$from, $to];
    }

    private function jalaliToCarbon(string $jalaliDate): Carbon
    {
        $normalized = str_replace('/', '-', trim($jalaliDate));

        return Jalalian::fromFormat('Y-m-d', $normalized)->toCarbon();
    }

    private function periodLabel(string $period): string
    {
        return match ($period) {
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This week',
            'last_week' => 'Last week',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'custom' => 'Custom range',
            default => 'All time',
        };
    }

    private function rangeLabel(?Carbon $from, ?Carbon $to): ?string
    {
        if (! $from && ! $to) {
            return null;
        }

        $fromLabel = $from ? Jalalian::fromCarbon($from)->format('Y/m/d H:i') : '—';
        $toLabel = $to ? Jalalian::fromCarbon($to)->format('Y/m/d H:i') : '—';

        return "{$fromLabel} to {$toLabel}";
    }
}
