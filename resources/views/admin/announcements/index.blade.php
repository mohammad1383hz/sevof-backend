@extends('layouts.admin')

@section('content')
<div class="max-w-full">

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-slate-800">اطلاعیه‌ها</h1>
        <a href="{{ route('admin.announcements.create') }}"
           class="px-4 py-2 rounded-lg text-xs font-medium bg-brand-600 text-white hover:bg-brand-700 transition">
            + اطلاعیه جدید
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 text-xs px-4 py-3">
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
                <thead class="bg-slate-50 text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 font-medium">#</th>
                        <th class="px-4 py-3 font-medium">عنوان</th>
                        <th class="px-4 py-3 font-medium">مخاطب</th>
                        <th class="px-4 py-3 font-medium">وضعیت</th>
                        <th class="px-4 py-3 font-medium">تاریخ</th>
                        <th class="px-4 py-3 font-medium">عملیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($announcements as $item)
                        <tr class="hover:bg-slate-50 transition-colors align-top">
                            <td class="px-4 py-3 text-slate-500">{{ $item->id }}</td>
                            <td class="px-4 py-3 text-slate-700 font-medium">{{ $item->title }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ \App\Models\Announcement::AUDIENCES[$item->audience] ?? $item->audience }}
                                @if($item->audience === 'plans')
                                    <div class="text-slate-400 text-[11px]">{{ $item->plans->pluck('name')->implode('، ') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($item->is_active)
                                    <span class="inline-block px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px]">فعال</span>
                                @else
                                    <span class="inline-block px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[11px]">غیرفعال</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-500 whitespace-nowrap" dir="ltr">
                                {{ \Morilog\Jalali\Jalalian::fromCarbon($item->created_at->copy()->timezone('Asia/Tehran'))->format('Y/m/d H:i') }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.announcements.edit', $item) }}"
                                       class="px-3 py-1.5 rounded-lg text-xs font-medium border border-slate-200 text-slate-600 hover:bg-slate-50 transition">
                                        ویرایش
                                    </a>
                                    <form action="{{ route('admin.announcements.destroy', $item) }}" method="POST"
                                          onsubmit="return confirm('اطلاعیه حذف بشه؟');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="px-3 py-1.5 rounded-lg text-xs font-medium border border-red-200 text-red-600 bg-red-50 hover:bg-red-100 transition">
                                            حذف
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">موردی یافت نشد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $announcements->links() }}
    </div>

</div>
@endsection
