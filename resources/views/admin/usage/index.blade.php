@extends('layouts.admin')

@section('content')

@php
    $hasData = $totals['requests'] > 0;
    $fmtCredit = fn ($v, $d = 4) => number_format((float) $v, $d);
    $fmtUsd = fn ($v, $d = 4) => '$' . number_format((float) $v, $d);
@endphp

{{-- ============================== عنوان ============================== --}}
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 mb-1">آنالیز مصرف هوش مصنوعی</h1>
        <p class="text-slate-500 text-sm">
            کریدیت سوزانده‌شده و هزینه‌ی واقعی دلاری، به تفکیک مدل، سرویس و کاربر
            @if($filters['category'] === 'image')
                — <strong class="text-slate-700">فقط مصرف تصویر</strong>
            @elseif($filters['category'] === 'text')
                — <strong class="text-slate-700">همه‌ی مصرف‌ها به‌جز تصویر</strong>
            @endif
            @if($filters['plan_type'] === 'free')
                — <strong class="text-slate-700">فقط کاربران رایگان</strong>
            @elseif($filters['plan_type'] === 'paid')
                — <strong class="text-slate-700">فقط کاربران پولی</strong>
            @endif
        </p>
    </div>
    <div class="flex flex-col items-start sm:items-end gap-2">
        <div class="text-sm text-slate-500 sm:text-left">
            بازه: <strong class="text-slate-800">{{ $periodLabel }}</strong>
            @if($rangeLabel)
                <div class="text-xs text-slate-400 mt-0.5" dir="rtl">{{ $rangeLabel }}</div>
            @endif
        </div>
        <div class="flex gap-2">
            <button type="button" onclick="copyUsageReportJson(this)"
                    class="text-xs border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-50 transition-colors">
                کپی JSON برای AI
            </button>
            <button type="button" onclick="downloadUsageReportJson()"
                    class="text-xs border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-50 transition-colors">
                دانلود JSON
            </button>
        </div>
    </div>
</div>

{{-- ============================== دسته‌بندی: همه / تصویر / متن ============================== --}}
<div class="inline-flex bg-slate-100 rounded-xl p-1 mb-4 text-sm" role="tablist">
    @foreach(['all' => 'همه‌ی مصرف‌ها', 'image' => 'فقط تصویر', 'text' => 'بدون تصویر (متن و سایر)'] as $key => $label)
        <a href="{{ request()->fullUrlWithQuery(['category' => $key, 'service' => null, 'model' => null, 'page' => null]) }}"
           role="tab" aria-selected="{{ $filters['category'] === $key ? 'true' : 'false' }}"
           class="px-4 py-2 rounded-lg transition-colors {{ $filters['category'] === $key ? 'bg-white text-slate-900 font-semibold shadow-sm' : 'text-slate-500 hover:text-slate-800' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

