<?php

namespace App\Services\Admin;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAnalyticsService
{
    public function dashboard(Carbon $from, Carbon $to): array
    {
        $paymentRange = DB::table('payments')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('user_id', $this->adminUserIdsSubquery());
        $successfulPayments = (clone $paymentRange)->where('status', 'success');

        $totalAttempts = (int) (clone $paymentRange)->count();
        $successCount = (int) (clone $successfulPayments)->count();

        $sales = [
            'success_count' => $successCount,
            'pending_count' => (int) (clone $paymentRange)->where('status', 'pending')->count(),
            'failed_count' => (int) (clone $paymentRange)->where('status', 'failed')->count(),
            'revenue' => (int) (clone $successfulPayments)->sum('amount'),
            'average_order_value' => (int) round((float) ((clone $successfulPayments)->avg('amount') ?? 0)),
            'conversion_rate' => $totalAttempts > 0
                ? round(($successCount / $totalAttempts) * 100, 1)
                : 0,
        ];

        $completedArticles = DB::table('articles')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('website_id', $this->adminSiteIdsSubquery());

        $content = [
            'new_sites' => (int) DB::table('sites')
                ->whereBetween('created_at', [$from, $to])
                ->whereNotIn('user_id', $this->adminUserIdsSubquery())
                ->count(),
            'new_ideas' => (int) DB::table('article_ideas')
                ->whereBetween('created_at', [$from, $to])
                ->whereNotIn('website_id', $this->adminSiteIdsSubquery())
                ->count(),
            'new_articles' => (int) DB::table('articles')
                ->whereBetween('created_at', [$from, $to])
                ->whereNotIn('website_id', $this->adminSiteIdsSubquery())
                ->count(),
            'completed_articles' => (int) (clone $completedArticles)->count(),
            'published_articles' => $this->publishedArticlesCount($from, $to),
            'chat_messages' => Schema::hasTable('article_chat_messages')
                ? (int) DB::table('article_chat_messages')
                    ->join('articles', 'articles.id', '=', 'article_chat_messages.article_id')
                    ->whereBetween('article_chat_messages.created_at', [$from, $to])
                    ->whereNotIn('articles.website_id', $this->adminSiteIdsSubquery())
                    ->count()
                : 0,
            'research_sessions' => Schema::hasTable('content_research_sessions')
                ? (int) DB::table('content_research_sessions')
                    ->whereBetween('created_at', [$from, $to])
                    ->whereNotIn('user_id', $this->adminUserIdsSubquery())
                    ->count()
                : 0,
        ];

        $usageQuery = DB::table('token_usage_records')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('user_id', $this->adminUserIdsSubquery());
        $usage = [
            'requests' => (int) (clone $usageQuery)->count(),
            'tokens' => (int) (clone $usageQuery)->sum('total_tokens'),
            'credit' => round((float) ((clone $usageQuery)->sum('credit') ?? 0), 4),
            'cost_usd' => round((float) ((clone $usageQuery)->sum('cost_usd') ?? 0), 6),
        ];

        return [
            'period' => ['from' => $from, 'to' => $to],
            'users' => [
                'total' => (int) User::where('is_admin', false)->count(),
                'new' => (int) User::where('is_admin', false)->whereBetween('created_at', [$from, $to])->count(),
                'verified' => (int) User::where('is_admin', false)->whereNotNull('email_verified_at')->count(),
                'with_sites' => (int) DB::table('users')
                    ->where('users.is_admin', false)
                    ->whereExists(function ($query) {
                        $query->selectRaw('1')->from('sites')->whereColumn('sites.user_id', 'users.id');
                    })->count(),
            ],
            // All-time buyer stats (independent of the selected date range)
            'buyers' => $this->buyerStats(),
            'sales' => $sales,
            'subscriptions' => [
                'active' => (int) DB::table('subscriptions')->where('status', 'active')->whereNotIn('user_id', $this->adminUserIdsSubquery())->count(),
                'new' => (int) DB::table('subscriptions')->whereBetween('created_at', [$from, $to])->whereNotIn('user_id', $this->adminUserIdsSubquery())->count(),
                'expired' => (int) DB::table('subscriptions')->where('status', 'expired')->whereNotIn('user_id', $this->adminUserIdsSubquery())->count(),
                'cancelled' => (int) DB::table('subscriptions')->where('status', 'cancelled')->whereNotIn('user_id', $this->adminUserIdsSubquery())->count(),
            ],
            'content' => $content,
            'usage' => $usage,
            'sales_series' => $this->salesSeries($from, $to),
            'plan_sales' => $this->planSales($from, $to),
            'top_buyers' => $this->topBuyers($from, $to),
            'recent_activities' => $this->globalActivities($from, $to, 20),
        ];
    }

