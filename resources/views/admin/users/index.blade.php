@extends('layouts.admin')

@section('content')
@php
    // فرمت تاریخ میلادی به شمسی
    $toJalaliFmt = function ($date, $format = 'Y/m/d H:i') {
        if (!$date) return '-';
        return \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($date))->format($format);
    };

    // دکمه ورود به حساب کاربر (Impersonate)
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

    // نشان پلن فعلی کاربر
    $planBadge = function ($sub) {
        if (!$sub || !$sub->plan) {
            return '<span class="text-xs text-slate-400">بدون اشتراک</span>';
        }
        $cls = $sub->plan->is_free ? 'bg-slate-100 text-slate-600' : 'bg-emerald-50 text-emerald-700';
        return '<span class="inline-flex items-center text-xs font-medium px-2 py-1 rounded-full ' . $cls . '">' . e($sub->plan->name) . '</span>';
    };

    // نوار مصرف کریدیت کاربر
    $creditUsage = function ($sub) {
        if (!$sub || !$sub->plan || !$sub->plan->credit || $sub->plan->credit <= 0) {
            return '<span class="text-xs text-slate-400">-</span>';
        }
        $pct = min(100, round(($sub->credit_used / $sub->plan->credit) * 100));
        return '
            <div class="text-xs text-slate-600 whitespace-nowrap">' . number_format($sub->credit_used, 2) . ' / ' . number_format($sub->plan->credit, 2) . '</div>
            <div class="w-24 h-1.5 bg-slate-100 rounded-full mt-1 overflow-hidden">
                <div class="h-full bg-brand-500" style="width: ' . $pct . '%"></div>
            </div>
        ';
    };

    // نشان تعداد سایت‌های ووکامرسی
    $wooBadge = function ($count) {
        if (!$count || $count <= 0) return '';
        return '<span class="inline-flex items-center text-[11px] font-medium px-1.5 py-0.5 rounded-full bg-purple-50 text-purple-700 border border-purple-100 whitespace-nowrap" title="تعداد سایت‌های ووکامرسی">ووکامرس: ' . number_format($count) . '</span>';
    };

    // نشان خریدهای موفق کاربر (تعداد، نام پلن‌ها، جمع مبلغ)
    $purchaseBadge = function ($payments) {
        if (!$payments || $payments->isEmpty()) {
            return '<span class="text-xs text-slate-400">بدون خرید</span>';
        }
        $planNames = $payments->map(fn ($p) => $p->plan?->name ?? 'نامشخص')->countBy();
        $titles = $planNames->map(fn ($c, $name) => $name . ($c > 1 ? " ×{$c}" : ''))->implode('، ');
        $total = $payments->sum('amount');

        return '<div class="flex flex-col gap-1">
            <span class="inline-flex items-center w-fit text-xs font-medium px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-100 whitespace-nowrap">'
                . number_format($payments->count()) . ' خرید موفق
            </span>
            <span class="text-[11px] text-slate-500">' . e($titles) . '</span>
            <span class="text-[11px] text-slate-400 whitespace-nowrap">جمع: ' . number_format($total) . '</span>
        </div>';
    };

    // پریست‌های سریع فیلتر تاریخ
    $presets = [
        'today'     => 'امروز',
        'yesterday' => 'دیروز',
        '3d'        => '۳ روز اخیر',
        '7d'        => 'هفته اخیر',
        '30d'       => 'ماه اخیر',
        '90d'       => '۹۰ روز اخیر',
    ];
@endphp

<div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900 mb-1">مدیریت کاربران</h1>
        <p class="text-slate-500">لیست کاربران ثبت‌نام‌شده و امکان جستجو، فیلتر و ورود به حساب</p>
    </div>
</div>

