<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserLoginLog;
use App\Services\Admin\AdminAnalyticsService;
use Illuminate\Http\Request;
use Morilog\Jalali\Jalalian;
use Carbon\Carbon;

class AdminUserController extends Controller
{
    /**
     * تایپ‌هایی که در جدول user_login_logs مربوط به ورودهای واقعی خود کاربرن.
     * عمداً whitelist نوشتیم (نه blacklist) تا اگر بعداً یه روش ورود ادمین
     * (مثل impersonate) هم توی همین جدول لاگ زد، خودکار حساب نشه.
     * اگر تایپ جدیدی برای ورود کاربر اضافه شد (مثل password) این‌جا اضافه‌ش کن.
     */
    private const USER_LOGIN_TYPES = ['email', 'google', 'email_verify'];

    /**
     * مقدار status در جدول payments که یعنی «پرداخت موفق».
     * (pending و failed حساب نمی‌شن)
     */
    private const PAID_PAYMENT_STATUSES = ['success'];

    public function __construct(private readonly AdminAnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        $preset = $request->string('preset')->toString();
        $fromJalali = $request->string('from')->toString(); // e.g. 1403/06/20
        $toJalali = $request->string('to')->toString();
        $planFilter = $request->string('plan')->toString(); // slug پلن یا 'none'
        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString();

        // فیلتر ووکامرس: '' (همه) | 'yes' (دارای سایت ووکامرس) | 'no' (بدون سایت ووکامرس)
        $woocommerceFilter = $request->string('woocommerce')->toString();
        if (!in_array($woocommerceFilter, ['yes', 'no'], true)) {
            $woocommerceFilter = '';
        }

        // فیلتر خرید: '' (همه) | 'yes' (دارای خرید موفق) | 'no' (بدون خرید)
        $purchaseFilter = $request->string('purchase')->toString();
        if (!in_array($purchaseFilter, ['yes', 'no'], true)) {
            $purchaseFilter = '';
        }

        // جستجوی دقیق بر اساس شناسه کاربر (پشتیبانی از اعداد فارسی/عربی و #123)
        $userIdSearch = $this->normalizeDigits(trim($request->string('user_id')->toString()));
        $userIdSearch = trim(ltrim($userIdSearch, '#'));

        // تعداد آیتم در هر صفحه — قابل انتخاب از فرانت، پیش‌فرض 100
        $perPageOptions = [30, 50, 100, 200, 500];
        $perPage = (int) $request->integer('per_page');
        if (!in_array($perPage, $perPageOptions, true)) {
            $perPage = 100;
        }

        [$fromDate, $toDate] = $this->resolveDateRange($preset, $fromJalali, $toJalali);

        $isCreditSort = in_array($sort, ['credit_desc', 'credit_asc'], true);

        $query = $this->analytics
            ->usersQuery($search, $isCreditSort ? '' : $sort);

        if ($fromDate) {
            $query->where('created_at', '>=', $fromDate->copy()->startOfDay());
        }
        if ($toDate) {
            $query->where('created_at', '<=', $toDate->copy()->endOfDay());
        }

        // فیلتر دقیق شناسه کاربر
        if ($userIdSearch !== '') {
            if (ctype_digit($userIdSearch)) {
                $query->where('users.id', (int) $userIdSearch);
            } else {
                // ورودی نامعتبر (غیرعددی) => نتیجه خالی
                $query->whereRaw('1 = 0');
            }
        }

        if ($planFilter === 'none') {
            $query->whereNotIn('users.id', function ($sub) {
                $sub->select('user_id')
                    ->from('subscriptions')
                    ->where('status', Subscription::STATUS_ACTIVE);
            });
        } elseif ($planFilter) {
            $query->whereIn('users.id', function ($sub) use ($planFilter) {
                $sub->select('subscriptions.user_id')
                    ->from('subscriptions')
                    ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                    ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
                    ->where('plans.slug', $planFilter);
            });
        }

        // فیلتر ووکامرس: بر اساس سایت‌های (حذف‌نشده) کاربر
        if ($woocommerceFilter === 'yes') {
            $query->whereIn('users.id', function ($sub) {
                $sub->select('user_id')
                    ->from('sites')
                    ->where('is_woocommerce', 1)
                    ->whereNull('deleted_at');
            });
        } elseif ($woocommerceFilter === 'no') {
            $query->whereNotIn('users.id', function ($sub) {
                $sub->select('user_id')
                    ->from('sites')
                    ->where('is_woocommerce', 1)
                    ->whereNull('deleted_at')
                    ->whereNotNull('user_id');
            });
        }

        // فیلتر خرید: بر اساس پرداخت‌های موفق
        if ($purchaseFilter === 'yes') {
            $query->whereIn('users.id', function ($sub) {
                $sub->select('user_id')
                    ->from('payments')
                    ->whereIn('status', self::PAID_PAYMENT_STATUSES);
            });
        } elseif ($purchaseFilter === 'no') {
            $query->whereNotIn('users.id', function ($sub) {
                $sub->select('user_id')
                    ->from('payments')
                    ->whereIn('status', self::PAID_PAYMENT_STATUSES)
                    ->whereNotNull('user_id');
            });
        }

        if ($isCreditSort) {
            $this->applyCreditSort($query, $sort === 'credit_desc');
        }

        $registeredInRangeCount = User::query()
            ->when($fromDate, fn ($q) => $q->where('created_at', '>=', $fromDate->copy()->startOfDay()))
            ->when($toDate, fn ($q) => $q->where('created_at', '<=', $toDate->copy()->endOfDay()))
            ->count();

        $users = $query->paginate($perPage)->withQueryString();

        $userIds = collect($users->items())->pluck('id')->all();

        $activeSubscriptions = Subscription::query()
            ->with('plan')
            ->whereIn('user_id', $userIds)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->get()
            ->keyBy('user_id');

        $siteCounts = Site::query()
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        // تعداد سایت‌های ووکامرسی هر کاربر
        $wooSiteCounts = Site::query()
            ->whereIn('user_id', $userIds)
            ->where('is_woocommerce', 1)
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $articleCounts = Article::query()
            ->join('sites', 'sites.id', '=', 'articles.website_id')
            ->whereIn('sites.user_id', $userIds)
            ->selectRaw('sites.user_id as user_id, COUNT(articles.id) as total')
            ->groupBy('sites.user_id')
            ->pluck('total', 'user_id');

        // آخرین ورود واقعی خود کاربر (نه ورودی که ادمین با Impersonate انجام داده)
        // success = 1 یعنی ورود موفق؛ فقط تایپ‌های ورود واقعی کاربر لحاظ می‌شن.
        $lastLogins = UserLoginLog::query()
            ->whereIn('user_id', $userIds)
            ->where('success', true)
            ->whereIn('login_method', self::USER_LOGIN_TYPES)
            ->selectRaw('user_id, MAX(created_at) as last_login_at')
            ->groupBy('user_id')
            ->pluck('last_login_at', 'user_id');

        // پرداخت‌های موفق هر کاربر (به‌همراه پلن) → کلید: user_id
        $userPayments = Payment::query()
            ->with('plan:id,name')
            ->whereIn('user_id', $userIds)
            ->whereIn('status', self::PAID_PAYMENT_STATUSES)
            ->orderBy('created_at')
            ->get()
            ->groupBy('user_id');

        return view('admin.users.index', [
            'users' => $users,
            'activeSubscriptions' => $activeSubscriptions,
            'siteCounts' => $siteCounts,
            'wooSiteCounts' => $wooSiteCounts,
            'articleCounts' => $articleCounts,
            'lastLogins' => $lastLogins,
            'userPayments' => $userPayments,
            'search' => $search,
            'userIdSearch' => $userIdSearch,
            'sort' => $sort,
            'preset' => $preset,
            'fromJalali' => $fromJalali,
            'toJalali' => $toJalali,
            'planFilter' => $planFilter,
            'woocommerceFilter' => $woocommerceFilter,
            'purchaseFilter' => $purchaseFilter,
            'plans' => Plan::query()->where('is_active', true)->orderBy('price')->get(['id', 'name', 'slug']),
            'registeredInRangeCount' => $registeredInRangeCount,
            'rangeLabel' => $this->rangeLabel($preset, $fromDate, $toDate),
            'perPage' => $perPage,
            'perPageOptions' => $perPageOptions,
        ]);
    }

