@extends('layouts.admin')

@section('content')
<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6" dir="rtl">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">فعالیت‌های سیستم</h1>
        <p class="text-sm text-gray-500 mt-1">رخدادهای reconstruct‌شده از لاگ‌ها و timestampهای واقعی سیستم</p>
    </div>

    <form class="flex flex-wrap items-end gap-3 bg-white p-4 rounded-xl shadow-sm border border-gray-100" method="GET">
        <div class="flex flex-col">
            <label class="text-xs text-gray-500 mb-1">از تاریخ</label>
            <input type="date" name="from" value="{{ $from }}"
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>

        <div class="flex flex-col">
            <label class="text-xs text-gray-500 mb-1">تا تاریخ</label>
            <input type="date" name="to" value="{{ $to }}"
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>

        <div class="flex flex-col">
            <label class="text-xs text-gray-500 mb-1">کاربر</label>
            <select name="user_id"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400 min-w-[180px]">
                <option value="">همه کاربران</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" @selected($userId === $user->id)>
                        {{ $user->name }} — {{ $user->email }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="flex flex-col">
            <label class="text-xs text-gray-500 mb-1">نوع رویداد</label>
            <input name="type" value="{{ $type }}" placeholder="مثلاً payment_success"
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>

        <button type="submit"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
            فیلتر
        </button>
    </form>
</div>

<section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5" dir="rtl">
    <p class="text-sm text-gray-500 mb-4">
        تعداد رخدادهای نمایش‌داده‌شده: <span class="font-semibold text-gray-700">{{ number_format($activities->count()) }}</span>
    </p>

    <div class="flex flex-col gap-3">
        @forelse($activities as $event)
            <div class="border border-gray-100 rounded-lg p-4 hover:bg-gray-50 transition">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <strong class="text-gray-800">{{ $event['label'] }}</strong>
                    <small class="text-gray-400 text-xs">
                        {{ \Carbon\Carbon::parse($event['at'])->format('Y-m-d H:i:s') }}
                    </small>
                </div>

                <div class="text-sm text-gray-600 mt-2 leading-6">
                    {{ $event['subject'] ?? '—' }}

                    @if(isset($event['amount']))
                        <span class="mx-1">·</span> مبلغ: {{ number_format($event['amount']) }}
                    @endif

                    @if(isset($event['status']))
                        <span class="mx-1">·</span> وضعیت:
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $event['status'] === 'success' ? 'bg-green-100 text-green-700' :
                               ($event['status'] === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700') }}">
                            {{ $event['status'] }}
                        </span>
                    @endif

                    @if(isset($event['method']))
                        <span class="mx-1">·</span> روش ورود: {{ $event['method'] }}
                    @endif

                    @if(isset($event['model']))
                        <span class="mx-1">·</span> مدل: {{ $event['model'] }}
                    @endif

                    @if(isset($event['tokens']))
                        <span class="mx-1">·</span> توکن: {{ number_format($event['tokens']) }}
                    @endif

                    @if(isset($event['status_code']))
                        <span class="mx-1">·</span> HTTP:
                        <span class="font-mono text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">
                            {{ $event['status_code'] }}
                        </span>
                    @endif

                    <small class="text-gray-400 block mt-1">type={{ $event['type'] }}</small>
                </div>
            </div>
        @empty
            <p class="text-gray-400 text-center py-10">در بازه انتخاب‌شده فعالیتی پیدا نشد.</p>
        @endforelse
    </div>
</section>
@endsection
