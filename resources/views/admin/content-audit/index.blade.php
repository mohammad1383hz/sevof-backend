@extends('layouts.admin')

@section('content')
@php
    $jalali = fn ($date) => $date ? \Morilog\Jalali\Jalalian::fromCarbon($date->copy()->timezone('Asia/Tehran'))->format('Y/m/d') : '—';
    $percent = fn ($part, $total) => $total > 0 ? round($part * 100 / $total) : 0;
    $severity = ['high' => 'bg-rose-50 text-rose-700', 'medium' => 'bg-amber-50 text-amber-700'];
    $statusLabel = ['queued' => 'در صف', 'generating' => 'در حال نوشتن', 'ready' => 'آماده‌ی تأیید', 'applying' => 'در حال انتشار', 'applied' => 'منتشر شد', 'reverted' => 'برگردانده شد', 'discarded' => 'کنار گذاشته شد', 'failed' => 'ناموفق'];
    $reasonLabel = ['near_first_page' => 'نزدیک صفحه‌ی اول', 'declining' => 'افت کلیک', 'low_ctr' => 'رتبه‌ی خوب، کلیک کم', 'manual' => 'درخواست کاربر'];
    $kindLabel = ['youtix' => 'یوتیکس', 'product' => 'محصول', 'post' => 'پست سایت'];
    $filters = \App\Http\Controllers\Admin\AdminContentAuditController::FILTERS;
@endphp