    public function show(User $user)
    {
        return view('admin.users.show', [
            'user' => $user,
            'snapshot' => $this->analytics->userSnapshot($user->id),
        ]);
    }

    /**
     * تبدیل اعداد فارسی/عربی به انگلیسی
     */
    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function resolveDateRange(string $preset, string $fromJalali, string $toJalali): array
    {
        if ($fromJalali || $toJalali) {
            $from = $fromJalali ? $this->jalaliToCarbon($fromJalali) : null;
            $to = $toJalali ? $this->jalaliToCarbon($toJalali) : null;
            return [$from, $to];
        }

        return match ($preset) {
            'today' => [Carbon::today(), Carbon::today()],
            'yesterday' => [Carbon::yesterday(), Carbon::yesterday()],
            '3d' => [Carbon::today()->subDays(2), Carbon::today()],
            '7d' => [Carbon::today()->subDays(6), Carbon::today()],
            '30d' => [Carbon::today()->subDays(29), Carbon::today()],
            '90d' => [Carbon::today()->subDays(89), Carbon::today()],
            default => [null, null],
        };
    }

    private function jalaliToCarbon(string $jalaliDate): ?Carbon
    {
        try {
            $normalized = str_replace('-', '/', trim($this->normalizeDigits($jalaliDate)));
            return Jalalian::fromFormat('Y/m/d', $normalized)->toCarbon();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function rangeLabel(string $preset, ?Carbon $from, ?Carbon $to): string
    {
        $labels = [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            '3d' => 'Last 3 days',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            '90d' => 'Last 90 days',
        ];

        if (isset($labels[$preset]) && !$from) {
            return $labels[$preset];
        }

        if ($preset && isset($labels[$preset])) {
            return $labels[$preset];
        }

        if ($from && $to) {
            return Jalalian::fromCarbon($from)->format('Y/m/d') . ' تا ' . Jalalian::fromCarbon($to)->format('Y/m/d');
        }
        if ($from) {
            return 'از ' . Jalalian::fromCarbon($from)->format('Y/m/d');
        }
        if ($to) {
            return 'تا ' . Jalalian::fromCarbon($to)->format('Y/m/d');
        }

        return 'Whole range';
    }

    private function applyCreditSort($query, bool $desc): void
    {
        $sub = Subscription::query()
            ->select('subscriptions.credit_used')
            ->whereColumn('subscriptions.user_id', 'users.id')
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->orderByDesc('subscriptions.id')
            ->limit(1);

        $sql = '(' . $sub->toSql() . ')';
        $bindings = $sub->getBindings();

        $query->reorder()
            ->orderByRaw("{$sql} IS NULL", $bindings)
            ->orderByRaw("{$sql} " . ($desc ? 'desc' : 'asc'), $bindings)
            ->orderByDesc('users.id');
    }
}
