<?php

namespace App\Services\Admin;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BehaviorAnalyticsService
{
    public function report(Carbon $from, Carbon $to, ?Carbon $comparisonFrom = null, ?Carbon $comparisonTo = null): array
    {
        $population = $this->populationUserIds($from, $to);
        $profiles = collect($population)->mapWithKeys(fn (int $id) => [$id => $this->profile($id, $to)])->values();

        $revenueByUser = $this->revenueByUser($from, $to);
        $profiles = $profiles->map(function (array $profile) use ($revenueByUser): array {
            $profile['revenue_in_period'] = (int) ($revenueByUser[$profile['user_id']] ?? 0);
            return $profile;
        });

        $funnel = $this->funnel($profiles);
        $retention = $this->retentionCohorts($profiles, $to);
        $segments = $this->segments($profiles);
        $features = $this->featureUsage($profiles);
        $dataQuality = $this->dataQuality($from, $to, $profiles);
        $revenue = $this->revenueReport($profiles, $from, $to);
        $insights = $this->insights($profiles, $funnel, $segments, $dataQuality, $revenue);

        $comparison = null;
        if ($comparisonFrom && $comparisonTo) {
            $comparison = $this->comparison($comparisonFrom, $comparisonTo);
        }

        return [
            'period' => ['from' => $from, 'to' => $to],
            'population' => [
                'users' => $profiles->count(),
                'active_users' => $profiles->filter(fn (array $p) => $p['window_activity_count'] > 0)->count(),
                'returning_users' => $profiles->where('returning', true)->count(),
                'activated_users' => $profiles->where('activated', true)->count(),
                'high_intent_users' => $profiles->where('intent_score', '>=', 60)->count(),
                'customers' => $profiles->where('purchased', true)->count(),
                'one_time_users' => $profiles->where('segment', 'one_time')->count(),
                'dormant_users' => $profiles->where('segment', 'dormant')->count(),
            ],
            'funnel' => $funnel,
            'retention' => $retention,
            'segments' => $segments,
            'features' => $features,
            'revenue' => $revenue,
            'data_quality' => $dataQuality,
            'insights' => $insights,
            'comparison' => $comparison,
            'high_intent_leads' => $profiles->filter(fn (array $p) => !$p['purchased'] && $p['intent_score'] >= 60)->sortByDesc('intent_score')->take(25)->values(),
            'churn_risk' => $profiles->filter(fn (array $p) => in_array($p['segment'], ['dormant', 'activated_no_return'], true))->sortByDesc('engagement_score')->take(25)->values(),
            'recent_users' => $profiles->sortByDesc('first_seen_at')->take(20)->values(),
            'session_summary' => $this->sessionSummary($population),
        ];
    }

    public function profile(int $userId, Carbon $asOf): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $events = $this->eventsForUser($userId, $asOf)->sortBy('at')->values();
        $loginEvents = $events->whereIn('name', [AnalyticsEventCatalog::LOGIN_SUCCESS, AnalyticsEventCatalog::OTP_VERIFIED]);
        $meaningful = $events->filter(fn (array $event) => in_array($event['name'], AnalyticsEventCatalog::meaningful(), true));
        $firstSeen = $events->first()['at'] ?? ($user?->created_at ? Carbon::parse($user->created_at) : null);
        $lastSeen = $events->last()['at'] ?? null;
        $firstLogin = $loginEvents->first()['at'] ?? null;
        $dates = $events->map(fn (array $event) => Carbon::parse($event['at'])->toDateString())->unique()->values();
        $stageTimes = $this->stageTimes($events);
        $firstValue = $stageTimes['first_value'] ?? null;
        $purchased = isset($stageTimes['purchase']);
        $returning = $dates->count() > 1;
        $retention = $this->retentionFlags($firstSeen, $dates, $asOf);
        $activated = isset($stageTimes['site_analyzed']) && isset($stageTimes['first_value']);
        $engagement = $this->engagementScore($events, $dates, $activated);
        $intent = $this->intentScore($events, $dates, $purchased);
        $segment = $this->segment($purchased, $intent['score'], $activated, $returning, $meaningful->count(), $lastSeen, $asOf);
        $funnel = $this->funnelProgress($stageTimes);

