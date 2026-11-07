@extends('layouts.admin')

@section('content')
@php
    $jalali = fn ($date) => $date ? \Morilog\Jalali\Jalalian::fromCarbon($date->copy()->timezone('Asia/Tehran'))->format('Y/m/d H:i') : '—';
    $percent = fn ($part, $total) => $total > 0 ? round($part * 100 / $total) : 0;
    $statusFilters = [
        'indexed' => 'ایندکس‌شده',
        'not_indexed' => 'ایندکس‌نشده',
        'unchecked' => 'بررسی‌نشده',
        'error' => 'خطا',
    ];
@endphp

<div class="max-w-full">
    <div class="flex items-center justify-between mb-5">
        <div>
            <h1 class="text-lg font-bold text-slate-800">وضعیت ایندکس مقاله‌ها</h1>
            <p class="text-xs text-slate-500 mt-1 leading-6">
                فقط مقاله‌هایی که <strong class="text-slate-700">یوتیکس نوشته و منتشر کرده</strong> (مقاله و مقاله‌ی محصول) شمرده می‌شوند؛ پست‌هایی که خود کاربر در سایتش نوشته حساب نمی‌شوند.
                هر روز ساعت ۵:۳۰ خودکار بررسی می‌شود. این صفحه به کاربران نشان داده نمی‌شود.
            </p>
        </div>
        <form method="POST" action="{{ route('admin.indexing.check-all') }}" class="shrink-0"
              onsubmit="return confirm('همه‌ی سایت‌های متصل به سرچ کنسول الان بررسی شوند؟')">
            @csrf
            <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-xs font-bold hover:bg-brand-700 whitespace-nowrap">بررسی همه‌ی سایت‌ها</button>
        </form>
    </div>

    @foreach(['success' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'error' => 'border-rose-200 bg-rose-50 text-rose-700'] as $key => $classes)
        @if(session($key))
            <div class="mb-4 rounded-lg border {{ $classes }} text-xs px-4 py-3">{{ session($key) }}</div>
        @endif
    @endforeach

    {{-- خلاصه --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
        @foreach([
            ['کل مقاله‌ها', $totals['total'], 'text-slate-800'],
            ['ایندکس‌شده', $totals['indexed'].' ('.$percent($totals['indexed'], $totals['total']).'٪)', 'text-emerald-600'],
            ['ایندکس‌نشده', $totals['not_indexed'], 'text-rose-600'],
            ['بررسی‌نشده', $totals['unchecked'], 'text-slate-500'],
            ['میانگین روز تا ایندکس', $avgDays !== null ? round($avgDays, 1) : '—', 'text-brand-700'],
        ] as [$label, $value, $color])
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold {{ $color }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- سایت‌ها --}}
    <h2 class="text-sm font-bold text-slate-700 mb-3">سایت‌های متصل به سرچ کنسول</h2>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">سایت</th>
                        <th class="px-4 py-3 font-medium">کاربر</th>
                        <th class="px-4 py-3 font-medium">Property</th>
                        <th class="px-4 py-3 font-medium">مقاله‌ها</th>
                        <th class="px-4 py-3 font-medium">ایندکس‌شده</th>
                        <th class="px-4 py-3 font-medium">ایندکس‌نشده</th>
                        <th class="px-4 py-3 font-medium">بررسی‌نشده / خطا</th>
                        <th class="px-4 py-3 font-medium">آخرین بررسی</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($properties as $property)
                        @php $row = $bySite[$property->site_id] ?? null; $total = (int) ($row->total ?? 0); @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.indexing.index', ['site_id' => $property->site_id]) }}" class="font-medium text-brand-700 hover:underline" dir="ltr">{{ $property->site->domain }}</a>
                                @if($property->connection?->status !== 'connected')
                                    <span class="mr-1 px-1.5 py-0.5 rounded bg-rose-50 text-rose-600 text-[10px]">اتصال قطع</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                @if($property->site->user)
                                    <a href="{{ route('admin.users.show', $property->site->user->id) }}" class="hover:underline">{{ $property->site->user->name ?? $property->site->user->email }}</a>
                                @else — @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $property->property_url }}</td>
                            <td class="px-4 py-3 font-medium">{{ $total }}</td>
                            <td class="px-4 py-3 text-emerald-600">{{ (int) ($row->indexed ?? 0) }} <span class="text-slate-400">({{ $percent((int) ($row->indexed ?? 0), $total) }}٪)</span></td>
                            <td class="px-4 py-3 text-rose-600">{{ (int) ($row->not_indexed ?? 0) }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ (int) ($row->unchecked ?? 0) }} / {{ (int) ($row->errors ?? 0) }}</td>
                            <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $row?->last_checked_at ? $jalali(\Carbon\Carbon::parse($row->last_checked_at)) : '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.indexing.index', ['site_id' => $property->site_id]) }}#articles"
                                       class="px-3 py-1.5 rounded-lg bg-slate-800 text-white hover:bg-slate-700 whitespace-nowrap">مشاهده‌ی مقاله‌ها</a>
                                    <form method="POST" action="{{ route('admin.indexing.check', $property->site_id) }}">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 rounded-lg border border-brand-200 text-brand-700 bg-brand-50 hover:bg-brand-100 whitespace-nowrap">بررسی الان</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400">هنوز سایتی به سرچ کنسول وصل نشده است.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- خلاصه‌ی سایت انتخاب‌شده: هر عدد فیلتر همان وضعیت است --}}
    @if($selectedSite)
        @php $site = $bySite[$selectedSite->site_id] ?? null; $siteTotal = (int) ($site->total ?? 0); @endphp
        <div id="articles" class="bg-white rounded-xl border border-slate-200 p-4 mb-4 scroll-mt-20">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h2 class="text-sm font-bold text-slate-800">مقاله‌های یوتیکس در <span dir="ltr">{{ $selectedSite->site->domain }}</span></h2>
                <a href="{{ route('admin.indexing.index') }}" class="text-xs text-slate-500 hover:text-brand-600">همه‌ی سایت‌ها ←</a>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                @foreach([
                    [null, 'همه', $siteTotal, 'text-slate-800'],
                    ['indexed', 'ایندکس‌شده', (int) ($site->indexed ?? 0), 'text-emerald-600'],
                    ['not_indexed', 'ایندکس‌نشده', (int) ($site->not_indexed ?? 0), 'text-rose-600'],
                    ['unchecked', 'بررسی‌نشده', (int) ($site->unchecked ?? 0), 'text-slate-500'],
                    ['error', 'خطا', (int) ($site->errors ?? 0), 'text-amber-600'],
                ] as [$key, $label, $count, $color])
                    <a href="{{ route('admin.indexing.index', array_filter(['site_id' => $selectedSite->site_id, 'status' => $key])) }}#articles"
                       class="rounded-lg border px-3 py-2 transition {{ $status === $key ? 'border-slate-800 bg-slate-50' : 'border-slate-200 hover:border-slate-400' }}">
                        <span class="block text-[11px] text-slate-500">{{ $label }}</span>
                        <span class="block text-lg font-bold {{ $color }}">{{ $count }}
                            @if($key === 'indexed')<span class="text-xs font-normal text-slate-400">({{ $percent($count, $siteTotal) }}٪)</span>@endif
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- دلیل‌های ایندکس‌نشدن --}}
    @if($reasons->isNotEmpty())
        <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
            <h2 class="text-sm font-bold text-slate-700 mb-3">چرا ایندکس نشده‌اند؟ {{ $selectedSite ? '' : '(همه‌ی سایت‌ها)' }}</h2>
            <div class="space-y-2">
                @foreach($reasons as $reason)
                    <div class="flex items-start gap-3 rounded-lg bg-slate-50 px-3 py-2.5">
                        <span class="shrink-0 min-w-9 text-center rounded-md bg-rose-100 text-rose-700 font-bold text-xs px-2 py-1">{{ $reason['count'] }}</span>
                        <div class="text-xs leading-6">
                            <p class="font-bold text-slate-800">{{ $reason['title'] }}</p>
                            <p class="text-slate-600">{{ $reason['fix'] }}</p>
                            @if($reason['new'] > 0)
                                <p class="text-[11px] text-slate-400">{{ $reason['new'] }} مورد کمتر از {{ \App\Models\ArticleIndexCheck::NEW_ARTICLE_DAYS }} روز پیش منتشر شده‌اند؛ ایندکس معمولاً ۱ تا ۲ هفته طول می‌کشد.</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- مقاله‌ها --}}
    <div @unless($selectedSite) id="articles" @endunless class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3 scroll-mt-20">
        <h2 class="text-sm font-bold text-slate-700">مقاله‌ها</h2>
        <form method="GET" class="flex flex-wrap gap-2">
            <select name="site_id" onchange="this.form.submit()" class="border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white">
                <option value="">همه‌ی سایت‌ها</option>
                @foreach($properties as $property)
                    <option value="{{ $property->site_id }}" @selected($siteId === $property->site_id)>{{ $property->site->domain }}</option>
                @endforeach
            </select>
            <select name="status" onchange="this.form.submit()" class="border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white">
                <option value="">همه‌ی وضعیت‌ها</option>
                @foreach($statusFilters as $key => $label)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @if($siteId || $status)
                <a href="{{ route('admin.indexing.index') }}" class="text-xs text-slate-500 hover:text-brand-600 self-center">پاک کردن فیلتر</a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">مقاله</th>
                        <th class="px-4 py-3 font-medium">سایت</th>
                        <th class="px-4 py-3 font-medium">وضعیت</th>
                        <th class="px-4 py-3 font-medium">جزئیات گوگل</th>
                        <th class="px-4 py-3 font-medium">انتشار</th>
                        <th class="px-4 py-3 font-medium">آخرین خزش گوگل</th>
                        <th class="px-4 py-3 font-medium">روز تا ایندکس</th>
                        <th class="px-4 py-3 font-medium">آخرین بررسی</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($checks as $check)
                        <tr class="hover:bg-slate-50 align-top">
                            <td class="px-4 py-3 max-w-[280px]">
                                <a href="{{ $check->url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-slate-800 hover:text-brand-700 line-clamp-2">{{ $check->title }}</a>
                                <span class="text-[10px] text-slate-400">{{ $check->subject_type === 'product_article' ? 'مقاله‌ی محصول' : 'مقاله' }} #{{ $check->subject_id }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-500" dir="ltr">{{ $check->site?->domain }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($check->error && $check->is_indexed === null)
                                    <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">خطا</span>
                                @elseif($check->is_indexed === true)
                                    <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">ایندکس‌شده</span>
                                @elseif($check->is_indexed === false)
                                    <span class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-200">ایندکس‌نشده</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">بررسی‌نشده</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 max-w-[320px] leading-5">
                                @if($reason = $check->reason())
                                    <p class="font-bold text-slate-800">{{ $reason['title'] }}</p>
                                    <p class="text-[11px] text-slate-500 mt-0.5">{{ $reason['fix'] }}</p>
                                    @if($reason['is_new'])
                                        <p class="text-[10px] text-slate-400 mt-0.5">تازه منتشر شده؛ شاید فقط منتظر نوبت گوگل است.</p>
                                    @endif
                                @else
                                    {{ $check->coverageLabel() ?? '—' }}
                                @endif
                                @if($check->error)
                                    <div class="text-[10px] text-amber-700 mt-1" dir="auto">{{ \Illuminate\Support\Str::limit($check->error, 160) }}</div>
                                @endif
                                @if($check->inspection_link)
                                    <a href="{{ $check->inspection_link }}" target="_blank" rel="noopener noreferrer" class="block text-[10px] text-brand-600 hover:underline mt-1">مشاهده در سرچ کنسول</a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 whitespace-nowrap" dir="ltr">{{ $jalali($check->published_at) }}</td>
                            <td class="px-4 py-3 text-slate-500 whitespace-nowrap" dir="ltr">{{ $jalali($check->last_crawl_at) }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $check->first_indexed_at && $check->published_at ? max(0, $check->published_at->diffInDays($check->first_indexed_at)) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-slate-500 whitespace-nowrap" dir="ltr">{{ $jalali($check->checked_at) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">مقاله‌ای برای نمایش نیست. اگر سایتی تازه وصل شده، «بررسی الان» را بزنید.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-5">{{ $checks->links() }}</div>
</div>
@endsection
