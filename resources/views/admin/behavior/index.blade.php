@extends('layouts.admin')

@section('content')
@php
    $p = $report['population'];
    $ratio = fn($value, $total) => $total > 0 ? number_format(($value / $total) * 100, 1) . '٪' : '—';
    $formatSeconds = function ($seconds) {
        if ($seconds === null) return 'Insufficient Data';
        $seconds = (int) $seconds;
        if ($seconds < 60) return $seconds . ' ثانیه';
        if ($seconds < 3600) return floor($seconds / 60) . ' دقیقه';
        return floor($seconds / 3600) . ' ساعت و ' . floor(($seconds % 3600) / 60) . ' دقیقه';
    };
    $stageLabel = collect($report['funnel'])->keyBy('stage');

    $badge = function ($text, $color = 'gray') {
        $map = [
            'gray' => 'bg-gray-100 text-gray-600',
            'success' => 'bg-green-100 text-green-700',
            'warning' => 'bg-amber-100 text-amber-700',
            'danger' => 'bg-red-100 text-red-700',
        ];
        return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium whitespace-nowrap ' . ($map[$color] ?? $map['gray']) . '">' . e($text) . '</span>';
    };
@endphp

<div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-gray-800">Behavior &amp; Conversion Analytics</h1>
        <div class="text-xs sm:text-sm text-gray-500 mt-1">تحلیل واقعی کاربران، مسیر محصول، retention و فروش از {{ $from }} تا {{ $to }}</div>
    </div>
    <form class="flex flex-wrap items-end gap-3" method="GET">
        <div class="flex flex-col gap-1 w-full xs:w-auto sm:w-auto">
            <label class="text-xs font-medium text-gray-500">بازه سریع</label>
            <select name="preset"
                onchange="this.form.querySelector('[name=from]').value=''; this.form.querySelector('[name=to]').value='';"
                class="rounded-lg border-gray-300 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 w-full sm:min-w-[140px]">
                <option value="">۳۰ روز اخیر</option>
                <option value="today" @selected($preset === 'today')>امروز</option>
                <option value="yesterday" @selected($preset === 'yesterday')>دیروز</option>
                <option value="7d" @selected($preset === '7d')>۷ روز اخیر</option>
                <option value="90d" @selected($preset === '90d')>۹۰ روز اخیر</option>
            </select>
        </div>
        <div class="flex flex-col gap-1 w-[calc(50%-6px)] sm:w-auto">
            <label class="text-xs font-medium text-gray-500">از تاریخ</label>
            <input type="date" name="from" value="{{ $from }}" class="w-full rounded-lg border-gray-300 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div class="flex flex-col gap-1 w-[calc(50%-6px)] sm:w-auto">
            <label class="text-xs font-medium text-gray-500">تا تاریخ</label>
            <input type="date" name="to" value="{{ $to }}" class="w-full rounded-lg border-gray-300 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-600">
            <input type="checkbox" name="compare" value="1" @checked($comparisonFrom) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            مقایسه دوره قبل
        </label>
        <button type="submit" class="w-full sm:w-auto px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium transition">تحلیل</button>
    </form>
</div>

<section class="bg-white rounded-xl shadow-sm border-r-4 border-blue-600 border-t border-b border-l border-gray-100 p-4 sm:p-5 mb-6">
    <h2 class="text-base font-semibold text-gray-800 mb-2">Executive Summary</h2>
    <p class="text-sm sm:text-[15px] leading-7 sm:leading-8 text-gray-700">{{ $report['executive_summary'] }}</p>
    <p class="text-xs text-gray-400 mt-2">Population این گزارش کاربران شناخته‌شده‌ای است که در بازه ثبت‌نام یا activity قابل اندازه‌گیری داشته‌اند؛ بازدیدکننده ناشناس و page view ثبت‌نشده در این عدد نیست.</p>
</section>