        return [
            'user_id' => $userId,
            'name' => $user->name ?? ('User #' . $userId),
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'signup_at' => $user?->created_at ? Carbon::parse($user->created_at) : null,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $lastSeen,
            'first_login_at' => $firstLogin,
            'last_login_at' => $loginEvents->last()['at'] ?? null,
            'total_sessions' => $this->sessionCount($userId),
            'active_days' => $dates->count(),
            'total_actions' => $events->count(),
            'meaningful_actions' => $meaningful->count(),
            'api_interactions' => $events->where('source', 'api')->count(),
            'page_views' => $events->where('name', AnalyticsEventCatalog::PAGE_VIEWED)->count(),
            'content_generations' => $events->whereIn('name', [AnalyticsEventCatalog::ARTICLE_STARTED, AnalyticsEventCatalog::ARTICLE_COMPLETED])->count(),
            'analyses' => $events->whereIn('name', [AnalyticsEventCatalog::SITE_ANALYZED, AnalyticsEventCatalog::KEYWORD_ANALYSIS])->count(),
            'scans' => $events->where('name', AnalyticsEventCatalog::SITE_ANALYZED)->count(),
            'articles' => $events->where('name', AnalyticsEventCatalog::ARTICLE_COMPLETED)->count(),
            'publish_actions' => $events->whereIn('name', [AnalyticsEventCatalog::PUBLISH_ATTEMPTED, AnalyticsEventCatalog::PUBLISH_SUCCEEDED, AnalyticsEventCatalog::PUBLISH_FAILED])->count(),
            'window_activity_count' => $events->count(),
            'returning' => $returning,
            'retention' => $retention,
            'session_stats' => $this->sessionStats($userId),
            'activated' => $activated,
            'activation_rule' => 'site_analyzed + first_value',
            'first_value_at' => $firstValue,
            'current_funnel_stage' => $this->currentStage($stageTimes),
            'funnel_progress' => $funnel,
            'stage_times' => $stageTimes,
            'engagement_score' => $engagement['score'],
            'engagement_reasons' => $engagement['reasons'],
            'intent_score' => $intent['score'],
            'intent_reasons' => $intent['reasons'],
            'lead' => $intent['score'] >= 60 ? 'HOT' : ($intent['score'] >= 30 ? 'WARM' : 'COLD'),
            'purchased' => $purchased,
            'segment' => $segment,
            'revenue_in_period' => 0,
            'events' => $events->reverse()->values(),
            'time_to_value' => $this->timeToValue($stageTimes),
        ];
    }

    public function executiveSummary(array $report): string
    {
        $p = $report['population'];
        $users = max(1, (int) $p['users']);
        $returnRate = round(($p['returning_users'] / $users) * 100, 1);
        $activationRate = round(($p['activated_users'] / $users) * 100, 1);
        $purchaseCount = $report['revenue']['paying_users'];
        $bottleneck = $report['insights']['bottleneck']['label'];

        return sprintf(
            'در بازه %s تا %s، %d کاربر قابل اندازه‌گیری بودند؛ %s٪ بازگشت داشتند، %s٪ به Activation رسیدند، %d کاربر Intent بالا داشتند، %d کاربر خرید کردند و درآمد %s شد. بزرگ‌ترین Bottleneck قابل مشاهده: %s.',
            Carbon::parse($report['period']['from'])->toDateString(),
            Carbon::parse($report['period']['to'])->toDateString(),
            $p['users'],
            number_format($returnRate, 1),
            number_format($activationRate, 1),
            $p['high_intent_users'],
            $purchaseCount,
            number_format($report['revenue']['revenue']),
            $bottleneck,
        );
    }

    protected function populationUserIds(Carbon $from, Carbon $to): array
    {
        $ids = User::whereBetween('created_at', [$from, $to])->pluck('id')->all();
        $sources = [
            ['table' => 'user_login_logs', 'column' => 'user_id'],
            ['table' => 'payments', 'column' => 'user_id'],
            ['table' => 'subscriptions', 'column' => 'user_id'],
            ['table' => 'token_usage_records', 'column' => 'user_id'],
            ['table' => 'content_research_sessions', 'column' => 'user_id'],
            ['table' => 'analytics_events', 'column' => 'user_id'],
        ];

        foreach ($sources as $source) {
            if (Schema::hasTable($source['table'])) {
                $timeColumn = $source['table'] === 'analytics_events' ? 'occurred_at' : 'created_at';
                $ids = array_merge($ids, DB::table($source['table'])->whereNotNull($source['column'])->whereBetween($timeColumn, [$from, $to])->pluck($source['column'])->all());
            }
        }

        if (Schema::hasTable('sites')) {
            $ids = array_merge($ids, DB::table('sites')->whereBetween('created_at', [$from, $to])->pluck('user_id')->all());
        }

        foreach ([
            ['table' => 'articles', 'join' => 'sites', 'foreign' => 'website_id', 'owner' => 'user_id'],
            ['table' => 'article_ideas', 'join' => 'sites', 'foreign' => 'website_id', 'owner' => 'user_id'],
        ] as $source) {
            if (Schema::hasTable($source['table']) && Schema::hasTable($source['join'])) {
                $ids = array_merge($ids, DB::table($source['table'])->join($source['join'], $source['join'] . '.id', '=', $source['table'] . '.' . $source['foreign'])->whereBetween($source['table'] . '.created_at', [$from, $to])->pluck($source['join'] . '.' . $source['owner'])->all());
            }
        }

        $adminIds = $this->adminUserIds();

        return collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn (int $id) => in_array($id, $adminIds, true))->values()->all();
    }

    /**
     * IDs of admin users, excluded from all analytics population/queries.
     */
    protected function adminUserIds(): array
    {
        return DB::table('users')->where('is_admin', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

protected function eventsForUser(int $userId, Carbon $asOf): Collection
{
    $events = collect();
    $push = function (string $name, $at, string $source = 'derived', ?string $feature = null, array $properties = [], ?string $entityType = null, ?int $entityId = null) use ($events): void {
        if ($at) {
            $events->push([
                'name' => $name,
                'at' => $at instanceof Carbon ? $at : Carbon::parse($at),
                'source' => $source,
                'feature' => $feature,
                'properties' => $properties,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }
    };

    $user = DB::table('users')->where('id', $userId)->first();
    if ($user) {
        $push(AnalyticsEventCatalog::SIGNUP, $user->created_at, 'derived', 'auth');
    }

    if ($user && Schema::hasTable('otp_codes')) {
        DB::table('otp_codes')->where('phone', $user->phone)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
            $push(AnalyticsEventCatalog::OTP_REQUESTED, $row->created_at, 'derived', 'auth');
            $push(AnalyticsEventCatalog::OTP_VERIFIED, $row->verified_at, 'derived', 'auth');
        });
    }

    if (Schema::hasTable('user_login_logs')) {
    DB::table('user_login_logs')
        ->where('user_id', $userId)
        ->where('login_method', '!=', 'admin_impersonate')
        ->where('created_at', '<=', $asOf)
        ->get()
        ->each(function ($row) use ($push): void {
            $push($row->success ? AnalyticsEventCatalog::LOGIN_SUCCESS : AnalyticsEventCatalog::LOGIN_FAILED, $row->created_at, 'derived', 'auth', ['method' => $row->login_method, 'ip' => $row->ip_address]);
        });
}

    // --- صفحات قیمت‌گذاری/اشتراک (page_visits) ---
    // این جدول توسط PageVisitController::start/heartbeat/end پر می‌شود.
    // ثبتش به‌عنوان PRICING_VIEWED باعث می‌شود intent score، funnel stage
    // «مشاهده قیمت‌گذاری» و data quality واقعی محاسبه شوند.
    if (Schema::hasTable('page_visits')) {
        DB::table('page_visits')
            ->where('user_id', $userId)
            ->where('page', 'subscription')
            ->where('entered_at', '<=', $asOf)
            ->get()
            ->each(function ($row) use ($push): void {
                $push(
                    AnalyticsEventCatalog::PRICING_VIEWED,
                    $row->entered_at,
                    'derived',
                    'pricing',
                    [
                        'left_at' => $row->left_at,
                        'duration_seconds' => $row->duration_seconds,
                    ],
                    'page_visit',
                    (int) $row->id
                );
            });
    }

    $siteIds = DB::table('sites')->where('user_id', $userId)->where('created_at', '<=', $asOf)->pluck('id')->all();
    if ($siteIds) {
        DB::table('sites')->whereIn('id', $siteIds)->get()->each(function ($row) use ($push): void {
            $push(AnalyticsEventCatalog::SITE_ADDED, $row->created_at, 'derived', 'site', [], 'site', (int) $row->id);
            if ($row->status === 'completed' || $row->analyzed_at) {
                $push(AnalyticsEventCatalog::SITE_ANALYZED, $row->analyzed_at ?: $row->updated_at, 'derived', 'site', ['status' => $row->status], 'site', (int) $row->id);
            }
        });

        if (Schema::hasTable('site_keyword_trends')) {
            DB::table('site_keyword_trends')->whereIn('site_id', $siteIds)->where('created_at', '<=', $asOf)->latest()->limit(200)->get()->each(fn ($row) => $push(AnalyticsEventCatalog::KEYWORD_ANALYSIS, $row->created_at, 'derived', 'keywords', [], 'site_keyword_trend', (int) $row->id));
        }
        if (Schema::hasTable('site_keyword_suggestions')) {
            DB::table('site_keyword_suggestions')->whereIn('site_id', $siteIds)->where('created_at', '<=', $asOf)->latest()->limit(200)->get()->each(fn ($row) => $push(AnalyticsEventCatalog::KEYWORD_ANALYSIS, $row->created_at, 'derived', 'keywords', [], 'site_keyword_suggestion', (int) $row->id));
        }
        if (Schema::hasTable('content_research_sessions')) {
            DB::table('content_research_sessions')->whereIn('site_id', $siteIds)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
                if ($row->status === 'done') {
                    $push(AnalyticsEventCatalog::CONTENT_RESEARCH_COMPLETED, $row->completed_at ?: $row->updated_at ?: $row->created_at, 'derived', 'content_research', ['type' => $row->type, 'status' => $row->status], 'content_research_session', (int) $row->id);
                }
            });
        }
        if (Schema::hasTable('article_ideas')) {
            DB::table('article_ideas')->whereIn('website_id', $siteIds)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
                $push(AnalyticsEventCatalog::IDEA_CREATED, $row->created_at, 'derived', 'article_idea', ['status' => $row->status], 'article_idea', (int) $row->id);
                $push(AnalyticsEventCatalog::IDEA_SCORED, $row->scored_at, 'derived', 'article_idea', ['seo_score' => $row->seo_score], 'article_idea', (int) $row->id);
            });
        }
        if (Schema::hasTable('articles')) {
            DB::table('articles')->whereIn('website_id', $siteIds)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
                $push(AnalyticsEventCatalog::ARTICLE_STARTED, $row->created_at, 'derived', 'article', ['status' => $row->status], 'article', (int) $row->id);
                if ($row->completed_at || $row->status === 'completed') {
                    $push(AnalyticsEventCatalog::ARTICLE_COMPLETED, $row->completed_at ?: $row->updated_at, 'derived', 'article', ['status' => $row->status], 'article', (int) $row->id);
                }
            });

            $articleIds = DB::table('articles')->whereIn('website_id', $siteIds)->pluck('id')->all();
            if ($articleIds && Schema::hasTable('article_chat_messages')) {
                DB::table('article_chat_messages')->whereIn('article_id', $articleIds)->where('role', 'user')->where('created_at', '<=', $asOf)->get()->each(fn ($row) => $push(AnalyticsEventCatalog::ARTICLE_EDITED, $row->created_at, 'derived', 'article_edit', ['target_type' => $row->target_type, 'applied' => (bool) $row->applied], 'article', (int) $row->article_id));
            }
        }
        if (Schema::hasTable('site_connection_logs')) {
            DB::table('site_connection_logs')->whereIn('site_id', $siteIds)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
                $name = in_array($row->event, ['connected', 'reconnected'], true) ? AnalyticsEventCatalog::WORDPRESS_CONNECTED : AnalyticsEventCatalog::FEATURE_USED;
                $push($name, $row->created_at, 'derived', 'wordpress', ['event' => $row->event, 'message' => $row->message], 'site', (int) $row->site_id);
            });
        }
        if (Schema::hasTable('article_publish_logs')) {
            $articleIds = DB::table('articles')->whereIn('website_id', $siteIds)->pluck('id')->all();
            if ($articleIds) {
                DB::table('article_publish_logs')->whereIn('article_id', $articleIds)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
                    $name = $row->status === 'success' ? AnalyticsEventCatalog::PUBLISH_SUCCEEDED : ($row->status === 'failed' ? AnalyticsEventCatalog::PUBLISH_FAILED : AnalyticsEventCatalog::PUBLISH_ATTEMPTED);
                    $push($name, $row->created_at, 'derived', 'publish', ['action' => $row->action, 'status' => $row->status], 'article', (int) $row->article_id);
                });
            }
        }
    }

    if (Schema::hasTable('payments')) {
        DB::table('payments')->where('user_id', $userId)->where('created_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
            $push(AnalyticsEventCatalog::PAYMENT_INTENT, $row->created_at, 'derived', 'billing', ['status' => $row->status, 'amount' => $row->amount], 'payment', (int) $row->id);
            if ($row->status === 'success') {
                $push(AnalyticsEventCatalog::PAYMENT_SUCCEEDED, $row->updated_at ?: $row->created_at, 'derived', 'billing', ['amount' => $row->amount, 'ref_id' => $row->ref_id], 'payment', (int) $row->id);
            } elseif ($row->status === 'failed') {
                $push(AnalyticsEventCatalog::PAYMENT_FAILED, $row->updated_at ?: $row->created_at, 'derived', 'billing', ['amount' => $row->amount], 'payment', (int) $row->id);
            }
        });
    }

    if (Schema::hasTable('subscriptions')) {
        DB::table('subscriptions')->where('user_id', $userId)->where('created_at', '<=', $asOf)->get()->each(fn ($row) => $push(AnalyticsEventCatalog::SUBSCRIPTION_CREATED, $row->created_at, 'derived', 'billing', ['status' => $row->status], 'subscription', (int) $row->id));
    }
    if (Schema::hasTable('token_usage_records')) {
        DB::table('token_usage_records')->where('user_id', $userId)->where('created_at', '<=', $asOf)->latest()->limit(500)->get()->each(fn ($row) => $push(AnalyticsEventCatalog::TOKEN_USED, $row->created_at, 'derived', 'ai_usage', ['service_name' => $row->service_name, 'model' => $row->model, 'total_tokens' => $row->total_tokens], 'token_usage', (int) $row->id));
    }
    if (Schema::hasTable('agent_request_logs') && $siteIds) {
        DB::table('agent_request_logs')->whereIn('site_id', $siteIds)->where('created_at', '<=', $asOf)->latest()->limit(500)->get()->each(fn ($row) => $push(AnalyticsEventCatalog::AGENT_REQUEST, $row->created_at, 'derived', 'agent', ['endpoint' => $row->endpoint, 'status_code' => $row->status_code], 'agent_request', (int) $row->id));
    }
    if (Schema::hasTable('analytics_events')) {
        DB::table('analytics_events')->where('user_id', $userId)->where('occurred_at', '<=', $asOf)->get()->each(function ($row) use ($push): void {
            $push($row->event_name, $row->occurred_at, $row->source, $row->feature, (array) json_decode($row->properties ?: '{}', true), $row->entity_type, $row->entity_id);
        });
    }

    return $events->filter(fn (array $event) => $event['at'] instanceof Carbon && $event['at']->lessThanOrEqualTo($asOf))
        ->sortBy('at')->unique(fn (array $event) => implode('|', [$event['name'], $event['at']->format('Y-m-d H:i:s'), $event['entity_type'], $event['entity_id']]))->values();
}

    protected function stageTimes(Collection $events): array
    {
        $times = [];
        foreach (AnalyticsEventCatalog::stageDefinitions() as $stage => $definition) {
            $event = $events->filter(fn (array $row) => in_array($row['name'], $definition['events'], true))->sortBy('at')->first();
            if ($event) {
                $times[$stage] = $event['at'];
            }
        }
        return $times;
    }

    protected function funnelProgress(array $stageTimes): array
    {
        return collect(AnalyticsEventCatalog::stageDefinitions())->map(function (array $definition, string $stage) use ($stageTimes): array {
            return [
                'stage' => $stage,
                'label' => $definition['label'],
                'status' => array_key_exists($stage, $stageTimes) ? 'completed' : ($definition['required'] ? 'not_completed' : 'not_measurable'),
                'at' => $stageTimes[$stage] ?? null,
            ];
        })->values()->all();
    }

    protected function currentStage(array $stageTimes): string
    {
        $ordered = array_keys(AnalyticsEventCatalog::stageDefinitions());
        foreach (array_reverse($ordered) as $stage) {
            if (isset($stageTimes[$stage])) {
                return $stage;
            }
        }
        return 'signup';
    }

    protected function retentionFlags($firstSeen, Collection $dates, Carbon $asOf): array
    {
        if (!$firstSeen) {
            return ['same_day' => null, 'd1' => null, 'd2' => null, 'd3' => null, 'd7' => null, 'd14' => null, 'd30' => null];
        }
        $firstDate = Carbon::parse($firstSeen)->startOfDay();
        $flags = [];
        foreach ([0 => 'same_day', 1 => 'd1', 2 => 'd2', 3 => 'd3', 7 => 'd7', 14 => 'd14', 30 => 'd30'] as $offset => $key) {
            $target = $firstDate->copy()->addDays($offset);
            $flags[$key] = $target->greaterThan($asOf->copy()->startOfDay()) ? null : $dates->contains($target->toDateString());
        }
        return $flags;
    }

    protected function engagementScore(Collection $events, Collection $dates, bool $activated): array
    {
        $score = 0;
        $reasons = [];
        $loginCount = $events->where('name', AnalyticsEventCatalog::LOGIN_SUCCESS)->count();
        $score += min(20, $loginCount * 4);
        if ($loginCount > 0) $reasons[] = '+' . min(20, $loginCount * 4) . ' امتیاز برای ورودهای موفق';
        $dayPoints = min(20, max(0, ($dates->count() - 1) * 5));
        $score += $dayPoints;
        if ($dayPoints > 0) $reasons[] = '+' . $dayPoints . ' امتیاز برای روزهای فعال/بازگشت';
        $meaningful = $events->filter(fn (array $event) => in_array($event['name'], AnalyticsEventCatalog::meaningful(), true))->count();
        $actionPoints = min(25, $meaningful * 2);
        $score += $actionPoints;
        if ($actionPoints > 0) $reasons[] = '+' . $actionPoints . ' امتیاز برای actionهای معنادار';
        if ($activated) { $score += 20; $reasons[] = '+20 امتیاز برای Activation'; }
        if ($events->where('name', AnalyticsEventCatalog::PUBLISH_SUCCEEDED)->count() > 0) { $score += 15; $reasons[] = '+15 امتیاز برای انتشار موفق'; }
        return ['score' => min(100, $score), 'reasons' => $reasons ?: ['بدون activity معنادار کافی']];
    }

    protected function intentScore(Collection $events, Collection $dates, bool $purchased): array
    {
        $score = 0;
        $reasons = [];
        $weights = [
            AnalyticsEventCatalog::PRICING_VIEWED => [20, 'مشاهده Pricing'],
            AnalyticsEventCatalog::CHECKOUT_STARTED => [25, 'شروع Checkout'],
            AnalyticsEventCatalog::PAYMENT_INTENT => [30, 'ایجاد درخواست پرداخت'],
            AnalyticsEventCatalog::ARTICLE_COMPLETED => [10, 'تولید مقاله'],
            AnalyticsEventCatalog::WORDPRESS_CONNECTED => [8, 'اتصال WordPress'],
            AnalyticsEventCatalog::PUBLISH_ATTEMPTED => [7, 'تلاش برای انتشار'],
        ];
        foreach ($weights as $event => [$points, $label]) {
            if ($events->where('name', $event)->count() > 0) { $score += $points; $reasons[] = '+' . $points . ' ' . $label; }
        }
        $returnPoints = min(10, max(0, ($dates->count() - 1) * 3));
        if ($returnPoints > 0) { $score += $returnPoints; $reasons[] = '+' . $returnPoints . ' بازگشت در چند روز'; }
        if ($purchased) { $score = 100; $reasons[] = 'خرید موفق؛ امتیاز به 100 نرمال شد'; }
        return ['score' => min(100, $score), 'reasons' => $reasons ?: ['Pricing/Checkout/Payment event قابل اندازه‌گیری ثبت نشده است']];
    }

    protected function segment(bool $purchased, int $intent, bool $activated, bool $returning, int $meaningful, $lastSeen, Carbon $asOf): string
    {
        if ($purchased) return 'customer';
        if ($lastSeen && Carbon::parse($lastSeen)->lt($asOf->copy()->subDays(30)) && ($activated || $meaningful >= 3)) return 'dormant';
        if ($intent >= 60) return 'high_intent';
        if ($activated && !$returning) return 'activated_no_return';
        if ($activated && $returning && $meaningful >= 3) return 'engaged';
        if ($activated) return 'activated';
        if ($returning) return 'returning';
        if ($meaningful === 0) return 'curious';
        return 'one_time';
    }

    protected function timeToValue(array $times): array
    {
        $pairs = [
            'signup_to_value' => ['signup', 'first_value'],
            'login_to_value' => ['first_login', 'first_value'],
            'domain_to_value' => ['domain_added', 'first_value'],
            'value_to_pricing' => ['first_value', 'pricing_viewed'],
            'pricing_to_checkout' => ['pricing_viewed', 'checkout_started'],
            'checkout_to_purchase' => ['checkout_started', 'purchase'],
        ];
        $result = [];
        foreach ($pairs as $key => [$from, $to]) {
            $result[$key] = isset($times[$from], $times[$to]) && Carbon::parse($times[$to])->greaterThanOrEqualTo(Carbon::parse($times[$from]))
                ? Carbon::parse($times[$from])->diffInSeconds(Carbon::parse($times[$to]))
                : null;
        }
        return $result;
    }

    protected function funnel(Collection $profiles): array
    {
        $result = [];
        $previousCount = null;
        $previousStage = null;
        $upstreamIncomplete = false;

        foreach (AnalyticsEventCatalog::stageDefinitions() as $stage => $definition) {
            $withStage = $profiles->filter(fn (array $profile) => isset($profile['stage_times'][$stage]));
            $available = $profiles->isNotEmpty() && ($withStage->count() > 0 || $profiles->every(function (array $profile) use ($stage): bool {
                $index = array_search($stage, array_column($profile['funnel_progress'], 'stage'), true);
                return $index !== false && $profile['funnel_progress'][$index]['status'] !== 'not_measurable';
            }));
            $count = $available ? $withStage->count() : null;
            $durations = [];
            $canCompareWithPrevious = !$upstreamIncomplete && $previousStage && $previousCount !== null && $count !== null;

            if ($canCompareWithPrevious) {
                $durations = $profiles->map(function (array $profile) use ($previousStage, $stage) {
                    if (!isset($profile['stage_times'][$previousStage], $profile['stage_times'][$stage])) return null;
                    return Carbon::parse($profile['stage_times'][$previousStage])->diffInSeconds(Carbon::parse($profile['stage_times'][$stage]));
                })->filter(fn ($v) => $v !== null)->values()->all();
            }

            $result[] = [
                'stage' => $stage,
                'label' => $definition['label'],
                'users' => $count,
                'status' => $count === null ? 'not_measurable' : ($upstreamIncomplete ? 'available_but_upstream_unavailable' : 'available'),
                'conversion_rate' => $canCompareWithPrevious && $previousCount > 0 ? round(($count / $previousCount) * 100, 1) : null,
                'drop_off_rate' => $canCompareWithPrevious && $previousCount > 0 ? round((1 - ($count / $previousCount)) * 100, 1) : null,
                'drop_off_users' => $canCompareWithPrevious ? max(0, $previousCount - $count) : null,
                'time' => $canCompareWithPrevious ? $this->durationStats($durations) : $this->durationStats([]),
            ];

            if ($count === null) {
                $upstreamIncomplete = true;
                $previousCount = null;
                $previousStage = null;
            } elseif (!$upstreamIncomplete) {
                $previousCount = $count;
                $previousStage = $stage;
            }
        }

        return $result;
    }
