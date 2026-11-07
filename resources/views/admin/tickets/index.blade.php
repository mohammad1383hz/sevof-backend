@extends('layouts.admin')

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
    <div>
        <h1 class="text-lg sm:text-xl font-bold text-gray-800">تیکت‌های پشتیبانی</h1>
        <div class="text-xs sm:text-sm text-gray-500 mt-1">مدیریت و پاسخ‌گویی به تیکت‌های کاربران</div>
    </div>
    <form class="flex items-end gap-2 w-full sm:w-auto" method="GET">
        <div class="flex flex-col gap-1 w-full sm:w-auto">
            <label class="text-xs font-medium text-gray-500">وضعیت</label>
            <select name="status" onchange="this.form.submit()"
                    class="rounded-lg border-gray-300 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 w-full sm:min-w-[180px]">
                <option value="">همه</option>
                <option value="open" @selected($status === 'open')>در انتظار پاسخ ادمین</option>
                <option value="answered" @selected($status === 'answered')>پاسخ داده شده</option>
                <option value="closed" @selected($status === 'closed')>بسته شده</option>
            </select>
        </div>
    </form>
</div>

<section class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 sm:p-5">
    <p class="text-xs sm:text-sm text-gray-500 mb-4">تعداد تیکت‌ها: {{ number_format($tickets->total()) }}</p>

    {{-- نمای جدول برای دسکتاپ/تبلت --}}
    <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm text-right">
            <thead>
                <tr class="border-b border-gray-100 text-gray-500 text-xs">
                    <th class="py-2 px-3 font-medium">موضوع</th>
                    <th class="py-2 px-3 font-medium">کاربر</th>
                    <th class="py-2 px-3 font-medium">اولویت</th>
                    <th class="py-2 px-3 font-medium">وضعیت</th>
                    <th class="py-2 px-3 font-medium">آخرین پیام</th>
                    <th class="py-2 px-3 font-medium"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            @forelse($tickets as $ticket)
                <tr class="hover:bg-gray-50">
                    <td class="py-2.5 px-3 text-gray-700 font-medium max-w-[220px] truncate">{{ $ticket->subject }}</td>
                    <td class="py-2.5 px-3">
                        <div class="text-gray-700">{{ $ticket->user->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $ticket->user->email ?? '' }}</div>
                    </td>
                    <td class="py-2.5 px-3 text-gray-700">{{ $ticket->priority }}</td>
                    <td class="py-2.5 px-3">
                        @if($ticket->status === 'open')
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 whitespace-nowrap">در انتظار پاسخ ادمین</span>
                        @elseif($ticket->status === 'answered')
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 whitespace-nowrap">پاسخ داده شده</span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 whitespace-nowrap">بسته شده</span>
                        @endif
                    </td>
                    <td class="py-2.5 px-3 text-gray-500 text-xs whitespace-nowrap">{{ optional($ticket->last_reply_at)->format('Y-m-d H:i') }}</td>
                    <td class="py-2.5 px-3">
                        <a href="{{ route('admin.tickets.show', $ticket) }}" class="text-indigo-600 hover:text-indigo-800 font-medium whitespace-nowrap">مشاهده و پاسخ</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-6 text-center text-gray-400">تیکتی یافت نشد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- نمای کارتی برای موبایل --}}
    <div class="md:hidden flex flex-col gap-3">
        @forelse($tickets as $ticket)
            <a href="{{ route('admin.tickets.show', $ticket) }}"
               class="block rounded-xl border border-gray-100 p-3.5 active:bg-gray-50">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <span class="text-sm font-medium text-gray-800 line-clamp-2">{{ $ticket->subject }}</span>
                    @if($ticket->status === 'open')
                        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-100 text-amber-700 whitespace-nowrap">در انتظار</span>
                    @elseif($ticket->status === 'answered')
                        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-100 text-green-700 whitespace-nowrap">پاسخ داده شده</span>
                    @else
                        <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500 whitespace-nowrap">بسته شده</span>
                    @endif
                </div>
                <div class="flex items-center justify-between text-xs text-gray-500">
                    <div>
                        <div class="text-gray-700">{{ $ticket->user->name ?? '—' }}</div>
                        <div class="text-gray-400">{{ $ticket->user->email ?? '' }}</div>
                    </div>
                    <div class="text-left">
                        <div>اولویت: {{ $ticket->priority }}</div>
                        <div class="text-gray-400">{{ optional($ticket->last_reply_at)->format('Y-m-d H:i') }}</div>
                    </div>
                </div>
            </a>
        @empty
            <div class="py-6 text-center text-gray-400 text-sm">تیکتی یافت نشد.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $tickets->links() }}</div>
</section>
@endsection
