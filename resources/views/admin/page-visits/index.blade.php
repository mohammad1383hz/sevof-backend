@extends('layouts.admin')

@section('content')
@php
    // نکته: از closure به‌جای function استفاده شده تا اگر این ویو در یک
    // ریکوئست بیش از یک‌بار render شود (مثلاً include مجدد)، خطای
    // "Cannot redeclare formatDuration()" رخ ندهد.
    $formatDuration = function ($seconds) {
        if (!$seconds || $seconds <= 0) return 'زیر ۱ ثانیه';
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return number_format($minutes) . ' دقیقه و ' . $remainingSeconds . ' ثانیه';
        }
        return $remainingSeconds . ' ثانیه';
    };

    // تبدیل تاریخ میلادی به شمسی با فرمت دلخواه
    $toJalali = function ($date, $format = 'Y/m/d H:i:s') {
        if (!$date) return null;
        return \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($date))->format($format);
    };

    // دکمه ورود به حساب کاربر (Impersonate) - برای استفاده در جدول‌های مختلف این صفحه
    $impersonateButton = function ($impUser) {
        if (!$impUser) return '';
        return '
            <form action="' . route('admin.users.impersonate', $impUser->id) . '" method="POST" target="_blank"
                  onsubmit="return confirm(\'وارد حساب کاربر #' . $impUser->id . ' (' . e($impUser->name) . ') بشی؟\');">
                ' . csrf_field() . '
                <button type="submit"
                        class="inline-flex items-center gap-1 text-xs text-emerald-600 hover:text-emerald-700 font-medium border border-emerald-200 hover:bg-emerald-50 px-2.5 py-1.5 rounded-lg transition whitespace-nowrap">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                        <polyline points="10 17 15 12 10 7"/>
                        <line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    ورود به حساب
                </button>
            </form>
        ';
    };
@endphp

<div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 mb-1">گزارش بازدید صفحات و کاربران</h1>
        <p class="text-slate-500">نمایش آمار و رفتار کاربران از {{ $toJalali($from, 'Y/m/d') }} تا {{ $toJalali($to, 'Y/m/d') }}</p>
    </div>
</div>