public function simpleReport(Carbon $from, Carbon $to): array
{
    $userIds = $this->populationUserIds($from, $to);
    $profiles = collect($userIds)->map(fn (int $id) => $this->profile($id, $to));

    $userBrief = fn (array $p): array => [
        'id' => $p['user_id'],
        'name' => $p['name'],
        'email' => $p['email'],
        'phone' => $p['phone'],
    ];

    $totalUsers = $profiles->count();
    $returning = $profiles->where('returning', true);
    $notReturning = $profiles->where('returning', false);

    $loginCounts = $profiles->map(fn (array $p) => $p + ['_login' => $p['events']->where('name', AnalyticsEventCatalog::LOGIN_SUCCESS)->count()]);
    $zeroLogin = $loginCounts->filter(fn ($p) => $p['_login'] === 0);
    $oneLogin = $loginCounts->filter(fn ($p) => $p['_login'] === 1);
    $multiLogin = $loginCounts->filter(fn ($p) => $p['_login'] > 1);

    $siteCounts = $profiles->map(fn (array $p) => $p + ['_sites' => $p['events']->where('name', AnalyticsEventCatalog::SITE_ADDED)->count()]);
    $noSite = $siteCounts->filter(fn ($p) => $p['_sites'] === 0);
    $oneSite = $siteCounts->filter(fn ($p) => $p['_sites'] === 1);
    $multiSite = $siteCounts->filter(fn ($p) => $p['_sites'] > 1);

    // ترکیب سایت + مقاله برای کارت "سایت داره ولی مقاله ننوشته"
    $combinedCounts = $profiles->map(fn (array $p) => $p + [
        '_sites' => $p['events']->where('name', AnalyticsEventCatalog::SITE_ADDED)->count(),
        '_articles' => $p['events']->where('name', AnalyticsEventCatalog::ARTICLE_COMPLETED)->count(),
    ]);
    $siteNoArticle = $combinedCounts->filter(fn ($p) => $p['_sites'] > 0 && $p['_articles'] === 0);
    $oneArticle = $combinedCounts->filter(fn ($p) => $p['_articles'] === 1);
    $twoArticles = $combinedCounts->filter(fn ($p) => $p['_articles'] === 2);
    $threeArticles = $combinedCounts->filter(fn ($p) => $p['_articles'] === 3);
    $fourPlusArticles = $combinedCounts->filter(fn ($p) => $p['_articles'] >= 4);

    $pricingViewers = $profiles->filter(fn (array $p) => $p['events']->where('name', AnalyticsEventCatalog::PRICING_VIEWED)->count() > 0);

    $toList = fn (Collection $c) => $c->map($userBrief)->values()->all();

    return [
        'total_users' => $totalUsers,
        'returning_users' => $returning->count(),
        'not_returning_users' => $notReturning->count(),
        'returning_users_list' => $toList($returning),
        'not_returning_users_list' => $toList($notReturning),
        'login' => [
            'zero' => $zeroLogin->count(),
            'one' => $oneLogin->count(),
            'multiple' => $multiLogin->count(),
        ],
        'login_lists' => [
            'zero' => $toList($zeroLogin),
            'one' => $toList($oneLogin),
            'multiple' => $toList($multiLogin),
        ],
        'sites' => [
            'none' => $noSite->count(),
            'one' => $oneSite->count(),
            'multiple' => $multiSite->count(),
        ],
        'sites_lists' => [
            'none' => $toList($noSite),
            'one' => $toList($oneSite),
            'multiple' => $toList($multiSite),
        ],
        'articles' => [
            'site_no_article' => $siteNoArticle->count(),
            'one' => $oneArticle->count(),
            'two' => $twoArticles->count(),
            'three' => $threeArticles->count(),
            'four_plus' => $fourPlusArticles->count(),
        ],
        'articles_lists' => [
            'site_no_article' => $toList($siteNoArticle),
            'one' => $toList($oneArticle),
            'two' => $toList($twoArticles),
            'three' => $toList($threeArticles),
            'four_plus' => $toList($fourPlusArticles),
        ],
        'pricing_viewers' => $pricingViewers->count(),
        'pricing_viewers_list' => $toList($pricingViewers),
    ];
}
    protected function durationStats(array $values): array
    {
        if (!$values) return ['average_seconds' => null, 'median_seconds' => null, 'min_seconds' => null, 'max_seconds' => null, 'sample' => 0];
        sort($values);
        $count = count($values);
        return [
            'average_seconds' => (int) round(array_sum($values) / $count),
            'median_seconds' => $count % 2 ? $values[(int) floor($count / 2)] : (int) round(($values[$count / 2 - 1] + $values[$count / 2]) / 2),
            'min_seconds' => min($values),
            'max_seconds' => max($values),
            'sample' => $count,
        ];
    }

    protected function retentionCohorts(Collection $profiles, Carbon $asOf): array
    {
        return $profiles->groupBy(fn (array $p) => $p['first_seen_at'] ? Carbon::parse($p['first_seen_at'])->toDateString() : 'unknown')
            ->map(function (Collection $cohort, string $date) use ($asOf): array {
                $row = ['cohort' => $date, 'users' => $cohort->count()];
                foreach (['d1' => 1, 'd3' => 3, 'd7' => 7, 'd14' => 14, 'd30' => 30] as $key => $day) {
                    $mature = $date !== 'unknown' && Carbon::parse($date)->addDays($day)->lessThanOrEqualTo($asOf->copy()->startOfDay());
                    $count = $mature ? $cohort->filter(fn (array $p) => $p['retention'][$key] === true)->count() : null;
                    $row[$key] = ['users' => $count, 'rate' => $count !== null && $cohort->count() ? round(($count / $cohort->count()) * 100, 1) : null];
                }
                return $row;
            })->sortKeysDesc()->take(60)->values()->all();
    }

    protected function segments(Collection $profiles): array
    {
        $total = max(1, $profiles->count());
        return $profiles->groupBy('segment')->map(function (Collection $users, string $segment) use ($total): array {
            return ['segment' => $segment, 'users' => $users->count(), 'percentage' => round(($users->count() / $total) * 100, 1)];
        })->sortByDesc('users')->values()->all();
    }

    protected function featureUsage(Collection $profiles): array
    {
        $features = [
            'site_scan' => [AnalyticsEventCatalog::SITE_ANALYZED, 'تحلیل سایت'],
            'keyword_analysis' => [AnalyticsEventCatalog::KEYWORD_ANALYSIS, 'تحلیل کلیدواژه'],
            'content_research' => [AnalyticsEventCatalog::CONTENT_RESEARCH_COMPLETED, 'تحقیق محتوا'],
            'article_idea' => [AnalyticsEventCatalog::IDEA_CREATED, 'ایده مقاله'],
            'article_generation' => [AnalyticsEventCatalog::ARTICLE_COMPLETED, 'تولید مقاله'],
            'article_edit' => [AnalyticsEventCatalog::ARTICLE_EDITED, 'ویرایش مقاله'],
            'wordpress' => [AnalyticsEventCatalog::WORDPRESS_CONNECTED, 'اتصال WordPress'],
            'publish' => [AnalyticsEventCatalog::PUBLISH_SUCCEEDED, 'انتشار'],
        ];
        return collect($features)->map(function (array $definition, string $key) use ($profiles): array {
            [$event, $label] = $definition;
            $users = $profiles->filter(fn (array $p) => $p['events']->where('name', $event)->count() > 0);
            $uses = $profiles->sum(fn (array $p) => $p['events']->where('name', $event)->count());
            $last = $profiles->flatMap(fn (array $p) => $p['events']->where('name', $event)->pluck('at'))->sortDesc()->first();
            return [
                'feature' => $key,
                'label' => $label,
                'users' => $users->count(),
                'percentage' => $profiles->count() ? round(($users->count() / $profiles->count()) * 100, 1) : 0,
                'uses' => $uses,
                'returning_users' => $users->where('returning', true)->count(),
                'activation_rate' => $users->count() ? round(($users->where('activated', true)->count() / $users->count()) * 100, 1) : null,
                'conversion_rate' => $users->count() ? round(($users->where('purchased', true)->count() / $users->count()) * 100, 1) : null,
                'last_used_at' => $last,
            ];
        })->sortByDesc('users')->values()->all();
    }

    protected function revenueReport(Collection $profiles, Carbon $from, Carbon $to): array
    {
        $payments = DB::table('payments')
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('user_id', $this->adminUserIds() ?: [0]);
        $success = (clone $payments)->where('status', 'success');
        $revenue = (int) $success->sum('amount');
        $payingUsers = (int) (clone $success)->distinct('user_id')->count('user_id');
        $users = max(1, $profiles->count());
        $bySegment = $profiles->groupBy('segment')->map(fn (Collection $group) => [
            'users' => $group->count(),
            'paying_users' => $group->where('purchased', true)->count(),
            'revenue' => $group->sum('revenue_in_period'),
        ]);
        return [
            'revenue' => $revenue,
            'paying_users' => $payingUsers,
            'arpu' => round($revenue / $users, 2),
            'conversion_rate' => round(($payingUsers / $users) * 100, 1),
            'average_order_value' => $payingUsers ? (int) round($revenue / max(1, (int) $success->count())) : 0,
            'by_segment' => $bySegment,
            'by_cohort' => $this->cohortRevenue($profiles),
        ];
    }

    protected function cohortRevenue(Collection $profiles): array
    {
        return $profiles->groupBy(fn (array $p) => $p['first_seen_at'] ? Carbon::parse($p['first_seen_at'])->toDateString() : 'unknown')->map(fn (Collection $group, string $cohort) => [
            'cohort' => $cohort,
            'users' => $group->count(),
            'paying_users' => $group->where('purchased', true)->count(),
            'revenue' => $group->sum('revenue_in_period'),
        ])->sortKeysDesc()->take(60)->values()->all();
    }