    /**
     * All-time buyer stats (ignores the dashboard date range).
     * A "buyer" is a non-admin user with at least one successful payment.
     */
    public function buyerStats(): array
    {
        $perUser = DB::table('payments')
            ->where('status', 'success')
            ->whereNotIn('user_id', $this->adminUserIdsSubquery())
            ->select('user_id', DB::raw('COUNT(*) as purchases'))
            ->groupBy('user_id');

        $row = DB::query()
            ->fromSub($perUser, 'buyer_counts')
            ->selectRaw('COUNT(*) as unique_buyers')
            ->selectRaw('COALESCE(SUM(CASE WHEN purchases > 1 THEN 1 ELSE 0 END), 0) as more_than_1')
            ->selectRaw('COALESCE(SUM(CASE WHEN purchases > 2 THEN 1 ELSE 0 END), 0) as more_than_2')
            ->selectRaw('COALESCE(SUM(CASE WHEN purchases > 3 THEN 1 ELSE 0 END), 0) as more_than_3')
            ->first();

        return [
            'unique' => (int) ($row->unique_buyers ?? 0),
            'more_than_1' => (int) ($row->more_than_1 ?? 0),
            'more_than_2' => (int) ($row->more_than_2 ?? 0),
            'more_than_3' => (int) ($row->more_than_3 ?? 0),
        ];
    }

    public function salesReport(Carbon $from, Carbon $to, ?string $status = null, ?int $planId = null): array
    {
        $base = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->join('plans', 'plans.id', '=', 'payments.plan_id')
            ->where('users.is_admin', false)
            ->whereBetween('payments.created_at', [$from, $to]);

        if ($status) {
            $base->where('payments.status', $status);
        }

        if ($planId) {
            $base->where('payments.plan_id', $planId);
        }

        $payments = (clone $base)
            ->select([
                'payments.*',
                'users.name as user_name',
                'users.email as user_email',
                'users.phone as user_phone',
                'plans.name as plan_name',
                'plans.slug as plan_slug',
            ])
            ->latest('payments.created_at')
            ->paginate(30)
            ->withQueryString();

        $successBase = (clone $base)->where('payments.status', 'success');

        return [
            'payments' => $payments,
            'summary' => [
                'transactions' => (int) (clone $base)->count(),
                'successful_transactions' => (int) (clone $successBase)->count(),
                'revenue' => (int) (clone $successBase)->sum('payments.amount'),
                'pending' => (int) (clone $base)->where('payments.status', 'pending')->count(),
                'failed' => (int) (clone $base)->where('payments.status', 'failed')->count(),
            ],
            'daily' => $this->salesSeries($from, $to, $status, $planId),
            'by_plan' => $this->planSales($from, $to, $status, $planId),
            'top_buyers' => $this->topBuyers($from, $to, $status, $planId),
        ];
    }

    public function usersQuery(?string $search = null, ?string $sort = null): Builder
    {
        $query = DB::table('users')
            ->select([
                'users.*',
                DB::raw('(SELECT COUNT(*) FROM sites WHERE sites.user_id = users.id) as sites_count'),
                DB::raw('(SELECT COUNT(*) FROM payments WHERE payments.user_id = users.id AND payments.status = "success") as successful_payments_count'),
                DB::raw('(SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.user_id = users.id AND payments.status = "success") as total_revenue'),
                DB::raw('(SELECT COUNT(*) FROM subscriptions WHERE subscriptions.user_id = users.id) as subscriptions_count'),
                DB::raw("(SELECT COUNT(*) FROM user_login_logs WHERE user_login_logs.user_id = users.id AND user_login_logs.success = 1 AND user_login_logs.login_method != 'admin_impersonate') as login_count"),
            ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $like = '%' . addcslashes($search, '%_') . '%';
                $q->where('users.name', 'like', $like)
                    ->orWhere('users.email', 'like', $like)
                    ->orWhere('users.phone', 'like', $like);
            });
        }