{{-- ===================== فرم فیلتر ===================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">

        <div class="flex flex-col min-w-[150px]">
            <label class="text-xs text-slate-500 mb-1.5">از تاریخ</label>
            <input type="text" name="from" value="{{ $toJalali($from, 'Y/m/d') }}" placeholder="۱۴۰۳/۰۱/۰۱"
                   class="jalali-datepicker border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[150px]">
            <label class="text-xs text-slate-500 mb-1.5">تا تاریخ</label>
            <input type="text" name="to" value="{{ $toJalali($to, 'Y/m/d') }}" placeholder="۱۴۰۳/۰۱/۰۱"
                   class="jalali-datepicker border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[190px]">
            <label class="text-xs text-slate-500 mb-1.5">صفحه</label>
            <select name="page"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">همه صفحات</option>
                @foreach($availablePages as $p)
                    <option value="{{ $p }}" {{ $selectedPage === $p ? 'selected' : '' }}>{{ $p }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col min-w-[220px] flex-1">
            <label class="text-xs text-slate-500 mb-1.5">جستجوی کاربر</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="نام، ایمیل یا موبایل..."
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 transition-colors text-white font-medium rounded-lg px-5 py-2 text-sm shadow-sm">
                اعمال فیلتر
            </button>

            @if($selectedPage || $search || $userId)
                <a href="{{ route('admin.page-visits.index') }}"
                   class="bg-slate-100 hover:bg-slate-200 transition-colors text-slate-700 font-medium rounded-lg px-4 py-2 text-sm">
                    حذف فیلترها
                </a>
            @endif
        </div>
    </form>
</section>

{{-- ===================== کارت‌های KPI ===================== --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
        <div class="text-xs text-slate-500 mb-2">کل بازدیدها</div>
        <div class="text-2xl font-bold text-slate-900">{{ number_format($summary['total_visits']) }}</div>
        <div class="text-xs text-slate-400 mt-1">تعداد کل بارگذاری صفحات</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
        <div class="text-xs text-slate-500 mb-2">کاربران یکتا</div>
        <div class="text-2xl font-bold text-slate-900">{{ number_format($summary['unique_users']) }}</div>
        <div class="text-xs text-slate-400 mt-1">تعداد افراد بازدیدکننده</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
        <div class="text-xs text-slate-500 mb-2">میانگین زمان حضور</div>
        <div class="text-2xl font-bold text-slate-900">{{ $formatDuration($summary['avg_duration']) }}</div>
        <div class="text-xs text-slate-400 mt-1">در هر نشست (Session)</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
        <div class="text-xs text-slate-500 mb-2">مجموع زمان حضور</div>
        <div class="text-2xl font-bold text-slate-900">
            {{ number_format(round($summary['total_duration'] / 60)) }}
            <small class="text-sm font-normal text-slate-400">دقیقه</small>
        </div>
        <div class="text-xs text-slate-400 mt-1">زمان تعامل کل کاربران با سایت</div>
    </div>
</div>

{{-- ===================== پربازدیدترین صفحات + فعال‌ترین کاربران ===================== --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

    {{-- پربازدیدترین صفحات --}}
    <section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
        <h2 class="text-base font-semibold text-slate-800 mb-4">پربازدیدترین صفحات</h2>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[420px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">نام صفحه / مسیر</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">تعداد بازدید</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">میانگین ماندگاری</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($topPages as $pageItem)
                    <tr class="hover:bg-slate-50/70 transition-colors">
                        <td class="px-3 py-3 align-top font-medium text-slate-800">{{ $pageItem->page }}</td>
                        <td class="px-3 py-3 align-top text-slate-700">{{ number_format($pageItem->visits_count) }}</td>
                        <td class="px-3 py-3 align-top text-slate-600">{{ $formatDuration(round($pageItem->avg_duration)) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-3 py-8 text-center text-slate-400">داده‌ای در این بازه یافت نشد.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- فعال‌ترین کاربران --}}
    <section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
        <h2 class="text-base font-semibold text-slate-800 mb-4">فعال‌ترین کاربران (بیشترین بازدید)</h2>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[420px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">کاربر</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">تعداد بازدید</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">کل زمان حضور</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($topUsers as $topUser)
                    <tr class="hover:bg-slate-50/70 transition-colors">
                        <td class="px-3 py-3 align-top">
                            @if($topUser->user)
                                <a href="{{ route('admin.page-visits.index', ['user_id' => $topUser->user_id]) }}"
                                   class="text-brand-600 hover:text-brand-800 font-medium">
                                    {{ $topUser->user->name ?? 'کاربر #' . $topUser->user_id }}
                                </a>
                                <br>
                                <small class="text-slate-400">{{ $topUser->user->email ?? $topUser->user->mobile }}</small>
                            @else
                                <span class="text-slate-400">کاربر حذف شده (#{{ $topUser->user_id }})</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 align-top text-slate-700">{{ number_format($topUser->visits_count) }}</td>
                        <td class="px-3 py-3 align-top text-slate-600">{{ $formatDuration($topUser->total_duration) }}</td>
                        <td class="px-3 py-3 align-top">
                            {!! $impersonateButton($topUser->user) !!}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-3 py-8 text-center text-slate-400">داده‌ای در این بازه یافت نشد.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

{{-- ===================== جدول کامل ویزیت‌ها ===================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
        <h2 class="text-base font-semibold text-slate-800">جزییات آخرین بازدیدها</h2>
        <div class="text-xs text-slate-400">
            تعداد در این صفحه: {{ $visits->count() }} از {{ number_format($visits->total()) }}
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200">
        <table class="w-full min-w-[900px] border-collapse text-sm">
            <thead>
                <tr class="bg-slate-50">
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">شناسه</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">کاربر</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">صفحه بازدیدشده</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">زمان ورود</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">زمان خروج</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">مدت حضور</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($visits as $visit)
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="px-3 py-3 align-top">
                        <code class="text-xs bg-slate-100 text-slate-600 rounded px-1.5 py-0.5">#{{ $visit->id }}</code>
                    </td>
                    <td class="px-3 py-3 align-top">
                        @if($visit->user)
                            <strong class="text-slate-800 font-medium">{{ $visit->user->name }}</strong>
                            <br><small class="text-slate-400">{{ $visit->user->email ?? $visit->user->mobile }}</small>
                        @else
                            <span class="text-slate-400">شناسه: {{ $visit->user_id }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 align-top">
                        <span class="inline-block bg-slate-100 text-slate-600 rounded px-2 py-1 text-xs font-mono" dir="ltr">
                            {{ $visit->page }}
                        </span>
                    </td>
                    <td class="px-3 py-3 align-top text-slate-600 whitespace-nowrap">
                        {{ $toJalali($visit->entered_at, 'Y/m/d H:i:s') }}
                    </td>
                    <td class="px-3 py-3 align-top whitespace-nowrap">
                        @if($visit->left_at)
                            <span class="text-slate-600">{{ $toJalali($visit->left_at, 'H:i:s') }}</span>
                        @else
                            <span class="inline-block bg-amber-100 text-amber-700 rounded-full px-2.5 py-1 text-[11px] font-medium">
                                در حال بازدید / بدون خروج
                            </span>
                        @endif
                    </td>
                    <td class="px-3 py-3 align-top">
                        @if($visit->duration_seconds !== null)
                            <strong class="text-emerald-600 font-semibold">{{ $formatDuration($visit->duration_seconds) }}</strong>
                        @else
                            <span class="text-slate-400">نامشخص</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 align-top">
                        {!! $impersonateButton($visit->user) !!}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-3 py-8 text-center text-slate-400">هیچ بازدیدی با فیلترهای انتخابی ثبت نشده است.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $visits->links() }}</div>
</section>

@endsection