protected function dataQuality(Carbon $from, Carbon $to, Collection $profiles): array
{
    $checks = [];
    if (!Schema::hasTable('analytics_events')) {
        $checks[] = ['severity' => 'high', 'key' => 'event_store_missing', 'label' => 'The new event store is not installed; pricing/page/checkout events cannot be recorded.'];
    } else {
        $duplicateKeys = DB::table('analytics_events')->whereNotNull('event_key')->select('event_key')->groupBy('event_key')->havingRaw('COUNT(*) > 1')->count();
        if ($duplicateKeys > 0) {
            $checks[] = ['severity' => 'high', 'key' => 'duplicate_event_keys', 'label' => $duplicateKeys . ' duplicate event_key exists; deduplication is incomplete.'];
        }
    }
    if (!Schema::hasTable('user_login_logs')) $checks[] = ['severity' => 'high', 'key' => 'login_history_missing', 'label' => 'There is no exact login history and sessions are not a full substitute.'];
    if (!Schema::hasTable('analytics_sessions')) $checks[] = ['severity' => 'medium', 'key' => 'session_tracking_missing', 'label' => 'No valid session duration is recorded.'];

    // --- Pricing/Checkout: حالا هم analytics_events و هم page_visits چک می‌شود ---
    $pricingEvents = 0;
    if (Schema::hasTable('analytics_events')) {
        $pricingEvents += (int) DB::table('analytics_events')
            ->whereIn('event_name', [AnalyticsEventCatalog::PRICING_VIEWED, AnalyticsEventCatalog::PLAN_SELECTED])
            ->whereBetween('occurred_at', [$from, $to])
            ->count();
    }
    if (Schema::hasTable('page_visits')) {
        $pricingEvents += (int) DB::table('page_visits')
            ->where('page', 'subscription')
            ->whereBetween('entered_at', [$from, $to])
            ->count();
    }
    if (!Schema::hasTable('analytics_events') && !Schema::hasTable('page_visits')) {
        $checks[] = ['severity' => 'high', 'key' => 'pricing_checkout_missing', 'label' => 'There are no separate pricing and checkout events.'];
    } elseif ($pricingEvents === 0) {
        $checks[] = ['severity' => 'high', 'key' => 'pricing_events_empty', 'label' => 'No pricing/plan events in this range; conversion to pricing can\'t be evaluated.'];
    }

    // Checkout مستقل از page_visits نیست (چون page_visits فقط visit صفحه رو ثبت می‌کنه، نه شروع پرداخت)
    $checkoutEvents = Schema::hasTable('analytics_events')
        ? (int) DB::table('analytics_events')->where('event_name', AnalyticsEventCatalog::CHECKOUT_STARTED)->whereBetween('occurred_at', [$from, $to])->count()
        : 0;
    if ($checkoutEvents === 0) {
        $checks[] = ['severity' => 'high', 'key' => 'checkout_events_empty', 'label' => 'No checkout events in this range; pricing-to-checkout drop-off can\'t be evaluated.'];
    }

    // --- page_visits بدون left_at/duration (heartbeat خورده ولی end نرسیده) ---
    if (Schema::hasTable('page_visits')) {
        $abandoned = (int) DB::table('page_visits')
            ->whereNull('left_at')
            ->whereBetween('entered_at', [$from, $to])
            ->count();
        if ($abandoned > 0) {
            $checks[] = ['severity' => 'low', 'key' => 'page_visits_unclosed', 'label' => $abandoned . ' subscription page visits have no left_at (end was never called); they are excluded from duration stats.'];
        }
    }

    if (Schema::hasTable('user_login_logs')) {
        $duplicateLogins = DB::table('user_login_logs')
    ->whereBetween('created_at', [$from, $to])
    ->where('success', true)
    ->where('login_method', '!=', 'admin_impersonate')
    ->select('user_id', 'login_method', 'created_at')
    ->groupBy('user_id', 'login_method', 'created_at')
    ->havingRaw('COUNT(*) > 1')
    ->count();
        if ($duplicateLogins > 0) {
            $checks[] = ['severity' => 'medium', 'key' => 'duplicate_logins', 'label' => $duplicateLogins . ' duplicate successful-login groups found; review the login audit.'];
        }
    }
    if (Schema::hasTable('otp_codes')) {
        $requests = (int) DB::table('otp_codes')->whereBetween('created_at', [$from, $to])->count();
        $verified = (int) DB::table('otp_codes')->whereBetween('verified_at', [$from, $to])->count();
        if ($requests > $verified) $checks[] = ['severity' => 'medium', 'key' => 'otp_gap', 'label' => sprintf('%d OTP requests vs %d verifications; a request alone is not a login.', $requests, $verified)];
    }
    $legacySessions = Schema::hasTable('sessions') ? (int) DB::table('sessions')->whereNotNull('user_id')->count() : 0;
    if ($legacySessions > 0 && !Schema::hasTable('analytics_sessions')) $checks[] = ['severity' => 'medium', 'key' => 'legacy_sessions', 'label' => 'Current sessions only have last_activity, which is not enough for duration/active time.'];
    $checks[] = ['severity' => 'medium', 'key' => 'timezone', 'label' => 'All dates use APP_TIMEZONE; user time zones are not recorded.'];
    $checks[] = ['severity' => 'low', 'key' => 'bot_internal_test', 'label' => 'There is no definitive rule in the schema to exclude bots, admins, test users and internal traffic.'];
    $checks[] = ['severity' => 'low', 'key' => 'identity_graph', 'label' => 'Multiple accounts of one person can\'t be detected reliably; user_id is the primary identity.'];
    return ['checks' => $checks, 'score' => max(0, 100 - (count($checks) * 8)), 'insufficient_data' => collect($checks)->whereIn('severity', ['high', 'medium'])->count() > 0];
}

    protected function insights(Collection $profiles, array $funnel, array $segments, array $quality, array $revenue): array
    {
        $bottleneck = collect($funnel)->filter(fn (array $stage) => $stage['drop_off_rate'] !== null)->sortByDesc('drop_off_rate')->first();
        if (!$bottleneck) {
            $bottleneck = ['label' => 'Pricing/checkout are not instrumented separately', 'reason' => 'FACT: there are no separate pricing/checkout events in the schema/code. EVIDENCE: analytics_events is missing or empty. INTERPRETATION: the sales bottleneck cannot be determined with confidence.', 'confidence' => 'High'];
        } else {
            $bottleneck['reason'] = 'FACT: the largest drop-off is between the previous step and ' . $bottleneck['label'] . ' observed. EVIDENCE: drop-off rate=' . $bottleneck['drop_off_rate'] . '%. INTERPRETATION: this step is a bottleneck candidate, not a confirmed cause. Confidence drops if pricing/checkout events are incomplete.';
            $bottleneck['confidence'] = $quality['insufficient_data'] ? 'Low' : 'Medium';
        }

        $highIntentNoPurchase = $profiles->filter(fn (array $p) => !$p['purchased'] && $p['intent_score'] >= 60)->count();
        $activatedNoReturn = $profiles->where('segment', 'activated_no_return')->count();
        $oneTime = $profiles->where('segment', 'one_time')->count();
        $insights = [
            ['fact' => 'کاربران Intent بالا بدون خرید', 'evidence' => $highIntentNoPurchase . ' کاربر', 'interpretation' => 'این گروه مناسب re-engagement است؛ علت عدم خرید از داده فعلی قطعی نیست.', 'confidence' => 'Medium', 'action' => 'ثبت Pricing/Checkout و پیگیری evidence-based برای این گروه.'],
            ['fact' => 'کاربران Activated بدون بازگشت', 'evidence' => $activatedNoReturn . ' کاربر', 'interpretation' => 'احتمال مشکل retention یا نبود trigger بازگشت وجود دارد؛ علیت ثابت نیست.', 'confidence' => 'Low', 'action' => 'بررسی پیام/feature بعد از اولین ارزش و cohort retention.'],
            ['fact' => 'کاربران One-time', 'evidence' => $oneTime . ' کاربر', 'interpretation' => 'ریزش اولیه یا ارزش نامشخص محتمل است، اما علت نیازمند event/feedback است.', 'confidence' => 'Medium', 'action' => 'بهبود activation و ثبت first value.'],
            ['fact' => 'درآمد دوره', 'evidence' => number_format($revenue['revenue']), 'interpretation' => $revenue['revenue'] > 0 ? 'پرداخت موفق واقعی ثبت شده است.' : 'در این بازه پرداخت موفق ثبت نشده است.', 'confidence' => 'High', 'action' => $revenue['revenue'] > 0 ? 'تحلیل cohort و segment خریداران.' : 'اول instrumentation قیمت/checkout را تکمیل کنید.'],
        ];
        return [
            'bottleneck' => $bottleneck,
            'items' => array_slice($insights, 0, 5),
            'recommended_actions' => [
                ['priority' => 'High', 'impact' => 'High', 'reason' => 'No pricing/checkout events in the data.', 'action' => 'Track the pricing_viewed, plan_selected and checkout_started events.'],
                ['priority' => 'High', 'impact' => 'High', 'reason' => 'No valid session duration or page views.', 'action' => 'Enable analytics sessions with start/heartbeat/end and page views.'],
                ['priority' => 'Medium', 'impact' => 'High', 'reason' => 'High-intent users without a purchase can be identified.', 'action' => 'Build re-engagement lists for high-intent no-purchase and checkout-no-payment users.'],
                ['priority' => 'Medium', 'impact' => 'Medium', 'reason' => 'Retention is based on measurable activity.', 'action' => 'Track cohort reports and return triggers after first value.'],
                ['priority' => 'Low', 'impact' => 'Medium', 'reason' => 'No bot/test/internal traffic rule exists.', 'action' => 'Define exclusion rules and filter internal data before making sales decisions.'],
            ],
        ];
    }

    protected function comparison(Carbon $from, Carbon $to): array
    {
        $profiles = collect($this->populationUserIds($from, $to))->map(fn (int $id) => $this->profile($id, $to));
        $revenue = $this->revenueReport($profiles, $from, $to);
        return ['users' => $profiles->count(), 'customers' => $revenue['paying_users'], 'revenue' => $revenue['revenue'], 'conversion_rate' => $revenue['conversion_rate']];
    }

    protected function revenueByUser(Carbon $from, Carbon $to): array
    {
        return DB::table('payments')
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'success')
            ->whereNotIn('user_id', $this->adminUserIds() ?: [0])
            ->select('user_id', DB::raw('SUM(amount) as revenue'))
            ->groupBy('user_id')
            ->pluck('revenue', 'user_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    protected function sessionCount(int $userId): ?int
    {
        if (!Schema::hasTable('analytics_sessions')) return null;
        return (int) DB::table('analytics_sessions')->where('user_id', $userId)->count();
    }

    protected function sessionStats(int $userId): array
    {
        if (!Schema::hasTable('analytics_sessions')) {
            return ['sessions' => null, 'valid_sessions' => null, 'short_without_action' => null, 'total_seconds' => null, 'average_seconds' => null];
        }

        $sessions = DB::table('analytics_sessions')->where('user_id', $userId)->get();
        $durations = $sessions->filter(fn ($session) => $session->started_at && $session->ended_at)->map(fn ($session) => max(0, Carbon::parse($session->started_at)->diffInSeconds(Carbon::parse($session->ended_at))))->values()->all();
        $shortWithoutAction = $sessions->filter(fn ($session) => $session->action_count === 0 && (!$session->ended_at || Carbon::parse($session->started_at)->diffInSeconds(Carbon::parse($session->ended_at)) < 60))->count();

        return [
            'sessions' => $sessions->count(),
            'valid_sessions' => count($durations),
            'short_without_action' => $shortWithoutAction,
            'total_seconds' => $durations ? array_sum($durations) : null,
            'average_seconds' => $durations ? (int) round(array_sum($durations) / count($durations)) : null,
        ];
    }

    protected function sessionSummary(array $userIds): array
    {
        if (!Schema::hasTable('analytics_sessions')) {
            return ['sessions' => null, 'valid_sessions' => null, 'total_seconds' => null, 'average_seconds' => null, 'short_without_action' => null];
        }

        $sessions = DB::table('analytics_sessions')->whereIn('user_id', $userIds)->get();
        $durations = $sessions->filter(fn ($session) => $session->started_at && $session->ended_at)->map(fn ($session) => max(0, Carbon::parse($session->started_at)->diffInSeconds(Carbon::parse($session->ended_at))))->values()->all();

        return [
            'sessions' => $sessions->count(),
            'valid_sessions' => count($durations),
            'total_seconds' => $durations ? array_sum($durations) : null,
            'average_seconds' => $durations ? (int) round(array_sum($durations) / count($durations)) : null,
            'short_without_action' => $sessions->filter(fn ($session) => $session->action_count === 0 && (!$session->ended_at || Carbon::parse($session->started_at)->diffInSeconds(Carbon::parse($session->ended_at)) < 60))->count(),
        ];
    }
}
