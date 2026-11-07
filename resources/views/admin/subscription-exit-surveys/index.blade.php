@extends('layouts.admin')

@section('content')
@php
    // نگاشت فارسی دلایل و تریگرها (فقط برای نمایش، مقدار دیتابیس تغییر نمی‌کند)
    $reasonLabels = [
        'price'   => 'قیمت',
        'quality' => 'کیفیت',
        'other'   => 'سایر',
    ];

    $triggerLabels = [
        'back_button' => 'دکمه بازگشت',
    ];
@endphp

<div class="max-w-full">

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-slate-800">نظرسنجی خروج از اشتراک</h1>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs px-4 py-3">
            {{ session('success') }}
        </div>
    @endif

    {{-- فیلترها --}}
    <form method="GET" class="flex flex-col sm:flex-row sm:flex-wrap gap-3 mb-5">
        <select name="reason_key" onchange="this.form.submit()"
                class="w-full sm:w-auto border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
            <option value="">همه دلایل</option>
            @foreach($reasonLabels as $key => $label)
                <option value="{{ $key }}" @selected($reasonKey === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="trigger_type" onchange="this.form.submit()"
                class="w-full sm:w-auto border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
            <option value="">همه تریگرها</option>
            @foreach($triggerLabels as $key => $label)
                <option value="{{ $key }}" @selected($triggerType === $key)>{{ $label }}</option>
            @endforeach
        </select>

        @if($reasonKey || $triggerType)
            <a href="{{ route('admin.subscription-exit-surveys.index') }}"
               class="text-xs text-slate-500 hover:text-brand-600 flex items-center">پاک کردن فیلتر</a>
        @endif
    </form>

    {{-- جدول: دسکتاپ --}}
    <div class="hidden lg:block bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">#</th>
                        <th class="px-4 py-3 font-medium">کاربر</th>
                        <th class="px-4 py-3 font-medium">ایمیل</th>
                        <th class="px-4 py-3 font-medium">شماره موبایل</th>
                        <th class="px-4 py-3 font-medium">نوع تریگر</th>
                        <th class="px-4 py-3 font-medium">دلیل</th>
                        <th class="px-4 py-3 font-medium">متن دلیل</th>
                        <th class="px-4 py-3 font-medium">صفحه</th>
                        <th class="px-4 py-3 font-medium">تاریخ</th>
                        <th class="px-4 py-3 font-medium">ورود به حساب</th>
                        <th class="px-4 py-3 font-medium">تخفیف</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($surveys as $survey)
                        <tr class="hover:bg-slate-50 transition-colors align-top">
                            <td class="px-4 py-3 text-slate-500">{{ $survey->id }}</td>
                            <td class="px-4 py-3">
                                @if($survey->user)
                                    <a href="{{ route('admin.users.show', $survey->user->id) }}"
                                       class="text-brand-700 hover:underline font-medium">
                                        {{ $survey->user->name ?? '—' }}
                                    </a>
                                    <div class="text-slate-400 text-[11px]">#{{ $survey->user->id }}</div>
                                @else
                                    <span class="text-slate-400">کاربر حذف شده (#{{ $survey->user_id }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap" dir="ltr">
                                {{ $survey->user->email ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap" dir="ltr">
                                {{ $survey->user->phone ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $triggerLabels[$survey->trigger_type] ?? $survey->trigger_type }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[11px]">
                                    {{ $reasonLabels[$survey->reason_key] ?? $survey->reason_key }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 max-w-[180px] truncate" title="{{ $survey->reason_text }}">
                                {{ $survey->reason_text ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-600" dir="ltr">{{ $survey->page }}</td>
                          <td class="px-4 py-3 text-slate-500 whitespace-nowrap" dir="ltr">
    {{ \Morilog\Jalali\Jalalian::fromCarbon($survey->created_at->copy()->timezone('Asia/Tehran'))->format('Y/m/d H:i') }}
</td>
                            <td class="px-4 py-3">
                                @if($survey->user)
                                    @php $ticketUser = $survey->user; @endphp
                                    <form action="{{ route('admin.users.impersonate', $ticketUser->id) }}" method="POST" target="_blank"
                                          onsubmit="return confirm('وارد حساب کاربر #{{ $ticketUser->id }} ({{ e($ticketUser->name) }}) بشی؟');">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border border-emerald-200 text-emerald-600 bg-emerald-50 hover:bg-emerald-100 transition whitespace-nowrap">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                                <polyline points="10 17 15 12 10 7"/>
                                                <line x1="15" y1="12" x2="3" y2="12"/>
                                            </svg>
                                            ورود به حساب کاربر
                                        </button>
                                    </form>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 min-w-[220px]">
                                @if($survey->user)
                                    @include('admin.subscription-exit-surveys._discount-form', ['user' => $survey->user])
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-4 py-8 text-center text-slate-400">
                                موردی یافت نشد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- کارت‌ها: موبایل و تبلت --}}
    <div class="lg:hidden space-y-3">
        @forelse($surveys as $survey)
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-xs">
                <div class="flex items-start justify-between mb-2">
                    <div>
                        @if($survey->user)
                            <a href="{{ route('admin.users.show', $survey->user->id) }}"
                               class="text-brand-700 font-medium">
                                {{ $survey->user->name ?? '—' }}
                            </a>
                            <div class="text-slate-400 text-[11px]">#{{ $survey->user->id }}</div>
                        @else
                            <span class="text-slate-400">کاربر حذف شده (#{{ $survey->user_id }})</span>
                        @endif
                    </div>
                    <span class="text-slate-400">#{{ $survey->id }}</span>
                </div>

                <dl class="grid grid-cols-2 gap-y-1.5 gap-x-2 text-slate-600 mb-3">
                    <dt class="text-slate-400">ایمیل</dt>
                    <dd dir="ltr" class="text-left break-all">{{ $survey->user->email ?? '—' }}</dd>

                    <dt class="text-slate-400">موبایل</dt>
                    <dd dir="ltr" class="text-left">{{ $survey->user->phone ?? '—' }}</dd>

                    <dt class="text-slate-400">تریگر</dt>
                    <dd>{{ $triggerLabels[$survey->trigger_type] ?? $survey->trigger_type }}</dd>

                    <dt class="text-slate-400">دلیل</dt>
                    <dd>
                        <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[11px]">
                            {{ $reasonLabels[$survey->reason_key] ?? $survey->reason_key }}
                        </span>
                    </dd>

                    @if($survey->reason_text)
                        <dt class="text-slate-400">متن دلیل</dt>
                        <dd class="break-words">{{ $survey->reason_text }}</dd>
                    @endif

                    <dt class="text-slate-400">صفحه</dt>
                    <dd dir="ltr" class="text-left break-all">{{ $survey->page }}</dd>

                   <dt class="text-slate-400">تاریخ</dt>
<dd dir="ltr" class="text-right">
    {{ \Morilog\Jalali\Jalalian::fromCarbon($survey->created_at->copy()->timezone('Asia/Tehran'))->format('Y/m/d H:i') }}
</dd>
                </dl>

                @if($survey->user)
                    @php $ticketUser = $survey->user; @endphp
                    <form action="{{ route('admin.users.impersonate', $ticketUser->id) }}" method="POST" target="_blank"
                          class="mb-3"
                          onsubmit="return confirm('وارد حساب کاربر #{{ $ticketUser->id }} ({{ e($ticketUser->name) }}) بشی؟');">
                        @csrf
                        <button type="submit"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium border border-emerald-200 text-emerald-600 bg-emerald-50 hover:bg-emerald-100 transition whitespace-nowrap">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                                <polyline points="10 17 15 12 10 7"/>
                                <line x1="15" y1="12" x2="3" y2="12"/>
                            </svg>
                            ورود به حساب کاربر
                        </button>
                    </form>

                    @include('admin.subscription-exit-surveys._discount-form', ['user' => $survey->user])
                @endif
            </div>
        @empty
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-slate-400 text-xs">
                موردی یافت نشد.
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $surveys->links() }}
    </div>

</div>
@endsection
