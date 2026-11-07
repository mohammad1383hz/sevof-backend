@extends('layouts.admin')

@section('content')
@php
    $statusLabels = [
        'pending'  => ['در انتظار بررسی', 'bg-amber-50 text-amber-700 border-amber-200'],
        'approved' => ['تأیید شده', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
        'rejected' => ['رد شده', 'bg-slate-100 text-slate-500 border-slate-200'],
    ];
    $channelLabels = \App\Models\ReferralRequest::CHANNELS;
    $audienceLabels = \App\Models\ReferralRequest::AUDIENCE_SIZES;
@endphp

<div class="max-w-full">
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-slate-800">درخواست‌های کسب درآمد با معرفی</h1>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs px-4 py-3">
            {{ session('success') }}
        </div>
    @endif

    {{-- فیلتر وضعیت --}}
    <div class="flex flex-wrap gap-2 mb-5 text-xs">
        <a href="{{ route('admin.referral-requests.index') }}"
           class="px-3 py-1.5 rounded-lg border {{ $status ? 'border-slate-200 text-slate-600 bg-white' : 'border-brand-600 bg-brand-600 text-white' }}">
            همه ({{ $counts->sum() }})
        </a>
        @foreach($statusLabels as $key => [$label])
            <a href="{{ route('admin.referral-requests.index', ['status' => $key]) }}"
               class="px-3 py-1.5 rounded-lg border {{ $status === $key ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 text-slate-600 bg-white' }}">
                {{ $label }} ({{ $counts[$key] ?? 0 }})
            </a>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse($requests as $item)
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-xs">
                <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                    <div class="flex-1 min-w-0 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-slate-400">#{{ $item->id }}</span>
                            @if($item->user)
                                <a href="{{ route('admin.users.show', $item->user->id) }}" class="font-bold text-brand-700 hover:underline">
                                    {{ $item->user->name ?? '—' }}
                                </a>
                                <span class="text-slate-400" dir="ltr">{{ $item->user->email }}</span>
                            @else
                                <span class="text-slate-400">کاربر حذف شده</span>
                            @endif
                            <span class="px-2 py-0.5 rounded-full border {{ $statusLabels[$item->status][1] ?? '' }}">
                                {{ $statusLabels[$item->status][0] ?? $item->status }}
                            </span>
                        </div>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-slate-600">
                            <span>موبایل: <b dir="ltr">{{ $item->phone }}</b></span>
                            <span>مخاطب: {{ $audienceLabels[$item->audience_size] ?? '—' }}</span>
                            <span dir="ltr">{{ \Morilog\Jalali\Jalalian::fromCarbon($item->created_at->copy()->timezone('Asia/Tehran'))->format('Y/m/d H:i') }}</span>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            @foreach((array) $item->channels as $channel)
                                <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ $channelLabels[$channel] ?? $channel }}</span>
                            @endforeach
                        </div>

                        <p class="text-slate-700 leading-6 whitespace-pre-line">{{ $item->description }}</p>
                    </div>

                    <form method="POST" action="{{ route('admin.referral-requests.update', $item) }}" class="lg:w-72 shrink-0 space-y-2">
                        @csrf
                        @method('PATCH')
                        <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach($statusLabels as $key => [$label])
                                <option value="{{ $key }}" @selected($item->status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <textarea name="admin_note" rows="2" placeholder="یادداشت داخلی (اختیاری)"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500">{{ $item->admin_note }}</textarea>
                        <button type="submit" class="w-full px-3 py-2 rounded-lg bg-brand-600 text-white font-medium hover:bg-brand-700 transition">ذخیره</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-slate-400 text-xs">درخواستی ثبت نشده است.</div>
        @endforelse
    </div>

    <div class="mt-5">{{ $requests->links() }}</div>
</div>
@endsection