@php
    $userLists = [
        'returning' => $simple['returning_users_list'],
        'not_returning' => $simple['not_returning_users_list'],
        'login_zero' => $simple['login_lists']['zero'],
        'login_one' => $simple['login_lists']['one'],
        'login_multiple' => $simple['login_lists']['multiple'],
        'sites_none' => $simple['sites_lists']['none'],
        'sites_one' => $simple['sites_lists']['one'],
        'sites_multiple' => $simple['sites_lists']['multiple'],
        'articles_site_no_article' => $simple['articles_lists']['site_no_article'],
        'articles_one' => $simple['articles_lists']['one'],
        'articles_two' => $simple['articles_lists']['two'],
        'articles_three' => $simple['articles_lists']['three'],
        'articles_four_plus' => $simple['articles_lists']['four_plus'],
        'pricing_viewers' => $simple['pricing_viewers_list'],
    ];
@endphp

<section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5 mb-6">
    <h2 class="text-base font-semibold text-gray-800 mb-4">آمار ساده کاربران</h2>

    <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 mb-6">
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition" data-user-modal="returning" data-title="کاربران بازگشت داشته">
            <div class="text-xs text-gray-500">کل کاربران</div>
            <div class="text-xl sm:text-2xl font-bold text-gray-800 mt-1">{{ number_format($simple['total_users']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition" data-user-modal="returning" data-title="کاربران بازگشت داشته">
            <div class="text-xs text-gray-500">کاربران بازگشت داشته</div>
            <div class="text-xl sm:text-2xl font-bold text-green-600 mt-1">{{ number_format($simple['returning_users']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition col-span-2 lg:col-span-1" data-user-modal="not_returning" data-title="کاربران بدون بازگشت">
            <div class="text-xs text-gray-500">کاربران بدون بازگشت</div>
            <div class="text-xl sm:text-2xl font-bold text-red-600 mt-1">{{ number_format($simple['not_returning_users']) }}</div>
        </button>
    </div>

    <h3 class="text-sm font-semibold text-gray-700 mb-2">تعداد Login</h3>
    <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-6">
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-2.5 sm:p-4 transition" data-user-modal="login_zero" data-title="کاربران بدون هیچ لاگین موفق">
            <div class="text-[11px] sm:text-xs text-gray-500">صفر بار</div>
            <div class="text-base sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['login']['zero']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-2.5 sm:p-4 transition" data-user-modal="login_one" data-title="کاربران با یک بار Login">
            <div class="text-[11px] sm:text-xs text-gray-500">یک بار</div>
            <div class="text-base sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['login']['one']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-2.5 sm:p-4 transition" data-user-modal="login_multiple" data-title="کاربران با بیشتر از یک بار Login">
            <div class="text-[11px] sm:text-xs text-gray-500">بیشتر از یک بار</div>
            <div class="text-base sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['login']['multiple']) }}</div>
        </button>
    </div>

    <h3 class="text-sm font-semibold text-gray-700 mb-2">تعداد سایت ثبت‌شده</h3>
    <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-6">
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-2.5 sm:p-4 transition" data-user-modal="sites_none" data-title="کاربران بدون سایت ثبت‌شده">
            <div class="text-[11px] sm:text-xs text-gray-500">هیچ سایتی ثبت نکرده</div>
            <div class="text-base sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['sites']['none']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-2.5 sm:p-4 transition" data-user-modal="sites_one" data-title="کاربران با یک سایت">
            <div class="text-[11px] sm:text-xs text-gray-500">یک سایت</div>
            <div class="text-base sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['sites']['one']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-2.5 sm:p-4 transition" data-user-modal="sites_multiple" data-title="کاربران با بیشتر از یک سایت">
            <div class="text-[11px] sm:text-xs text-gray-500">بیشتر از یک سایت</div>
            <div class="text-base sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['sites']['multiple']) }}</div>
        </button>
    </div>

    <h3 class="text-sm font-semibold text-gray-700 mb-2">تعداد مقاله تولیدشده</h3>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4 mb-6">
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition" data-user-modal="articles_site_no_article" data-title="کاربرانی که سایت ثبت کرده‌اند ولی مقاله ننوشته‌اند">
            <div class="text-xs text-gray-500">سایت دارن، مقاله ننوشتن</div>
            <div class="text-lg sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['articles']['site_no_article']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition" data-user-modal="articles_one" data-title="کاربران با یک مقاله">
            <div class="text-xs text-gray-500">یک مقاله</div>
            <div class="text-lg sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['articles']['one']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition" data-user-modal="articles_two" data-title="کاربران با دو مقاله">
            <div class="text-xs text-gray-500">دو مقاله</div>
            <div class="text-lg sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['articles']['two']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition" data-user-modal="articles_three" data-title="کاربران با سه مقاله">
            <div class="text-xs text-gray-500">سه مقاله</div>
            <div class="text-lg sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['articles']['three']) }}</div>
        </button>
        <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-3 sm:p-4 transition col-span-2 sm:col-span-1" data-user-modal="articles_four_plus" data-title="کاربران با چهار یا بیشتر مقاله">
            <div class="text-xs text-gray-500">چهار یا بیشتر</div>
            <div class="text-lg sm:text-xl font-bold text-gray-800 mt-1">{{ number_format($simple['articles']['four_plus']) }}</div>
        </button>
    </div>

    <h3 class="text-sm font-semibold text-gray-700 mb-2">مشاهده صفحه Subscription/Pricing</h3>
    <button type="button" class="text-right rounded-xl border border-gray-100 bg-gray-50 hover:bg-gray-100 p-4 w-full sm:max-w-xs transition" data-user-modal="pricing_viewers" data-title="کاربرانی که صفحه Pricing را دیده‌اند">
        <div class="text-xs text-gray-500">تعداد کاربری که این صفحه رو دیده</div>
        <div class="text-2xl font-bold text-indigo-600 mt-1">{{ number_format($simple['pricing_viewers']) }}</div>
    </button>
</section>

{{-- Modal --}}
<div id="user-list-modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/40" data-modal-close></div>
    <div class="relative z-10 flex items-center justify-center min-h-screen p-3 sm:p-4">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-lg max-h-[85vh] sm:max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between p-3 sm:p-4 border-b border-gray-100">
                <h3 id="user-list-modal-title" class="text-sm sm:text-base font-semibold text-gray-800 pl-2"></h3>
                <button type="button" class="text-gray-400 hover:text-gray-600 shrink-0 text-lg leading-none" data-modal-close>✕</button>
            </div>
            <div id="user-list-modal-body" class="overflow-y-auto p-3 sm:p-4 space-y-2"></div>
        </div>
    </div>
</div>

<script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}';
    const userLists = @json($userLists);

    document.querySelectorAll('[data-user-modal]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.getAttribute('data-user-modal');
            const title = btn.getAttribute('data-title');
            const users = userLists[key] || [];

            document.getElementById('user-list-modal-title').textContent = title + ' (' + users.length + ' نفر)';

            const body = document.getElementById('user-list-modal-body');
            body.innerHTML = '';

            if (users.length === 0) {
                body.innerHTML = '<p class="text-sm text-gray-400 text-center py-6">کاربری یافت نشد.</p>';
            } else {
               users.forEach((u) => {
    const row = document.createElement('div');
    row.className = 'flex flex-col xs:flex-row sm:flex-row items-start sm:items-center justify-between gap-2 border border-gray-100 rounded-lg p-3';
row.innerHTML = `
    <div class="min-w-0">
        <div class="text-sm font-medium text-gray-800 truncate">${u.name ?? ('کاربر #' + u.id)}</div>
        <div class="text-xs text-gray-400 truncate">${u.email ?? '—'}</div>
        <div class="text-xs text-gray-400" dir="ltr">${u.phone ?? '—'}</div>
    </div>
    <form action="/admin/users/${u.id}/impersonate" method="POST" target="_blank" class="shrink-0 w-full sm:w-auto">
        <input type="hidden" name="_token" value="${csrfToken}">
        <button type="submit" class="w-full sm:w-auto px-3 py-1.5 text-xs rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white whitespace-nowrap">
            ورود به پنل
        </button>
    </form>
`;
    body.appendChild(row);
});
            }

            document.getElementById('user-list-modal').classList.remove('hidden');
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach((el) => {
        el.addEventListener('click', () => {
            document.getElementById('user-list-modal').classList.add('hidden');
        });
    });
</script>


@if($report['comparison'])
<section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5 mb-6">
    <h2 class="text-base font-semibold text-gray-800 mb-4">مقایسه با دوره قبل</h2>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        @foreach([
            ['label' => 'Users', 'current' => $p['users'], 'previous' => $report['comparison']['users']],
            ['label' => 'Customers', 'current' => $report['revenue']['paying_users'], 'previous' => $report['comparison']['customers']],
            ['label' => 'Revenue', 'current' => $report['revenue']['revenue'], 'previous' => $report['comparison']['revenue']],
            ['label' => 'Conversion', 'current' => $report['revenue']['conversion_rate'], 'previous' => $report['comparison']['conversion_rate']]
        ] as $comparison)
            <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 sm:p-4">
                <div class="text-xs text-gray-500">{{ $comparison['label'] }}</div>
                <div class="text-base sm:text-lg font-bold text-gray-800 mt-1">{{ number_format($comparison['current'], 1) }}</div>
                <div class="text-xs text-gray-400 mt-1">قبلی: {{ number_format($comparison['previous'], 1) }}</div>
            </div>
        @endforeach
    </div>
</section>
@endif

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">Session Analysis</h2>
        @if($report['session_summary']['sessions'] === null)
            <p class="text-sm text-gray-400">Insufficient Data: session start/end جدید هنوز نصب یا instrument نشده است.</p>
        @else
            <div class="space-y-2 text-sm text-gray-700">
                <p>کل sessionها: <strong>{{ number_format($report['session_summary']['sessions']) }}</strong></p>
                <p>sessionهای دارای start/end معتبر: <strong>{{ number_format($report['session_summary']['valid_sessions']) }}</strong></p>
                <p>میانگین مدت معتبر: <strong>{{ $formatSeconds($report['session_summary']['average_seconds']) }}</strong></p>
                <p>مجموع active time معتبر: <strong>{{ $formatSeconds($report['session_summary']['total_seconds']) }}</strong></p>
                <p>کوتاه و بدون action: <strong>{{ number_format($report['session_summary']['short_without_action']) }}</strong></p>
            </div>
        @endif
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">Conversion Funnel</h2>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm text-right min-w-[560px] sm:min-w-0">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 text-xs">
                        <th class="py-2 px-3 font-medium">مرحله</th>
                        <th class="py-2 px-3 font-medium">Users</th>
                        <th class="py-2 px-3 font-medium">نسبت به قبل</th>
                        <th class="py-2 px-3 font-medium">Drop-off</th>
                        <th class="py-2 px-3 font-medium">زمان رسیدن</th>
                        <th class="py-2 px-3 font-medium">وضعیت</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @foreach($report['funnel'] as $stage)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 px-3">
                            <strong class="text-gray-700">{{ $stage['label'] }}</strong><br>
                            <small class="text-gray-400">{{ $stage['stage'] }}</small>
                        </td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $stage['users'] === null ? 'Insufficient Data' : number_format($stage['users']) }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $stage['conversion_rate'] === null ? '—' : number_format($stage['conversion_rate'], 1) . '٪' }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $stage['drop_off_rate'] === null ? '—' : number_format($stage['drop_off_rate'], 1) . '٪' }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $stage['time']['sample'] ? $formatSeconds($stage['time']['median_seconds']) . ' (median)' : 'Insufficient Data' }}</td>
                        <td class="py-2.5 px-3">{!! $badge($stage['status'] === 'available' ? 'قابل تحلیل' : 'داده کافی نیست', $stage['status'] === 'available' ? 'success' : 'warning') !!}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">User Segments</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 text-xs">
                        <th class="py-2 px-3 font-medium">Segment</th>
                        <th class="py-2 px-3 font-medium">Users</th>
                        <th class="py-2 px-3 font-medium">درصد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @forelse($report['segments'] as $segment)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 px-3 text-gray-700">{{ $segment['segment'] }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ number_format($segment['users']) }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ number_format($segment['percentage'], 1) }}٪</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-6 text-center text-gray-400">داده‌ای نیست.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-3">Segmentها rule-based و قابل توضیح هستند؛ علت رفتاری قطعی از آن‌ها استنتاج نمی‌شود.</p>
    </section>
</div>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">Retention Cohorts</h2>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm text-right min-w-[520px] sm:min-w-0">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 text-xs">
                        <th class="py-2 px-3 font-medium">Cohort</th>
                        <th class="py-2 px-3 font-medium">Users</th>
                        <th class="py-2 px-3 font-medium">D1</th>
                        <th class="py-2 px-3 font-medium">D3</th>
                        <th class="py-2 px-3 font-medium">D7</th>
                        <th class="py-2 px-3 font-medium">D14</th>
                        <th class="py-2 px-3 font-medium">D30</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @forelse($report['retention'] as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 px-3 text-gray-700">{{ $row['cohort'] }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ number_format($row['users']) }}</td>
                        @foreach(['d1','d3','d7','d14','d30'] as $key)
                            <td class="py-2.5 px-3 text-gray-700">{{ $row[$key]['rate'] === null ? '—' : number_format($row[$key]['rate'], 1) . '٪' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-6 text-center text-gray-400">داده cohort کافی نیست.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">Feature Usage</h2>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm text-right min-w-[560px] sm:min-w-0">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 text-xs">
                        <th class="py-2 px-3 font-medium">Feature</th>
                        <th class="py-2 px-3 font-medium">Users</th>
                        <th class="py-2 px-3 font-medium">Uses</th>
                        <th class="py-2 px-3 font-medium">Returning</th>
                        <th class="py-2 px-3 font-medium">Activation</th>
                        <th class="py-2 px-3 font-medium">Conversion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @forelse($report['features'] as $feature)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 px-3 text-gray-700">{{ $feature['label'] }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ number_format($feature['users']) }} ({{ number_format($feature['percentage'], 1) }}٪)</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ number_format($feature['uses']) }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ number_format($feature['returning_users']) }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $feature['activation_rate'] === null ? '—' : number_format($feature['activation_rate'], 1) . '٪' }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $feature['conversion_rate'] === null ? '—' : number_format($feature['conversion_rate'], 1) . '٪' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-gray-400">داده feature نیست.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-3">این اعداد correlation توصیفی‌اند، نه causal effect.</p>
    </section>
</div>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6 mb-6">
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">High Intent Leads</h2>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm text-right min-w-[560px] sm:min-w-0">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 text-xs">
                        <th class="py-2 px-3 font-medium">کاربر</th>
                        <th class="py-2 px-3 font-medium">Intent</th>
                        <th class="py-2 px-3 font-medium">Lead</th>
                        <th class="py-2 px-3 font-medium">Stage</th>
                        <th class="py-2 px-3 font-medium">دلایل</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @forelse($report['high_intent_leads'] as $lead)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 px-3">
                            <a href="{{ route('admin.users.show', $lead['user_id']) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $lead['name'] }}</a><br>
                            <small class="text-gray-400">{{ $lead['email'] }}</small>
                        </td>
                        <td class="py-2.5 px-3 text-gray-700"><strong>{{ $lead['intent_score'] }}/100</strong></td>
                        <td class="py-2.5 px-3">{!! $badge($lead['lead'], 'warning') !!}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $lead['current_funnel_stage'] }}</td>
                        <td class="py-2.5 px-3 text-gray-500 text-xs">{{ implode('، ', array_slice($lead['intent_reasons'], 0, 3)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">High intent بدون خرید پیدا نشد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 sm:p-5">
        <h2 class="text-base font-semibold text-gray-800 mb-3">Users at Risk of Churn</h2>
        <div class="overflow-x-auto -mx-4 sm:mx-0 px-4 sm:px-0">
            <table class="w-full text-sm text-right min-w-[520px] sm:min-w-0">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500 text-xs">
                        <th class="py-2 px-3 font-medium">کاربر</th>
                        <th class="py-2 px-3 font-medium">Segment</th>
                        <th class="py-2 px-3 font-medium">Engagement</th>
                        <th class="py-2 px-3 font-medium">آخرین فعالیت</th>
                        <th class="py-2 px-3 font-medium">پیشنهاد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                @forelse($report['churn_risk'] as $lead)
                    <tr class="hover:bg-gray-50">
                        <td class="py-2.5 px-3">
                            <a href="{{ route('admin.users.show', $lead['user_id']) }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $lead['name'] }}</a>
                        </td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $lead['segment'] }}</td>
                        <td class="py-2.5 px-3 text-gray-700">{{ $lead['engagement_score'] }}/100</td>
                        <td class="py-2.5 px-3 text-gray-500 text-xs">{{ $lead['last_seen_at'] ? \Carbon\Carbon::parse($lead['last_seen_at'])->format('Y-m-d H:i') : '—' }}</td>
                        <td class="py-2.5 px-3 text-gray-700">بازگشت به First Value / feature اصلی</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6 text-center text-gray-400">کاربر dormant یا activated-no-return پیدا نشد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