{{-- ===================== کارت KPI بازه انتخابی ===================== --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
        <div class="text-xs text-slate-500 mb-2">کاربران ثبت‌نام‌شده در «{{ $rangeLabel }}»</div>
        <div class="text-2xl font-bold text-slate-900">{{ number_format($registeredInRangeCount) }}</div>
        <div class="text-xs text-slate-400 mt-1">بر اساس فیلتر تاریخ فعال</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-5">
        <div class="text-xs text-slate-500 mb-2">کل نتایج فیلترشده</div>
        <div class="text-2xl font-bold text-slate-900">{{ number_format($users->total()) }}</div>
        <div class="text-xs text-slate-400 mt-1">با احتساب جستجو، شناسه، بازه تاریخ، پلن، ووکامرس و خرید</div>
    </div>
</div>

{{-- ===================== فرم فیلتر ===================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6 mb-6">

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="{{ route('admin.users.index', array_filter(['user_id' => $userIdSearch, 'woocommerce' => $woocommerceFilter, 'purchase' => $purchaseFilter, 'per_page' => $perPage !== 100 ? $perPage : null])) }}"
           class="text-xs font-medium px-3 py-1.5 rounded-full border transition
                  {{ !$preset && !$fromJalali && !$toJalali ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
            کل بازه
        </a>
        @foreach($presets as $key => $label)
            <a href="{{ route('admin.users.index', array_filter(['preset' => $key, 'search' => $search, 'user_id' => $userIdSearch, 'sort' => $sort, 'plan' => $planFilter, 'woocommerce' => $woocommerceFilter, 'purchase' => $purchaseFilter, 'per_page' => $perPage])) }}"
               class="text-xs font-medium px-3 py-1.5 rounded-full border transition
                      {{ $preset === $key && !$fromJalali && !$toJalali ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-3 items-end">

        <div class="flex flex-col min-w-[150px]">
            <label class="text-xs text-slate-500 mb-1.5">از تاریخ</label>
            <input type="text" name="from" value="{{ $fromJalali }}" placeholder="۱۴۰۳/۰۱/۰۱"
                   class="jalali-datepicker border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[150px]">
            <label class="text-xs text-slate-500 mb-1.5">تا تاریخ</label>
            <input type="text" name="to" value="{{ $toJalali }}" placeholder="۱۴۰۳/۰۱/۰۱"
                   class="jalali-datepicker border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        {{-- جستجوی دقیق بر اساس شناسه --}}
        <div class="flex flex-col min-w-[130px]">
            <label class="text-xs text-slate-500 mb-1.5">جستجو بر اساس شناسه</label>
            <input type="text" name="user_id" value="{{ $userIdSearch }}" placeholder="مثلاً 125"
                   inputmode="numeric" dir="ltr"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[220px] flex-1">
            <label class="text-xs text-slate-500 mb-1.5">جستجوی کاربر</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="نام، ایمیل یا موبایل..."
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[170px]">
            <label class="text-xs text-slate-500 mb-1.5">پلن اشتراک</label>
            <select name="plan"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">همه</option>
                <option value="none" {{ $planFilter === 'none' ? 'selected' : '' }}>بدون اشتراک</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->slug }}" {{ $planFilter === $plan->slug ? 'selected' : '' }}>{{ $plan->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- فیلتر ووکامرس --}}
        <div class="flex flex-col min-w-[190px]">
            <label class="text-xs text-slate-500 mb-1.5">ووکامرس</label>
            <select name="woocommerce"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">همه</option>
                <option value="yes" {{ $woocommerceFilter === 'yes' ? 'selected' : '' }}>دارای سایت ووکامرس</option>
                <option value="no" {{ $woocommerceFilter === 'no' ? 'selected' : '' }}>بدون سایت ووکامرس</option>
            </select>
        </div>

        {{-- فیلتر خرید --}}
        <div class="flex flex-col min-w-[170px]">
            <label class="text-xs text-slate-500 mb-1.5">خرید</label>
            <select name="purchase"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="">همه</option>
                <option value="yes" {{ $purchaseFilter === 'yes' ? 'selected' : '' }}>دارای خرید موفق</option>
                <option value="no" {{ $purchaseFilter === 'no' ? 'selected' : '' }}>بدون خرید</option>
            </select>
        </div>

        <div class="flex flex-col min-w-[190px]">
            <label class="text-xs text-slate-500 mb-1.5">مرتب‌سازی</label>
            <select name="sort"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="newest" {{ $sort === 'newest' || !$sort ? 'selected' : '' }}>جدیدترین ثبت‌نام</option>
                <option value="oldest" {{ $sort === 'oldest' ? 'selected' : '' }}>قدیمی‌ترین ثبت‌نام</option>
                <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>نام (الفبا)</option>
                <option value="credit_desc" {{ $sort === 'credit_desc' ? 'selected' : '' }}>بیشترین مصرف کریدیت</option>
                <option value="credit_asc" {{ $sort === 'credit_asc' ? 'selected' : '' }}>کمترین مصرف کریدیت</option>
            </select>
        </div>

        <div class="flex flex-col min-w-[130px]">
            <label class="text-xs text-slate-500 mb-1.5">تعداد در صفحه</label>
            <select name="per_page"
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @foreach($perPageOptions as $option)
                    <option value="{{ $option }}" {{ $perPage === $option ? 'selected' : '' }}>{{ $option }} تا</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit"
                    class="bg-brand-600 hover:bg-brand-700 transition-colors text-white font-medium rounded-lg px-5 py-2 text-sm shadow-sm">
                اعمال فیلتر
            </button>

            @if($search || $userIdSearch || $sort || $preset || $fromJalali || $toJalali || $planFilter || $woocommerceFilter || $purchaseFilter || $perPage !== 100)
                <a href="{{ route('admin.users.index') }}"
                   class="bg-slate-100 hover:bg-slate-200 transition-colors text-slate-700 font-medium rounded-lg px-4 py-2 text-sm">
                    حذف فیلترها
                </a>
            @endif
        </div>
    </form>
</section>

{{-- ===================== جدول کاربران (دسکتاپ) ===================== --}}
<section class="bg-white border border-slate-200 rounded-xl shadow-sm p-4 sm:p-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-4">
        <h2 class="text-base font-semibold text-slate-800">لیست کاربران</h2>
        <div class="text-xs text-slate-400">
            تعداد در این صفحه: {{ $users->count() }} از {{ number_format($users->total()) }}
        </div>
    </div>

    {{-- نسخه دسکتاپ: جدول --}}
    <div class="hidden md:block overflow-x-auto rounded-lg border border-slate-200">
        <table class="w-full min-w-[1300px] border-collapse text-sm">
            <thead>
                <tr class="bg-slate-50">
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">شناسه</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">نام کاربر</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">ایمیل / موبایل</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">تاریخ ثبت‌نام</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">آخرین ورود</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">پلن فعلی</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">مصرف کریدیت</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">خریدها</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">مقاله‌ها</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">سایت‌ها</th>
                    <th class="text-right font-medium text-xs text-slate-500 px-3 py-3 whitespace-nowrap">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($users as $user)
                <tr class="hover:bg-slate-50/70 transition-colors">
                    <td class="px-3 py-3 align-top">
                        <code class="text-xs bg-slate-100 text-slate-600 rounded px-1.5 py-0.5">#{{ $user->id }}</code>
                    </td>
                    <td class="px-3 py-3 align-top">
                        <a href="{{ route('admin.users.show', $user->id) }}"
                           class="text-brand-600 hover:text-brand-800 font-medium">
                            {{ $user->name ?? 'بدون نام' }}
                        </a>
                    </td>
                    <td class="px-3 py-3 align-top text-slate-600" dir="ltr">
                        <div>{{ $user->email ?? '-' }}</div>
                        @if($user->mobile ?? $user->phone ?? null)
                            <small class="text-slate-400">{{ $user->mobile ?? $user->phone }}</small>
                        @endif
                    </td>
                    <td class="px-3 py-3 align-top text-slate-600 whitespace-nowrap">
                        {{ $toJalaliFmt($user->created_at) }}
                    </td>
                    <td class="px-3 py-3 align-top text-slate-600 whitespace-nowrap">
                        @php($lastLogin = $lastLogins->get($user->id))
                        @if($lastLogin)
                            {{ $toJalaliFmt($lastLogin) }}
                        @else
                            <span class="text-xs text-slate-400">هرگز</span>
                        @endif
                    </td>
                    @php($sub = $activeSubscriptions->get($user->id))
                    <td class="px-3 py-3 align-top whitespace-nowrap">
                        {!! $planBadge($sub) !!}
                    </td>
                    <td class="px-3 py-3 align-top">
                        {!! $creditUsage($sub) !!}
                    </td>
                    <td class="px-3 py-3 align-top">
                        {!! $purchaseBadge($userPayments->get($user->id)) !!}
                    </td>
                    <td class="px-3 py-3 align-top text-slate-700 whitespace-nowrap">
                        {{ number_format($articleCounts->get($user->id, 0)) }}
                    </td>
                    <td class="px-3 py-3 align-top text-slate-700 whitespace-nowrap">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span>{{ number_format($siteCounts->get($user->id, 0)) }}</span>
                            {!! $wooBadge($wooSiteCounts->get($user->id, 0)) !!}
                        </div>
                    </td>
                    <td class="px-3 py-3 align-top">
                        <div class="flex items-center gap-2 flex-wrap">
                            <a href="{{ route('admin.users.show', $user->id) }}"
                               class="inline-flex items-center gap-1 text-xs text-slate-600 hover:text-slate-800 font-medium border border-slate-200 hover:bg-slate-50 px-2.5 py-1.5 rounded-lg transition whitespace-nowrap">
                                مشاهده
                            </a>
                            {!! $impersonateButton($user) !!}
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="px-3 py-8 text-center text-slate-400">کاربری با فیلترهای انتخابی یافت نشد.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- نسخه موبایل: کارت --}}
    <div class="md:hidden flex flex-col gap-3">
        @forelse($users as $user)
            <div class="border border-slate-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <div>
                        <a href="{{ route('admin.users.show', $user->id) }}"
                           class="text-brand-600 hover:text-brand-800 font-medium block">
                            {{ $user->name ?? 'بدون نام' }}
                        </a>
                        <code class="text-xs bg-slate-100 text-slate-600 rounded px-1.5 py-0.5 mt-1 inline-block">#{{ $user->id }}</code>
                    </div>
                    <span class="text-xs text-slate-400 whitespace-nowrap">{{ $toJalaliFmt($user->created_at) }}</span>
                </div>
                <div class="text-sm text-slate-600 mb-3" dir="ltr">
                    <div>{{ $user->email ?? '-' }}</div>
                    @if($user->mobile ?? $user->phone ?? null)
                        <div class="text-slate-400">{{ $user->mobile ?? $user->phone }}</div>
                    @endif
                </div>
                @php($lastLogin = $lastLogins->get($user->id))
                <div class="text-xs text-slate-500 mb-3">
                    آخرین ورود:
                    <span class="text-slate-700 font-medium">
                        {{ $lastLogin ? $toJalaliFmt($lastLogin) : 'هرگز' }}
                    </span>
                </div>
                @php($sub = $activeSubscriptions->get($user->id))
                <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
                    <div>{!! $planBadge($sub) !!}</div>
                    <div>{!! $creditUsage($sub) !!}</div>
                </div>
                <div class="mb-3">{!! $purchaseBadge($userPayments->get($user->id)) !!}</div>
                <div class="flex items-center gap-4 text-xs text-slate-600 mb-3 flex-wrap">
                    <span>مقاله‌ها: <b class="text-slate-800">{{ number_format($articleCounts->get($user->id, 0)) }}</b></span>
                    <span>سایت‌ها: <b class="text-slate-800">{{ number_format($siteCounts->get($user->id, 0)) }}</b></span>
                    {!! $wooBadge($wooSiteCounts->get($user->id, 0)) !!}
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="{{ route('admin.users.show', $user->id) }}"
                       class="inline-flex items-center gap-1 text-xs text-slate-600 hover:text-slate-800 font-medium border border-slate-200 hover:bg-slate-50 px-2.5 py-1.5 rounded-lg transition whitespace-nowrap">
                        مشاهده
                    </a>
                    {!! $impersonateButton($user) !!}
                </div>
            </div>
        @empty
            <div class="text-center text-slate-400 py-8">کاربری با فیلترهای انتخابی یافت نشد.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
</section>

@endsection