<div class="max-w-full">
    <div class="mb-5">
        <h1 class="text-lg font-bold text-slate-800">گزارش سئوی مقاله‌های گذشته</h1>
        <p class="text-xs text-slate-500 mt-1 leading-6">
            پست‌هایی که خود کاربر روی سایتش نوشته (نه یوتیکس). لینک داخلی، سال‌های قدیمی، تازگی، طول و تیترها بدون AI بررسی می‌شود
            و آپدیت‌ها با لینک‌های داخلی تازه‌شان ثبت شده‌اند. کاربر فقط ایرادها را می‌بیند؛ این صفحه همه‌ی اعداد را دارد.
        </p>
    </div>

    @foreach(['success' => 'border-emerald-200 bg-emerald-50 text-emerald-700', 'error' => 'border-rose-200 bg-rose-50 text-rose-700'] as $key => $classes)
        @if(session($key))
            <div class="mb-4 rounded-lg border {{ $classes }} text-xs px-4 py-3">{{ session($key) }}</div>
        @endif
    @endforeach

    {{-- خلاصه‌ی کل --}}
    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3 mb-6">
        @foreach([
            ['کل مقاله‌ها', $totals['posts'], 'text-slate-800'],
            ['بررسی‌شده', $totals['audited'].' ('.$percent($totals['audited'], $totals['posts']).'٪)', 'text-slate-700'],
            ['نیاز به آپدیت', $totals['needs_update'], 'text-rose-600'],
            ['بدون لینک داخلی', $totals['no_links'], 'text-rose-600'],
            ['آپدیت منتشرشده', $totals['applied'], 'text-emerald-600'],
            ['لینک داخلی تازه', $totals['new_links'], 'text-brand-700'],
        ] as [$label, $value, $color])
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-[11px] text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold {{ $color }}">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- سایت‌ها --}}
    <h2 class="text-sm font-bold text-slate-700 mb-3">سایت‌ها</h2>
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">سایت</th>
                        <th class="px-4 py-3 font-medium">مقاله</th>
                        <th class="px-4 py-3 font-medium">بررسی‌شده</th>
                        <th class="px-4 py-3 font-medium">نیاز به آپدیت</th>
                        <th class="px-4 py-3 font-medium">بدون لینک / لینک کم</th>
                        <th class="px-4 py-3 font-medium">میانگین لینک داخلی</th>
                        <th class="px-4 py-3 font-medium">میانگین کلمه</th>
                        <th class="px-4 py-3 font-medium">سال قدیمی</th>
                        <th class="px-4 py-3 font-medium">کهنه</th>
                        <th class="px-4 py-3 font-medium">آپدیت منتشرشده</th>
                        <th class="px-4 py-3 font-medium">لینک تازه</th>
                        <th class="px-4 py-3 font-medium">کریدیت</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($sites as $s)
                        <tr class="{{ $selectedSite && $selectedSite->id === $s['site']->id ? 'bg-brand-50/50' : 'hover:bg-slate-50' }}">
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-800" dir="ltr">{{ $s['site']->domain }}</span>
                                <span class="block text-[11px] text-slate-400">{{ $s['site']->user?->name ?? $s['site']->user?->email }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $s['posts'] }}</td>
                            <td class="px-4 py-3">{{ $s['audited'] }} <span class="text-slate-400">({{ $percent($s['audited'], $s['posts']) }}٪)</span></td>
                            <td class="px-4 py-3 font-bold text-rose-600">{{ $s['needs_update'] }}</td>
                            <td class="px-4 py-3">{{ $s['no_links'] }} / {{ $s['weak_links'] }}</td>
                            <td class="px-4 py-3">{{ $s['avg_links'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $s['avg_words'] ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $s['outdated'] }}</td>
                            <td class="px-4 py-3">{{ $s['stale'] }}</td>
                            <td class="px-4 py-3 text-emerald-700">{{ $s['applied'] }}</td>
                            <td class="px-4 py-3 text-brand-700">{{ $s['new_links'] }}</td>
                            <td class="px-4 py-3">{{ round($s['credit'], 2) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('admin.content-audit.index', ['site_id' => $s['site']->id]) }}#articles" class="text-brand-600 hover:text-brand-800 font-medium">گزارش مقاله‌ها</a>
                                <form method="POST" action="{{ route('admin.content-audit.audit', $s['site']) }}" class="inline mr-2"
                                      onsubmit="return confirm('همه‌ی مقاله‌های این سایت دوباره خوانده و بررسی شوند؟')">
                                    @csrf
                                    <button type="submit" class="text-slate-500 hover:text-slate-800">بررسی دوباره</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="13" class="px-4 py-8 text-center text-slate-400">هنوز هیچ سایتی مقاله‌ی گذشته‌ای ندارد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- مقاله‌های سایت انتخاب‌شده --}}
    @if($selectedSite)
        <div id="articles" class="flex flex-wrap items-end justify-between gap-3 mb-3">
            <h2 class="text-sm font-bold text-slate-700">مقاله‌های <span dir="ltr">{{ $selectedSite->domain }}</span> ({{ $articles->total() }})</h2>
            <form method="GET" action="{{ route('admin.content-audit.index') }}" class="flex flex-wrap gap-2">
                <input type="hidden" name="site_id" value="{{ $selectedSite->id }}">
                @if($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
                <input type="search" name="q" value="{{ $search }}" placeholder="جستجو در عنوان…" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs">
                <button class="rounded-lg bg-slate-800 text-white text-xs px-3 py-1.5">جستجو</button>
            </form>
        </div>

        <div class="flex flex-wrap gap-1.5 mb-3">
            <a href="{{ route('admin.content-audit.index', ['site_id' => $selectedSite->id, 'q' => $search ?: null]) }}#articles"
               class="rounded-full px-3 py-1 text-[11px] {{ $filter === null ? 'bg-brand-600 text-white' : 'bg-white border border-slate-200 text-slate-600' }}">همه</a>
            @foreach($filters as $key => $label)
                <a href="{{ route('admin.content-audit.index', ['site_id' => $selectedSite->id, 'filter' => $key, 'q' => $search ?: null]) }}#articles"
                   class="rounded-full px-3 py-1 text-[11px] {{ $filter === $key ? 'bg-brand-600 text-white' : 'bg-white border border-slate-200 text-slate-600' }}">
                    {{ $label }} ({{ $issueCounts[$key] ?? 0 }})
                </a>
            @endforeach
        </div>

        <div class="space-y-2 mb-4">
            @forelse($articles as $row)
                @php($post = $row['post'])
                @php($audit = $row['audit'])
                <details class="bg-white rounded-xl border border-slate-200 {{ $row['needs_update'] ? 'border-r-4 border-r-rose-400' : '' }}">
                    <summary class="cursor-pointer list-none px-4 py-3">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800">{{ $post->title }}</p>
                                <p class="mt-1 text-[11px] text-slate-500 leading-6">
                                    انتشار {{ $jalali($post->published_at) }} · آخرین ویرایش {{ $jalali($post->modified_at) }}
                                    · {{ $audit['words'] ?? $post->word_count ?? '—' }} کلمه
                                    · لینک داخلی: <strong class="{{ $audit && ($audit['internal_links'] ?? 0) < ($row['recommended_links'] ?? 0) ? 'text-rose-600' : 'text-slate-700' }}">{{ $audit ? ($audit['internal_links'] ?? 0) : 'نامعلوم' }}</strong>@if($audit) از {{ $row['recommended_links'] }} پیشنهادی@endif
                                    · لینک بیرونی: {{ $audit['external_links'] ?? '—' }}
                                    · تیتر: {{ $audit['headings'] ?? '—' }}
                                    @if($row['gsc'])
                                        · {{ $row['gsc']['clicks'] }} کلیک · {{ $row['gsc']['impressions'] }} نمایش · رتبه {{ $row['gsc']['position'] ?? '—' }}
                                    @endif
                                </p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if($row['reason'])
                                        <span class="rounded-full px-2 py-0.5 text-[10px] bg-sky-50 text-sky-700">{{ $reasonLabel[$row['reason']] ?? $row['reason'] }}</span>
                                    @endif
                                    @foreach($row['issues'] as $issue)
                                        <span class="rounded-full px-2 py-0.5 text-[10px] {{ $severity[$issue['severity']] }}">{{ $issue['label'] }}</span>
                                    @endforeach
                                    @if(! $audit)
                                        <span class="rounded-full px-2 py-0.5 text-[10px] bg-slate-100 text-slate-500">متن هنوز بررسی نشده</span>
                                    @endif
                                    @if(! $post->editable)
                                        <span class="rounded-full px-2 py-0.5 text-[10px] bg-slate-100 text-slate-500">غیرقابل ویرایش ({{ $post->not_editable_reason }})</span>
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-left text-[11px] text-slate-500">
                                @if($last = $row['refreshes']->first())
                                    <span class="block">آخرین آپدیت: {{ $statusLabel[$last->status] ?? $last->status }}</span>
                                    <span class="block">{{ $row['refreshes']->count() }} آپدیت · {{ count($last->after['links'] ?? []) }} لینک تازه</span>
                                @else
                                    <span>آپدیت نشده</span>
                                @endif
                            </div>
                        </div>
                    </summary>

                    <div class="border-t border-slate-100 px-4 py-3 grid gap-4 lg:grid-cols-3 text-[11px] leading-6 text-slate-600">
                        <div>
                            <p class="font-bold text-slate-700 mb-1">ایرادها</p>
                            @forelse($row['issues'] as $issue)
                                <p><span class="font-medium">{{ $issue['label'] }}:</span> {{ $issue['detail'] }}</p>
                            @empty
                                <p class="text-emerald-600">ایرادی پیدا نشد.</p>
                            @endforelse
                            @if(! empty($audit['outdated_years']))
                                <p class="mt-1">سال‌های قدیمی در متن: {{ implode('، ', $audit['outdated_years']) }}</p>
                            @endif
                            <p class="mt-1 text-slate-400">بررسی متن: {{ $post->audited_at ? $jalali($post->audited_at) : 'هنوز نه' }} · <a href="{{ $post->url }}" target="_blank" rel="noopener" class="text-brand-600" dir="ltr">مشاهده در سایت</a></p>
                        </div>

                        <div>
                            <p class="font-bold text-slate-700 mb-1">لینک‌های داخلی فعلی ({{ count($audit['internal_urls'] ?? []) }})</p>
                            @forelse(array_slice($audit['internal_urls'] ?? [], 0, 30) as $url)
                                <p class="truncate" dir="ltr"><a href="{{ $url }}" target="_blank" rel="noopener" class="hover:text-brand-600">{{ $url }}</a></p>
                            @empty
                                <p class="text-slate-400">{{ $audit ? 'هیچ لینک داخلی ندارد.' : 'نامعلوم' }}</p>
                            @endforelse
                        </div>

                        <div>
                            <p class="font-bold text-slate-700 mb-1">تاریخچه‌ی آپدیت</p>
                            @forelse($row['refreshes'] as $r)
                                <div class="mb-2 rounded-lg bg-slate-50 p-2">
                                    <p>
                                        <span class="font-medium">{{ $jalali($r->created_at) }}</span>
                                        · {{ $statusLabel[$r->status] ?? $r->status }}
                                        · {{ $reasonLabel[$r->reason] ?? $r->reason }}
                                        · {{ $r->trigger === 'agent' ? 'خودکار' : 'دستی' }}
                                        · {{ round((float) $r->credit_used, 2) }} کریدیت
                                        · {{ count($r->after['sections'] ?? []) }} بخش تغییر
                                    </p>
                                    @if($r->summary)<p class="text-slate-500">{{ $r->summary }}</p>@endif
                                    @if($r->error)<p class="text-rose-600">{{ $r->error }}</p>@endif
                                    @foreach($r->after['links'] ?? [] as $link)
                                        <p>
                                            ← «{{ $link['anchor'] }}»
                                            <a href="{{ $link['url'] }}" target="_blank" rel="noopener" class="text-brand-600">{{ $link['title'] }}</a>
                                            <span class="text-slate-400">({{ $kindLabel[$link['kind']] ?? $link['kind'] }}، {{ $link['section'] ?? '' }})</span>
                                        </p>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-slate-400">هنوز آپدیت نشده.</p>
                            @endforelse
                        </div>
                    </div>
                </details>
            @empty
                <div class="bg-white rounded-xl border border-slate-200 px-4 py-8 text-center text-xs text-slate-400">مقاله‌ای با این فیلتر نیست.</div>
            @endforelse
        </div>

        {{ $articles->fragment('articles')->links() }}
    @endif
</div>
@endsection