{{-- ============================== فیلترها ============================== --}}
<form method="GET" id="usage-filter-form"
      class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-5 mb-6">

    <input type="hidden" name="user_id" value="{{ $filters['user_id'] }}">
    <input type="hidden" name="category" value="{{ $filters['category'] }}">

    <div class="flex flex-wrap gap-3 items-end">
        <div class="flex flex-col min-w-[170px]">
            <label class="text-xs text-slate-500 mb-1.5">بازه زمانی</label>
            <select name="period" id="period-select"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @foreach([
                    'all_time' => 'کل بازه (از ابتدا)',
                    'today' => 'امروز',
                    'yesterday' => 'دیروز',
                    'this_week' => 'هفته جاری',
                    'last_week' => 'هفته گذشته',
                    'this_month' => 'ماه جاری (شمسی)',
                    'last_month' => 'ماه گذشته (شمسی)',
                    'custom' => 'بازه دلخواه',
                ] as $val => $label)
                    <option value="{{ $val }}" @selected($filters['period'] === $val)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div id="custom-range-fields" class="flex flex-col min-w-[140px]" style="{{ $filters['period'] === 'custom' ? '' : 'display:none' }}">
            <label class="text-xs text-slate-500 mb-1.5">از تاریخ (شمسی)</label>
            <input type="text" name="from_date" value="{{ $filters['from_date'] }}" placeholder="1405-05-01" dir="ltr"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>
        <div id="custom-range-fields-to" class="flex flex-col min-w-[140px]" style="{{ $filters['period'] === 'custom' ? '' : 'display:none' }}">
            <label class="text-xs text-slate-500 mb-1.5">تا تاریخ (شمسی)</label>
            <input type="text" name="to_date" value="{{ $filters['to_date'] }}" placeholder="1405-05-31" dir="ltr"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[140px]">
            <label class="text-xs text-slate-500 mb-1.5">تجمیع نمودار روند</label>
            <select name="group"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="day" @selected($filters['group'] === 'day')>روزانه</option>
                <option value="week" @selected($filters['group'] === 'week')>هفتگی</option>
                <option value="month" @selected($filters['group'] === 'month')>ماهانه (شمسی)</option>
            </select>
        </div>

        <div class="flex flex-col min-w-[190px]">
            <label class="text-xs text-slate-500 mb-1.5">سرویس (کار انجام‌شده)</label>
            <select name="service"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">همه‌ی سرویس‌ها</option>
                @foreach($serviceOptions as $key => $label)
                    <option value="{{ $key }}" @selected($filters['service'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col min-w-[170px]">
            <label class="text-xs text-slate-500 mb-1.5">مدل</label>
            <select name="model" dir="ltr"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">همه‌ی مدل‌ها</option>
                @foreach($modelOptions as $m)
                    <option value="{{ $m }}" @selected($filters['model'] === $m)>{{ $m }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col min-w-[170px]">
            <label class="text-xs text-slate-500 mb-1.5">نوع کاربر</label>
            <select name="plan_type"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="all" @selected($filters['plan_type'] === 'all')>همه‌ی کاربران</option>
                <option value="free" @selected($filters['plan_type'] === 'free')>فقط رایگان (هرگز پولی نگرفته)</option>
                <option value="paid" @selected($filters['plan_type'] === 'paid')>فقط پولی (حداقل یک‌بار خریده)</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 transition-colors text-white font-medium rounded-lg px-5 py-2 text-sm shadow-sm">
                اعمال فیلتر
            </button>
            <a href="{{ route('admin.usage.index') }}"
               class="border border-slate-300 text-slate-600 hover:bg-slate-50 transition-colors rounded-lg px-4 py-2 text-sm">
                پاک‌سازی
            </a>
        </div>
    </div>

    @if($selectedUser)
        <div class="mt-4 flex items-center gap-2 text-sm bg-brand-50 border border-brand-100 text-brand-800 rounded-lg px-3 py-2">
            <span>آنالیز فقط برای کاربر:</span>
            <strong>{{ $selectedUser->name ?: ($selectedUser->phone ?: $selectedUser->email) }}</strong>
            <a href="{{ request()->fullUrlWithQuery(['user_id' => null, 'page' => null]) }}"
               class="mr-auto text-xs underline hover:no-underline">نمایش همه‌ی کاربران</a>
        </div>
    @endif

    {{-- فیلترهای مخصوص جدول کاربران --}}
    <details class="mt-4 pt-4 border-t border-slate-100" @if($filters['search'] || $filters['credit_value'] !== null && $filters['credit_value'] !== '' || $filters['users_scope'] === 'all') open @endif>
        <summary class="text-sm text-slate-600 cursor-pointer select-none">فیلترهای جدول کاربران</summary>
        <div class="flex flex-wrap gap-3 items-end mt-3">
            <div class="flex flex-col min-w-[210px] flex-1">
                <label class="text-xs text-slate-500 mb-1.5">جستجو (نام / موبایل / ایمیل)</label>
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="مثلاً 0912..."
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <div class="flex flex-col min-w-[150px]">
                <label class="text-xs text-slate-500 mb-1.5">مقایسه کریدیت</label>
                <select name="credit_operator"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                    <option value="gte" @selected($filters['credit_operator'] === 'gte')>بیشتر یا مساوی</option>
                    <option value="lte" @selected($filters['credit_operator'] === 'lte')>کمتر یا مساوی</option>
                    <option value="eq" @selected($filters['credit_operator'] === 'eq')>دقیقاً برابر</option>
                </select>
            </div>
            <div class="flex flex-col min-w-[130px]">
                <label class="text-xs text-slate-500 mb-1.5">مقدار کریدیت</label>
                <input type="number" step="0.0001" min="0" name="credit_value" value="{{ $filters['credit_value'] }}" placeholder="مثلاً 10"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <div class="flex flex-col min-w-[180px]">
                <label class="text-xs text-slate-500 mb-1.5">مرتب‌سازی</label>
                <select name="sort"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                    <option value="credit_desc" @selected($filters['sort'] === 'credit_desc')>بیشترین کریدیت مصرفی</option>
                    <option value="credit_asc" @selected($filters['sort'] === 'credit_asc')>کمترین کریدیت مصرفی</option>
                    <option value="tokens_desc" @selected($filters['sort'] === 'tokens_desc')>بیشترین توکن مصرفی</option>
                    <option value="tokens_asc" @selected($filters['sort'] === 'tokens_asc')>کمترین توکن مصرفی</option>
                    <option value="last_usage_desc" @selected($filters['sort'] === 'last_usage_desc')>آخرین مصرف</option>
                </select>
            </div>
            <div class="flex flex-col min-w-[170px]">
                <label class="text-xs text-slate-500 mb-1.5">نمایش کاربران</label>
                <select name="users_scope"
                        class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                    <option value="with_usage" @selected($filters['users_scope'] === 'with_usage')>فقط دارای مصرف</option>
                    <option value="all" @selected($filters['users_scope'] === 'all')>همه‌ی کاربران</option>
                </select>
            </div>
        </div>
    </details>
</form>

@if(! $hasData)
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-12 text-center text-slate-400">
        در این فیلتر هیچ مصرفی ثبت نشده است. بازه یا فیلتر سرویس/مدل را تغییر دهید.
    </div>
@else

{{-- ============================== KPI ============================== --}}
<section class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-6">

    {{-- کریدیت سوزانده‌شده: مهم‌ترین عدد صفحه --}}
    <div class="col-span-2 lg:col-span-2 rounded-xl p-5 text-white shadow-sm bg-gradient-to-br from-brand-600 to-brand-800">
        <div class="text-xs text-white/70 mb-2">مجموع کریدیت سوزانده‌شده</div>
        <div class="text-3xl font-bold tracking-tight" dir="ltr">{{ $fmtCredit($totals['credit'], 2) }}</div>
        @if($comparison)
            <div class="mt-3 text-xs text-white/80">
                @if(!is_null($comparison['credit_delta_pct']))
                    <span class="inline-block bg-white/15 rounded-full px-2 py-0.5" dir="ltr">
                        {{ $comparison['credit_delta_pct'] >= 0 ? '▲' : '▼' }}
                        {{ number_format(abs($comparison['credit_delta_pct']), 1) }}%
                    </span>
                    نسبت به بازه‌ی قبل ({{ $fmtCredit($comparison['prev_credit'], 2) }})
                @else
                    بازه‌ی قبل مصرفی نداشته
                @endif
            </div>
        @endif
    </div>

    {{-- هزینه دلاری: دومین عدد مهم --}}
    <div class="col-span-2 lg:col-span-2 rounded-xl p-5 bg-slate-900 text-white shadow-sm">
        <div class="text-xs text-white/60 mb-2">مجموع هزینه‌ی واقعی (دلار)</div>
        <div class="text-3xl font-bold tracking-tight" dir="ltr">{{ $fmtUsd($totals['cost_usd'], 4) }}</div>
        @if($comparison)
            <div class="mt-3 text-xs text-white/70">
                @if(!is_null($comparison['cost_delta_pct']))
                    <span class="inline-block bg-white/10 rounded-full px-2 py-0.5" dir="ltr">
                        {{ $comparison['cost_delta_pct'] >= 0 ? '▲' : '▼' }}
                        {{ number_format(abs($comparison['cost_delta_pct']), 1) }}%
                    </span>
                    نسبت به بازه‌ی قبل ({{ $fmtUsd($comparison['prev_cost_usd'], 4) }})
                @else
                    بازه‌ی قبل هزینه‌ای نداشته
                @endif
            </div>
        @else
            <div class="mt-3 text-xs text-white/60">هر ۱ دلار ≈ {{ number_format($totals['credit_per_usd'], 1) }} کریدیت</div>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs text-slate-500 mb-1.5">تعداد درخواست</div>
        <div class="text-xl font-semibold text-slate-900">{{ number_format($totals['requests']) }}</div>
        <div class="text-[11px] text-slate-400 mt-1">{{ number_format($totals['users_count']) }} کاربر فعال</div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs text-slate-500 mb-1.5">میانگین هر درخواست</div>
        <div class="text-xl font-semibold text-slate-900" dir="ltr">{{ $fmtCredit($totals['avg_credit_per_request'], 3) }}</div>
        <div class="text-[11px] text-slate-400 mt-1" dir="ltr">{{ $fmtUsd($totals['avg_cost_per_request'], 5) }}</div>
    </div>

    <div class="col-span-2 lg:col-span-6 bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-xs text-slate-500 flex flex-wrap gap-x-6 gap-y-1">
        <span>مجموع توکن: <strong class="text-slate-700">{{ number_format($totals['tokens']) }}</strong></span>
        <span>ورودی: <strong class="text-slate-700">{{ number_format($totals['input_tokens']) }}</strong></span>
        <span>خروجی: <strong class="text-slate-700">{{ number_format($totals['output_tokens']) }}</strong></span>
        @if($byModel->count())
            <span>پرمصرف‌ترین مدل: <strong class="text-slate-700" dir="ltr">{{ $byModel->first()->model }}</strong> ({{ number_format($byModel->first()->share, 1) }}٪)</span>
        @endif
        @if($byService->count())
            <span>پرمصرف‌ترین سرویس: <strong class="text-slate-700">{{ $byService->first()->service_label }}</strong> ({{ number_format($byService->first()->share, 1) }}٪)</span>
        @endif
    </div>
</section>

{{-- ============================== رایگان در برابر پولی ============================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">
    <div class="flex items-center justify-between mb-1">
        <h2 class="font-semibold text-slate-900">کریدیت رایگان در برابر پولی</h2>
        <span class="text-xs text-slate-400">
            پولی = کاربری که حداقل یک‌بار اشتراک غیررایگان خریده (حتی اگه الان کنسل/اکسپایر باشه)
        </span>
    </div>
    <p class="text-xs text-slate-400 mb-5">این تفکیک مستقل از فیلتر «نوع کاربر» بالاست و همیشه هر دو طرف را نشان می‌دهد</p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
        <div class="rounded-xl p-5 bg-rose-50 border border-rose-100">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-rose-700">کریدیت داده‌شده رایگان</span>
                <span class="text-xs bg-rose-100 text-rose-700 rounded-full px-2 py-0.5">{{ number_format($planBreakdown['free']['users_count']) }} کاربر</span>
            </div>
            <div class="text-2xl font-bold text-rose-900" dir="ltr">{{ $fmtCredit($planBreakdown['free']['credit'], 2) }}</div>
            <div class="text-xs text-rose-600 mt-1" dir="ltr">{{ $fmtUsd($planBreakdown['free']['cost_usd'], 4) }} هزینه‌ی واقعی</div>
            <div class="mt-3 flex items-center gap-2">
                <div class="flex-1 h-1.5 bg-rose-100 rounded-full overflow-hidden">
                    <div class="h-1.5 bg-rose-500 rounded-full" style="width: {{ min(100, $planBreakdown['free']['share_pct']) }}%"></div>
                </div>
                <span class="text-xs text-rose-700 font-medium" dir="ltr">{{ number_format($planBreakdown['free']['share_pct'], 1) }}%</span>
            </div>
            <div class="text-[11px] text-rose-500 mt-1">از کل کریدیت مصرفی، این‌قدر رایگان بوده</div>
        </div>

        <div class="rounded-xl p-5 bg-emerald-50 border border-emerald-100">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-emerald-700">کریدیت مصرفی کاربران پولی</span>
                <span class="text-xs bg-emerald-100 text-emerald-700 rounded-full px-2 py-0.5">{{ number_format($planBreakdown['paid']['users_count']) }} کاربر</span>
            </div>
            <div class="text-2xl font-bold text-emerald-900" dir="ltr">{{ $fmtCredit($planBreakdown['paid']['credit'], 2) }}</div>
            <div class="text-xs text-emerald-600 mt-1" dir="ltr">{{ $fmtUsd($planBreakdown['paid']['cost_usd'], 4) }} هزینه‌ی واقعی</div>
            <div class="mt-3 flex items-center gap-2">
                <div class="flex-1 h-1.5 bg-emerald-100 rounded-full overflow-hidden">
                    <div class="h-1.5 bg-emerald-500 rounded-full" style="width: {{ min(100, $planBreakdown['paid']['share_pct']) }}%"></div>
                </div>
                <span class="text-xs text-emerald-700 font-medium" dir="ltr">{{ number_format($planBreakdown['paid']['share_pct'], 1) }}%</span>
            </div>
            <div class="text-[11px] text-emerald-500 mt-1">از کل کریدیت مصرفی، این‌قدر توسط کاربر پولی بوده</div>
        </div>
    </div>

    <div class="relative h-56" dir="ltr"><canvas id="chart-plan-type"></canvas></div>
</section>

{{-- ============================== روند روزانه ============================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-slate-900">
            روند کریدیت و هزینه
            <span class="text-xs font-normal text-slate-400">
                ({{ ['day' => 'روزانه', 'week' => 'هفتگی', 'month' => 'ماهانه'][$filters['group']] }})
            </span>
        </h2>
        <span class="text-xs text-slate-400">ستون‌ها: کریدیت — خط: هزینه (دلار)</span>
    </div>
    <div class="relative h-72" dir="ltr"><canvas id="chart-daily"></canvas></div>
</section>

{{-- ============================== مدل‌ها و سرویس‌ها ============================== --}}
<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
        <h2 class="font-semibold text-slate-900 mb-1">سهم هر مدل از کریدیت</h2>
        <p class="text-xs text-slate-400 mb-4">کدام مدل‌ها بیشترین کریدیت را سوزانده‌اند</p>
        <div class="relative h-72" dir="ltr"><canvas id="chart-models"></canvas></div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
        <h2 class="font-semibold text-slate-900 mb-1">مصرف هر سرویس (کار انجام‌شده)</h2>
        <p class="text-xs text-slate-400 mb-4">مرتب‌شده از بیشترین به کمترین کریدیت</p>
        <div class="relative h-72" dir="ltr"><canvas id="chart-services"></canvas></div>
    </div>
</section>

{{-- ============================== سرویس × مدل ============================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">
    <h2 class="font-semibold text-slate-900 mb-1">هر سرویس با چه مدلی چقدر کریدیت سوزانده؟</h2>
    <p class="text-xs text-slate-400 mb-4">هر ستون یک سرویس است و رنگ‌ها سهم هر مدل در آن سرویس را نشان می‌دهند</p>
    <div class="relative h-80" dir="ltr"><canvas id="chart-stacked"></canvas></div>
</section>

@unless($filters['user_id'])
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">
    <h2 class="font-semibold text-slate-900 mb-1">۱۰ کاربر پرمصرف</h2>
    <p class="text-xs text-slate-400 mb-4">بر اساس کریدیت سوزانده‌شده در این فیلتر</p>
    <div class="relative h-80" dir="ltr"><canvas id="chart-users"></canvas></div>
</section>
@endunless

{{-- ============================== جدول مدل‌ها + سرویس‌ها ============================== --}}
<section class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
        <h2 class="font-semibold text-slate-900 mb-4">جدول مدل‌ها</h2>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[520px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500">
                        <th class="text-right font-medium px-3 py-3">مدل</th>
                        <th class="text-right font-medium px-3 py-3">کریدیت</th>
                        <th class="text-right font-medium px-3 py-3">هزینه ($)</th>
                        <th class="text-right font-medium px-3 py-3">درخواست</th>
                        <th class="text-right font-medium px-3 py-3 w-32">سهم</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach($byModel as $row)
                    <tr class="hover:bg-slate-50/70">
                        <td class="px-3 py-2.5 text-slate-800" dir="ltr">{{ $row->model }}</td>
                        <td class="px-3 py-2.5 font-semibold text-slate-900">{{ $fmtCredit($row->credit) }}</td>
                        <td class="px-3 py-2.5 text-slate-700" dir="ltr">{{ $fmtUsd($row->cost_usd, 5) }}</td>
                        <td class="px-3 py-2.5 text-slate-700">{{ number_format($row->requests) }}</td>
                        <td class="px-3 py-2.5">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-1.5 bg-brand-500 rounded-full" style="width: {{ min(100, $row->share) }}%"></div>
                                </div>
                                <span class="text-xs text-slate-500 w-10 text-left" dir="ltr">{{ number_format($row->share, 1) }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-semibold text-slate-900 text-sm">
                        <td class="px-3 py-2.5">مجموع</td>
                        <td class="px-3 py-2.5">{{ $fmtCredit($totals['credit']) }}</td>
                        <td class="px-3 py-2.5" dir="ltr">{{ $fmtUsd($totals['cost_usd'], 5) }}</td>
                        <td class="px-3 py-2.5">{{ number_format($totals['requests']) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
        <h2 class="font-semibold text-slate-900 mb-4">جدول سرویس‌ها</h2>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[560px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 text-xs text-slate-500">
                        <th class="text-right font-medium px-3 py-3">سرویس</th>
                        <th class="text-right font-medium px-3 py-3">کریدیت</th>
                        <th class="text-right font-medium px-3 py-3">هزینه ($)</th>
                        <th class="text-right font-medium px-3 py-3">درخواست</th>
                        <th class="text-right font-medium px-3 py-3">میانگین کریدیت</th>
                        <th class="text-right font-medium px-3 py-3 w-32">سهم</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @foreach($byService as $row)
                    <tr class="hover:bg-slate-50/70">
                        <td class="px-3 py-2.5 text-slate-800">
                            {{ $row->service_label }}
                            @if($row->service_label !== $row->service_name)
                                <div class="text-[11px] text-slate-400" dir="ltr">{{ $row->service_name }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 font-semibold text-slate-900">{{ $fmtCredit($row->credit) }}</td>
                        <td class="px-3 py-2.5 text-slate-700" dir="ltr">{{ $fmtUsd($row->cost_usd, 5) }}</td>
                        <td class="px-3 py-2.5 text-slate-700">{{ number_format($row->requests) }}</td>
                        <td class="px-3 py-2.5 text-slate-700">{{ $fmtCredit($row->avg_credit) }}</td>
                        <td class="px-3 py-2.5">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-1.5 bg-emerald-500 rounded-full" style="width: {{ min(100, $row->share) }}%"></div>
                                </div>
                                <span class="text-xs text-slate-500 w-10 text-left" dir="ltr">{{ number_format($row->share, 1) }}%</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 font-semibold text-slate-900 text-sm">
                        <td class="px-3 py-2.5">مجموع</td>
                        <td class="px-3 py-2.5">{{ $fmtCredit($totals['credit']) }}</td>
                        <td class="px-3 py-2.5" dir="ltr">{{ $fmtUsd($totals['cost_usd'], 5) }}</td>
                        <td class="px-3 py-2.5">{{ number_format($totals['requests']) }}</td>
                        <td class="px-3 py-2.5">{{ $fmtCredit($totals['avg_credit_per_request']) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</section>

{{-- ============================== ماتریس سرویس × مدل ============================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">
    <h2 class="font-semibold text-slate-900 mb-1">ریز هزینه‌ی هر سرویس به تفکیک مدل</h2>
    <p class="text-xs text-slate-400 mb-4">دقیق‌ترین نما: مثلاً «تولید ایده مقاله» با یک مدل مشخص چند درخواست داشته و چقدر هزینه کرده</p>
    <div class="overflow-x-auto rounded-lg border border-slate-200">
        <table class="w-full min-w-[820px] border-collapse text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs text-slate-500">
                    <th class="text-right font-medium px-3 py-3">سرویس</th>
                    <th class="text-right font-medium px-3 py-3">مدل</th>
                    <th class="text-right font-medium px-3 py-3">کریدیت</th>
                    <th class="text-right font-medium px-3 py-3">هزینه ($)</th>
                    <th class="text-right font-medium px-3 py-3">درخواست</th>
                    <th class="text-right font-medium px-3 py-3">میانگین کریدیت</th>
                    <th class="text-right font-medium px-3 py-3">میانگین هزینه ($)</th>
                    <th class="text-right font-medium px-3 py-3 w-36">سهم از کل</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @foreach($matrix as $row)
                <tr class="hover:bg-slate-50/70">
                    <td class="px-3 py-2.5 text-slate-800">{{ $row->service_label }}</td>
                    <td class="px-3 py-2.5 text-slate-700" dir="ltr">{{ $row->model }}</td>
                    <td class="px-3 py-2.5 font-semibold text-slate-900">{{ $fmtCredit($row->credit) }}</td>
                    <td class="px-3 py-2.5 text-slate-700" dir="ltr">{{ $fmtUsd($row->cost_usd, 5) }}</td>
                    <td class="px-3 py-2.5 text-slate-700">{{ number_format($row->requests) }}</td>
                    <td class="px-3 py-2.5 text-slate-700">{{ $fmtCredit($row->avg_credit) }}</td>
                    <td class="px-3 py-2.5 text-slate-700" dir="ltr">{{ $fmtUsd($row->avg_cost, 5) }}</td>
                    <td class="px-3 py-2.5">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-1.5 bg-amber-500 rounded-full" style="width: {{ min(100, $row->share) }}%"></div>
                            </div>
                            <span class="text-xs text-slate-500 w-10 text-left" dir="ltr">{{ number_format($row->share, 1) }}%</span>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>

@endif

{{-- ============================== جدول کاربران ============================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-semibold text-slate-900">آنالیز به تفکیک کاربر</h2>
        <span class="text-sm text-slate-500">تعداد کاربران: <strong class="text-slate-800">{{ number_format($users->total()) }}</strong></span>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200">
        <table class="w-full min-w-[1080px] border-collapse text-sm">
            <thead>
                <tr class="bg-slate-50">
                    @foreach(['کاربر', 'نوع کاربر', 'موبایل', 'اشتراک', 'کریدیت مصرفی (بازه)', 'هزینه دلاری (بازه)', 'درخواست', 'سهم از کل', 'کریدیت کل (تا امروز)', 'آخرین مصرف'] as $h)
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">{{ $h }}</th>
                    @endforeach
                    <th class="px-3 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($users as $user)
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="px-3 py-3 align-top text-slate-800">
                        {{ $user->name ?? '—' }}
                        @if($user->email)<div class="text-[11px] text-slate-400">{{ $user->email }}</div>@endif
                    </td>
                    <td class="px-3 py-3 align-top">
                        @if($user->is_paid_user)
                            <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-medium bg-emerald-100 text-emerald-700">پولی</span>
                        @else
                            <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-medium bg-rose-100 text-rose-700">رایگان</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 align-top text-slate-600" dir="ltr">{{ $user->phone ?? '—' }}</td>
                    <td class="px-3 py-3 align-top">
                        @if($user->active_subscription)
                            <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-medium
                                {{ $user->active_subscription->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $user->active_subscription->status }}
                            </span>
                            <div class="text-[11px] text-slate-400 mt-1">پلن #{{ $user->active_subscription->plan_id }}</div>
                        @else
                            <span class="inline-block px-2.5 py-1 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500">بدون اشتراک</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 align-top font-semibold text-slate-900">{{ $fmtCredit($user->period_credit) }}</td>
                    <td class="px-3 py-3 align-top text-slate-700" dir="ltr">{{ $fmtUsd($user->period_cost_usd, 5) }}</td>
                    <td class="px-3 py-3 align-top text-slate-700">{{ number_format((int) $user->period_requests) }}</td>
                    <td class="px-3 py-3 align-top">
                        <div class="flex items-center gap-2 w-28">
                            <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-1.5 bg-brand-500 rounded-full" style="width: {{ min(100, $user->share) }}%"></div>
                            </div>
                            <span class="text-xs text-slate-500" dir="ltr">{{ number_format($user->share, 1) }}%</span>
                        </div>
                    </td>
                    <td class="px-3 py-3 align-top text-slate-700">{{ $fmtCredit($user->lifetime_credit) }}</td>
                    <td class="px-3 py-3 align-top"><small class="text-slate-400">{{ $user->last_usage_at_jalali ?? '—' }}</small></td>
                    <td class="px-3 py-3 align-top whitespace-nowrap">
                        <a href="{{ request()->fullUrlWithQuery(['user_id' => $user->id, 'page' => null]) }}"
                           class="text-brand-600 hover:text-brand-800 font-medium ml-3">آنالیز</a>
                        <button type="button"
                                onclick="openUsageModal('{{ route('admin.usage.show', $user) }}')"
                                class="text-slate-500 hover:text-slate-800 font-medium">
                            جزئیات
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="px-3 py-8 text-center text-slate-400">کاربری با این فیلترها یافت نشد.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</section>

{{-- ============================== مودال جزئیات کاربر ============================== --}}
<div id="usage-modal-overlay"
     class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-start sm:items-center justify-center p-4 overflow-y-auto">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl my-8">
        <div id="usage-modal-body" class="p-5 sm:p-6 max-h-[85vh] overflow-y-auto">
            {{-- محتوا از طریق AJAX بارگذاری می‌شود --}}
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    // ---------- فیلد بازه دلخواه ----------
    document.getElementById('period-select').addEventListener('change', function (e) {
        var isCustom = e.target.value === 'custom';
        document.getElementById('custom-range-fields').style.display = isCustom ? '' : 'none';
        document.getElementById('custom-range-fields-to').style.display = isCustom ? '' : 'none';
    });

    // ---------- نمودارها ----------
    (function () {
        if (typeof Chart === 'undefined') return;

        var charts = @json($charts);
        var palette = ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6', '#ec4899', '#84cc16', '#f97316', '#64748b'];
        var fmt = function (v, d) { return Number(v).toLocaleString('en-US', { maximumFractionDigits: d === undefined ? 2 : d }); };

        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.color = '#64748b';
        Chart.defaults.borderColor = '#f1f5f9';
        // تولتیپ راست‌به‌چپ تا ترکیب فارسی/انگلیسی به‌هم نریزد
        Chart.defaults.plugins.tooltip.rtl = true;
        Chart.defaults.plugins.tooltip.textDirection = 'rtl';
        Chart.defaults.plugins.tooltip.padding = 10;

        function el(id) { return document.getElementById(id); }

        // روند (روزانه/هفتگی/ماهانه بسته به فیلتر «group»)
        if (el('chart-daily')) {
            new Chart(el('chart-daily'), {
                data: {
                    labels: charts.daily.labels,
                    datasets: [
                        { type: 'bar', label: 'کریدیت', data: charts.daily.credit, backgroundColor: '#6366f1', borderRadius: 4, yAxisID: 'y', order: 2 },
                        { type: 'line', label: 'هزینه ($)', data: charts.daily.cost, borderColor: '#f59e0b', backgroundColor: '#f59e0b', tension: .3, pointRadius: 3, yAxisID: 'y1', order: 1 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'کریدیت' } },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'دلار' } }
                    },
                    plugins: {
                        tooltip: { callbacks: { label: function (c) {
                            return c.dataset.label + ': ' + (c.dataset.yAxisID === 'y1' ? '$' + fmt(c.parsed.y, 5) : fmt(c.parsed.y, 4));
                        }, afterBody: function (items) {
                            var r = charts.daily.requests[items[0].dataIndex];
                            return 'درخواست: ' + fmt(r, 0);
                        } } }
                    }
                }
            });
        }

        // سهم مدل‌ها
        if (el('chart-models')) {
            new Chart(el('chart-models'), {
                type: 'doughnut',
                data: {
                    labels: charts.models.labels,
                    datasets: [{ data: charts.models.credit, backgroundColor: palette, borderWidth: 2, borderColor: '#fff' }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                        tooltip: { callbacks: { label: function (c) {
                            var total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            return [
                                c.label,
                                'کریدیت: ' + fmt(c.parsed, 4),
                                'هزینه: $' + fmt(charts.models.cost[c.dataIndex], 5),
                                'سهم: ' + (total ? (c.parsed / total * 100).toFixed(1) : 0) + '%'
                            ];
                        } } }
                    }
                }
            });
        }

        // سرویس‌ها
        if (el('chart-services')) {
            new Chart(el('chart-services'), {
                type: 'bar',
                data: {
                    labels: charts.services.labels,
                    datasets: [{ label: 'کریدیت', data: charts.services.credit, backgroundColor: '#10b981', borderRadius: 4 }]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false },
                        tooltip: { callbacks: { label: function (c) {
                            return [
                                'کریدیت: ' + fmt(c.parsed.x, 4),
                                'هزینه: $' + fmt(charts.services.cost[c.dataIndex], 5)
                            ];
                        } } } },
                    scales: { x: { beginAtZero: true } }
                }
            });
        }

        // سرویس × مدل
        if (el('chart-stacked')) {
            new Chart(el('chart-stacked'), {
                type: 'bar',
                data: {
                    labels: charts.stacked.labels,
                    datasets: charts.stacked.datasets.map(function (d, i) {
                        return { label: d.label, data: d.data, backgroundColor: palette[i % palette.length], borderRadius: 2 };
                    })
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, title: { display: true, text: 'کریدیت' } } },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                        tooltip: { callbacks: { label: function (c) {
                            return c.dataset.label + ': ' + fmt(c.parsed.y, 4);
                        } } }
                    }
                }
            });
        }

        // کاربران پرمصرف
        if (el('chart-users')) {
            new Chart(el('chart-users'), {
                type: 'bar',
                data: {
                    labels: charts.topUsers.labels,
                    datasets: [{ label: 'کریدیت', data: charts.topUsers.credit, backgroundColor: '#6366f1', borderRadius: 4 }]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false },
                        tooltip: { callbacks: { label: function (c) { return 'کریدیت: ' + fmt(c.parsed.x, 4); } } } },
                    scales: { x: { beginAtZero: true } }
                }
            });
        }

        // رایگان در برابر پولی
        if (el('chart-plan-type')) {
            new Chart(el('chart-plan-type'), {
                type: 'bar',
                data: {
                    labels: charts.planType.labels,
                    datasets: [{
                        label: 'کریدیت',
                        data: charts.planType.credit,
                        backgroundColor: ['#f43f5e', '#10b981'],
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function (c) {
                            return [
                                'کریدیت: ' + fmt(c.parsed.x, 4),
                                'هزینه: $' + fmt(charts.planType.cost[c.dataIndex], 5)
                            ];
                        } } }
                    },
                    scales: { x: { beginAtZero: true } }
                }
            });
        }
    })();

    // ---------- گزارش JSON برای تحلیل با AI ----------
    var usageReportData = @json($report);

    function usageReportJsonText() {
        return JSON.stringify(usageReportData, null, 2);
    }

    function copyUsageReportJson(btn) {
        var text = usageReportJsonText();
        var done = function (ok) {
            if (!btn) return;
            var original = btn.dataset.originalText || btn.textContent;
            btn.dataset.originalText = original;
            btn.textContent = ok ? 'کپی شد ✓' : 'خطا در کپی';
            setTimeout(function () { btn.textContent = original; }, 1500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); });
        } else {
            // fallback برای مرورگرهای قدیمی‌تر یا صفحات غیر-HTTPS
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(true); } catch (e) { done(false); }
            document.body.removeChild(ta);
        }
    }

    function downloadUsageReportJson() {
        var blob = new Blob([usageReportJsonText()], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'ai-usage-report-' + new Date().toISOString().slice(0, 10) + '.json';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // ---------- مودال جزئیات کاربر ----------
    function openUsageModal(url) {
        document.getElementById('usage-modal-overlay').classList.remove('hidden');
        document.getElementById('usage-modal-body').innerHTML =
            '<div class="p-10 text-center text-slate-400">در حال بارگذاری...</div>';
        loadUsageModalContent(url);
    }

    function closeUsageModal() {
        document.getElementById('usage-modal-overlay').classList.add('hidden');
    }

    function loadUsageModalContent(url) {
        var body = document.getElementById('usage-modal-body');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.text(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () {
                body.innerHTML = '<div class="p-10 text-center text-red-500">خطا در بارگذاری اطلاعات.</div>';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var overlay = document.getElementById('usage-modal-overlay');
        var body = document.getElementById('usage-modal-body');

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeUsageModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.classList.contains('hidden')) closeUsageModal();
        });

        body.addEventListener('change', function (e) {
            if (e.target.matches('[data-period-select]')) {
                var isCustom = e.target.value === 'custom';
                e.target.closest('form').querySelectorAll('[data-custom-range]')
                    .forEach(function (el) { el.style.display = isCustom ? '' : 'none'; });
            }
        });

        body.addEventListener('submit', function (e) {
            if (e.target.matches('[data-ajax-form]')) {
                e.preventDefault();
                var form = e.target;
                var params = new URLSearchParams(new FormData(form)).toString();
                loadUsageModalContent(form.action + (params ? '?' + params : ''));
            }
        });

        body.addEventListener('click', function (e) {
            var link = e.target.closest('a');
            if (link && body.contains(link)) {
                e.preventDefault();
                loadUsageModalContent(link.href);
            }
        });
    });
</script>
@endpush
@endsection