        match ($sort) {
            'revenue' => $query->orderByDesc('total_revenue'),
            'logins' => $query->orderByDesc('login_count'),
            'content' => $query->orderByDesc('sites_count'),
            default => $query->latest('users.created_at'),
        };

        return $query;
    }

    public function userSnapshot(int $userId): array
    {
        $sites = DB::table('sites')->where('user_id', $userId)->latest()->get();
        $siteIds = $sites->pluck('id')->all();

        $siteScopedCount = function (string $table) use ($siteIds): int {
            if (!$siteIds || !Schema::hasTable($table)) {
                return 0;
            }
            return (int) DB::table($table)->whereIn(
                in_array($table, ['article_ideas', 'articles']) ? 'website_id' : 'site_id',
                $siteIds
            )->count();
        };

        $articleIds = $siteIds
            ? DB::table('articles')->whereIn('website_id', $siteIds)->pluck('id')->all()
            : [];

        return [
            'payments' => DB::table('payments')->join('plans', 'plans.id', '=', 'payments.plan_id')
                ->where('payments.user_id', $userId)
                ->select('payments.*', 'plans.name as plan_name')
                ->latest('payments.created_at')->get(),
            'subscriptions' => DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
                ->where('subscriptions.user_id', $userId)
                ->select('subscriptions.*', 'plans.name as plan_name', 'plans.slug as plan_slug')
                ->latest('subscriptions.created_at')->get(),
            'sites' => $sites,
            'ideas' => $siteIds ? DB::table('article_ideas')->whereIn('website_id', $siteIds)->latest()->limit(100)->get() : collect(),
            'articles' => $siteIds ? DB::table('articles')->whereIn('website_id', $siteIds)->latest()->limit(100)->get() : collect(),
            'chat_messages' => $articleIds && Schema::hasTable('article_chat_messages')
                ? DB::table('article_chat_messages')->join('articles', 'articles.id', '=', 'article_chat_messages.article_id')
                    ->whereIn('article_chat_messages.article_id', $articleIds)
                    ->select('article_chat_messages.*', 'articles.title as article_title')
                    ->latest('article_chat_messages.created_at')->limit(100)->get()
                : collect(),
            'research_sessions' => Schema::hasTable('content_research_sessions')
                ? DB::table('content_research_sessions')->where('user_id', $userId)->latest()->limit(100)->get()
                : collect(),
            'connection_logs' => $siteIds && Schema::hasTable('site_connection_logs')
                ? DB::table('site_connection_logs')->whereIn('site_id', $siteIds)->latest()->limit(100)->get()
                : collect(),
            'agent_logs' => $siteIds && Schema::hasTable('agent_request_logs')
                ? DB::table('agent_request_logs')->whereIn('site_id', $siteIds)->latest()->limit(100)->get()
                : collect(),
            'usage' => DB::table('token_usage_records')->where('user_id', $userId)->latest()->limit(100)->get(),
            'logins' => Schema::hasTable('user_login_logs')
                ? DB::table('user_login_logs')->where('user_id', $userId)->latest('created_at')->limit(100)->get()
                : collect(),
            'timeline' => $this->userTimeline($userId, 150),
            'totals' => [
                'revenue' => (int) DB::table('payments')->where('user_id', $userId)->where('status', 'success')->sum('amount'),
                'payments' => (int) DB::table('payments')->where('user_id', $userId)->count(),
                'active_subscriptions' => (int) DB::table('subscriptions')->where('user_id', $userId)->where('status', 'active')->count(),
                'sites' => (int) DB::table('sites')->where('user_id', $userId)->count(),
                'ideas' => $siteScopedCount('article_ideas'),
                'articles' => $siteScopedCount('articles'),
                'tokens' => (int) DB::table('token_usage_records')->where('user_id', $userId)->sum('total_tokens'),
                'credit' => round((float) DB::table('token_usage_records')->where('user_id', $userId)->sum('credit'), 4),
                'logins' => Schema::hasTable('user_login_logs')
                    ? (int) DB::table('user_login_logs')->where('user_id', $userId)->where('success', true)->where('login_method', '!=', 'admin_impersonate')->count()
                    : 0,
            ],
        ];
    }

    public function userTimeline(int $userId, int $limit = 100): Collection
    {
        $events = collect();
        $push = static function (Collection $events, string $type, string $label, $at, array $data = []) use (&$push): void {
            if ($at) {
                $events->push(array_merge([
                    'type' => $type,
                    'label' => $label,
                    'at' => $at,
                ], $data));
            }
        };

        $user = DB::table('users')->where('id', $userId)->first();
        if ($user) {
            $push($events, 'user_registered', 'ثبت‌نام کاربر', $user->created_at, ['subject' => $user->name]);
        }

        if (Schema::hasTable('user_login_logs')) {
            DB::table('user_login_logs')->where('user_id', $userId)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, $row->success ? 'login_success' : 'login_failed', $row->success ? 'ورود موفق' : 'ورود ناموفق', $row->created_at, [
                    'subject' => $row->login_method,
                    'method' => $row->login_method,
                    'ip' => $row->ip_address,
                ]);
            });
        }

        $siteIds = DB::table('sites')->where('user_id', $userId)->pluck('id')->all();
        $articleIds = $siteIds ? DB::table('articles')->whereIn('website_id', $siteIds)->pluck('id')->all() : [];

        DB::table('payments')->join('plans', 'plans.id', '=', 'payments.plan_id')->where('payments.user_id', $userId)
            ->select('payments.*', 'plans.name as plan_name')->latest('payments.created_at')->limit($limit)->get()
            ->each(function ($row) use ($events, $push) {
                $label = match ($row->status) {
                    'success' => 'پرداخت موفق',
                    'failed' => 'پرداخت ناموفق',
                    default => 'پرداخت در انتظار',
                };
                $push($events, 'payment_' . $row->status, $label, $row->created_at, [
                    'subject' => $row->plan_name,
                    'amount' => $row->amount,
                    'status' => $row->status,
                    'payment_id' => $row->id,
                ]);
            });

        DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('subscriptions.user_id', $userId)
            ->select('subscriptions.*', 'plans.name as plan_name')->latest('subscriptions.created_at')->limit($limit)->get()
            ->each(function ($row) use ($events, $push) {
                $push($events, 'subscription_' . $row->status, 'اشتراک ' . $row->status, $row->created_at, [
                    'subject' => $row->plan_name,
                    'subscription_id' => $row->id,
                    'status' => $row->status,
                ]);
            });

        if ($siteIds) {
            DB::table('sites')->whereIn('id', $siteIds)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, 'site_created', 'ساخت سایت', $row->created_at, ['subject' => $row->domain, 'site_id' => $row->id]);
            });

            DB::table('article_ideas')->whereIn('website_id', $siteIds)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, 'idea_created', 'ایجاد ایده محتوا', $row->created_at, ['subject' => $row->title, 'idea_id' => $row->id]);
            });

            DB::table('articles')->whereIn('website_id', $siteIds)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, 'article_created', 'شروع تولید مقاله', $row->created_at, ['subject' => $row->title, 'article_id' => $row->id, 'status' => $row->status]);
                $push($events, 'article_completed', 'تکمیل مقاله', $row->completed_at, ['subject' => $row->title, 'article_id' => $row->id, 'status' => $row->status]);
            });
        }

        if ($articleIds && Schema::hasTable('article_chat_messages')) {
            DB::table('article_chat_messages')->join('articles', 'articles.id', '=', 'article_chat_messages.article_id')
                ->whereIn('article_chat_messages.article_id', $articleIds)
                ->select('article_chat_messages.*', 'articles.title as article_title')
                ->latest('article_chat_messages.created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                    $push($events, 'article_chat', $row->role === 'user' ? 'پیام کاربر در ویرایش مقاله' : 'پاسخ هوش مصنوعی', $row->created_at, [
                        'subject' => $row->article_title,
                        'article_id' => $row->article_id,
                        'target_type' => $row->target_type,
                        'target_label' => $row->target_label,
                        'applied' => (bool) $row->applied,
                        'content' => $row->content,
                    ]);
                });
        }

        if (Schema::hasTable('content_research_sessions')) {
            DB::table('content_research_sessions')->where('user_id', $userId)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, 'content_research', 'اجرای تحقیق محتوا', $row->created_at, [
                    'subject' => $row->type,
                    'status' => $row->status,
                    'session_id' => $row->id,
                ]);
            });
        }

        if ($siteIds && Schema::hasTable('site_connection_logs')) {
            DB::table('site_connection_logs')->whereIn('site_id', $siteIds)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, 'site_connection', 'فعالیت اتصال وردپرس', $row->created_at, [
                    'subject' => $row->event,
                    'status' => $row->event,
                    'site_id' => $row->site_id,
                ]);
            });
        }

        if ($siteIds && Schema::hasTable('agent_request_logs')) {
            DB::table('agent_request_logs')->whereIn('site_id', $siteIds)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
                $push($events, 'agent_request', 'درخواست Agent', $row->created_at, [
                    'subject' => $row->endpoint,
                    'status_code' => $row->status_code,
                    'ip' => $row->ip,
                    'site_id' => $row->site_id,
                ]);
            });
        }

        DB::table('token_usage_records')->where('user_id', $userId)->latest('created_at')->limit($limit)->get()->each(function ($row) use ($events, $push) {
            $push($events, 'token_usage', 'مصرف اعتبار/توکن', $row->created_at, [
                'subject' => $row->service_name,
                'model' => $row->model,
                'tokens' => $row->total_tokens,
                'credit' => $row->credit,
            ]);
        });

        return $events->sortByDesc('at')->values()->take($limit);
    }

    public function globalActivities(Carbon $from, Carbon $to, int $limit = 50, ?int $userId = null, ?string $type = null): Collection
    {
        $userIds = $userId ? collect([$userId]) : User::where('is_admin', false)->pluck('id');
        $events = collect();

        foreach ($userIds as $id) {
            $events = $events->merge($this->userTimeline((int) $id, $limit));
        }

        return $events
            ->filter(function (array $event) use ($from, $to, $type) {
                $at = Carbon::parse($event['at']);
                return $at->betweenIncluded($from, $to) && (!$type || $event['type'] === $type);
            })
            ->sortByDesc('at')
            ->unique(fn (array $event) => implode('|', [
                $event['type'],
                $event['at'],
                $event['subject'] ?? '',
                $event['payment_id'] ?? '',
                $event['article_id'] ?? '',
                $event['session_id'] ?? '',
            ]))
            ->values()
            ->take($limit);
    }

    public function salesSeries(Carbon $from, Carbon $to, ?string $status = 'success', ?int $planId = null): Collection
    {
        $query = DB::table('payments')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('user_id', $this->adminUserIdsSubquery());
        if ($status) {
            $query->where('status', $status);
        }
        if ($planId) {
            $query->where('plan_id', $planId);
        }

        $rows = $query->selectRaw('DATE(created_at) as day, COUNT(*) as transactions, COALESCE(SUM(amount), 0) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        return collect(CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay()))
            ->map(function (Carbon $date) use ($rows) {
                $row = $rows->get($date->format('Y-m-d'));
                return [
                    'day' => $date->format('Y-m-d'),
                    'transactions' => (int) ($row->transactions ?? 0),
                    'revenue' => (int) ($row->revenue ?? 0),
                ];
            });
    }

    public function planSales(Carbon $from, Carbon $to, ?string $status = 'success', ?int $planId = null): Collection
    {
        $query = DB::table('payments')
            ->join('plans', 'plans.id', '=', 'payments.plan_id')
            ->whereBetween('payments.created_at', [$from, $to])
            ->whereNotIn('payments.user_id', $this->adminUserIdsSubquery());
        if ($status) {
            $query->where('payments.status', $status);
        }
        if ($planId) {
            $query->where('payments.plan_id', $planId);
        }

        return $query->select([
                'payments.plan_id',
                'plans.name as plan_name',
                DB::raw('COUNT(payments.id) as transactions'),
                DB::raw('COALESCE(SUM(payments.amount), 0) as revenue'),
            ])
            ->groupBy('payments.plan_id', 'plans.name')
            ->orderByDesc('revenue')
            ->get();
    }

    public function topBuyers(Carbon $from, Carbon $to, ?string $status = 'success', ?int $planId = null): Collection
    {
        $query = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where('users.is_admin', false)
            ->whereBetween('payments.created_at', [$from, $to]);
        if ($status) {
            $query->where('payments.status', $status);
        }
        if ($planId) {
            $query->where('payments.plan_id', $planId);
        }

        return $query->select([
                'payments.user_id',
                'users.name',
                'users.email',
                DB::raw('COUNT(payments.id) as transactions'),
                DB::raw('COALESCE(SUM(payments.amount), 0) as revenue'),
            ])
            ->groupBy('payments.user_id', 'users.name', 'users.email')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get();
    }

    public function loginStats(Carbon $from, Carbon $to): array
    {
        if (Schema::hasTable('user_login_logs')) {
            $query = DB::table('user_login_logs')
                ->whereBetween('created_at', [$from, $to])
                ->where('login_method', '!=', 'admin_impersonate')
                ->whereNotIn('user_id', $this->adminUserIdsSubquery());

            return [
                'total' => (int) (clone $query)->where('success', true)->count(),
                'unique_users' => (int) (clone $query)->where('success', true)->distinct('user_id')->count('user_id'),
                'failed' => (int) (clone $query)->where('success', false)->count(),
                'last_login_at' => DB::table('user_login_logs')
                    ->where('success', true)
                    ->where('login_method', '!=', 'admin_impersonate')
                    ->whereNotIn('user_id', $this->adminUserIdsSubquery())
                    ->latest('created_at')
                    ->value('created_at'),
            ];
        }

        $query = DB::table('sessions')->whereBetween('last_activity', [$from->timestamp, $to->timestamp]);

        return [
            'total' => (int) $query->count(),
            'unique_users' => (int) (clone $query)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            'failed' => 0,
            'last_login_at' => null,
        ];
    }

    protected function publishedArticlesCount(Carbon $from, Carbon $to): int
    {
        $query = DB::table('articles')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('website_id', $this->adminSiteIdsSubquery());
        $hasStatus = Schema::hasColumn('articles', 'publish_status');
        $hasDate = Schema::hasColumn('articles', 'published_at');

        if (!$hasStatus && !$hasDate) {
            return (int) $query->where('status', 'completed')->count();
        }

        return (int) $query->where(function ($q) use ($hasStatus, $hasDate) {
            if ($hasStatus) {
                $q->where('publish_status', 'published');
            }
            if ($hasDate) {
                $hasStatus ? $q->orWhereNotNull('published_at') : $q->whereNotNull('published_at');
            }
        })->count();
    }

    /**
     * Subquery of user IDs that belong to admin accounts, used to exclude
     * admins from all statistics via whereNotIn('user_id', ...).
     */
    protected function adminUserIdsSubquery(): \Closure
    {
        return function ($query) {
            $query->select('id')->from('users')->where('is_admin', true);
        };
    }

    /**
     * Subquery of site IDs owned by admin accounts, used to exclude
     * admin-owned sites (and everything tied to them) from statistics.
     */
    protected function adminSiteIdsSubquery(): \Closure
    {
        return function ($query) {
            $query->select('sites.id')
                ->from('sites')
                ->join('users', 'users.id', '=', 'sites.user_id')
                ->where('users.is_admin', true);
        };
    }
}
