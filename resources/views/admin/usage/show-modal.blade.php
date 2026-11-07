{{-- resources/views/admin/usage/show-modal.blade.php --}}
{{-- این ویو مستقل نیست و فقط داخل مودال (index.blade.php) لود می‌شود --}}

<div id="usage-modal-content" data-user-id="{{ $user->id }}">

    <div class="flex items-start justify-between gap-4 mb-5">
        <div>
            <h2 class="text-lg font-bold text-slate-900">{{ $user->name ?? 'کاربر بدون نام' }}</h2>
            <p class="text-sm text-slate-500 mt-0.5" dir="ltr">
                {{ $user->phone ?? '—' }} @if($user->email) · {{ $user->email }} @endif
            </p>
        </div>
        <button type="button" onclick="closeUsageModal()"
                class="text-slate-400 hover:text-slate-600 text-2xl leading-none px-2">
            &times;
        </button>
    </div>

    {{-- فیلتر بازه زمانی داخل مودال --}}
    <form data-ajax-form method="GET" action="{{ route('admin.usage.show', $user) }}"
          class="flex flex-wrap gap-3 items-end mb-5 border-b border-slate-100 pb-4">

        <div class="flex flex-col min-w-[160px]">
            <label class="text-xs text-slate-500 mb-1.5">بازه زمانی</label>
            <select name="period" data-period-select
                    class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <option value="all_time" @selected($filters['period'] === 'all_time')>کل بازه (از ابتدا)</option>
                <option value="today" @selected($filters['period'] === 'today')>امروز</option>
                <option value="yesterday" @selected($filters['period'] === 'yesterday')>دیروز</option>
                <option value="this_week" @selected($filters['period'] === 'this_week')>هفته جاری</option>
                <option value="last_week" @selected($filters['period'] === 'last_week')>هفته گذشته</option>
                <option value="this_month" @selected($filters['period'] === 'this_month')>ماه جاری (شمسی)</option>
                <option value="last_month" @selected($filters['period'] === 'last_month')>ماه گذشته (شمسی)</option>
                <option value="custom" @selected($filters['period'] === 'custom')>بازه دلخواه</option>
            </select>
        </div>

        <div class="flex flex-col min-w-[130px]" data-custom-range style="{{ $filters['period'] === 'custom' ? '' : 'display:none' }}">
            <label class="text-xs text-slate-500 mb-1.5">از تاریخ (شمسی)</label>
            <input type="text" name="from_date" value="{{ $filters['from_date'] }}" placeholder="1404-06-01" dir="ltr"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <div class="flex flex-col min-w-[130px]" data-custom-range style="{{ $filters['period'] === 'custom' ? '' : 'display:none' }}">
            <label class="text-xs text-slate-500 mb-1.5">تا تاریخ (شمسی)</label>
            <input type="text" name="to_date" value="{{ $filters['to_date'] }}" placeholder="1404-06-31" dir="ltr"
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
        </div>

        <button type="submit"
                class="bg-brand-600 hover:bg-brand-700 transition-colors text-white font-medium rounded-lg px-4 py-2 text-sm shadow-sm">
            اعمال
        </button>

        <span class="text-xs text-slate-400 ms-auto">
            {{ $periodLabel }} @if($rangeLabel) ({{ $rangeLabel }}) @endif
        </span>
    </form>

    {{-- کارت‌های خلاصه --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="bg-slate-50 rounded-lg p-3">
            <p class="text-xs text-slate-500 mb-1">کریدیت مصرفی (بازه)</p>
            <p class="font-bold text-slate-900">{{ number_format((float) $totals['credit'], 4) }}</p>
        </div>
        <div class="bg-slate-50 rounded-lg p-3">
            <p class="text-xs text-slate-500 mb-1">توکن مصرفی (بازه)</p>
            <p class="font-bold text-slate-900">{{ number_format((int) $totals['tokens']) }}</p>
        </div>
        <div class="bg-slate-50 rounded-lg p-3">
            <p class="text-xs text-slate-500 mb-1">هزینه (USD)</p>
            <p class="font-bold text-slate-900">${{ number_format((float) $totals['cost_usd'], 4) }}</p>
        </div>
        <div class="bg-slate-50 rounded-lg p-3">
            <p class="text-xs text-slate-500 mb-1">تعداد درخواست</p>
            <p class="font-bold text-slate-900">{{ number_format((int) $totals['requests']) }}</p>
        </div>
    </div>

    <p class="text-sm text-slate-500 mb-6">
        کریدیت کل مصرفی از ابتدا:
        <strong class="text-slate-800">{{ number_format((float) $lifetimeCredit, 4) }}</strong>
    </p>

    {{-- مصرف روزانه --}}
    <div class="mb-6">
        <h3 class="text-sm font-semibold text-slate-800 mb-2">مصرف روزانه</h3>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[500px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">تاریخ</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">کریدیت</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">هزینه (USD)</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">تعداد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($dailyUsage as $row)
                    <tr>
                        <td class="px-3 py-2">{{ $row->usage_date_jalali }}</td>
                        <td class="px-3 py-2 font-semibold text-slate-900">{{ number_format((float) $row->credit, 4) }}</td>
                        <td class="px-3 py-2">${{ number_format((float) $row->cost_usd, 4) }}</td>
                        <td class="px-3 py-2">{{ number_format((int) $row->requests) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-center text-slate-400">مصرفی در این بازه ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- مصرف به تفکیک سرویس / مدل --}}
    <div class="mb-6">
        <h3 class="text-sm font-semibold text-slate-800 mb-2">مصرف به تفکیک سرویس و مدل</h3>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[600px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">سرویس</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">مدل</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">کریدیت</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">هزینه (USD)</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">تعداد</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($usageByModel as $row)
                    <tr>
                        <td class="px-3 py-2 text-slate-600">{{ $row->service_name ?? '—' }}</td>
                        <td class="px-3 py-2 text-slate-600" dir="ltr">{{ $row->model ?? '—' }}</td>
                        <td class="px-3 py-2 font-semibold text-slate-900">{{ number_format((float) $row->credit, 4) }}</td>
                        <td class="px-3 py-2">${{ number_format((float) $row->cost_usd, 4) }}</td>
                        <td class="px-3 py-2">{{ number_format((int) $row->requests) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-6 text-center text-slate-400">مصرفی در این بازه ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- اشتراک‌ها --}}
    <div class="mb-6">
        <h3 class="text-sm font-semibold text-slate-800 mb-2">اشتراک‌ها</h3>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[500px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">پلن</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">وضعیت</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">کریدیت مصرفی</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">شروع</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">پایان</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($subscriptions as $sub)
                    <tr>
                        <td class="px-3 py-2">#{{ $sub->plan_id }}</td>
                        <td class="px-3 py-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-medium {{ $sub->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                {{ $sub->status }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ number_format((float) $sub->credit_used, 4) }}</td>
                        <td class="px-3 py-2">{{ $sub->starts_at_jalali ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $sub->ends_at_jalali ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-center text-slate-400">اشتراکی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- رکوردهای اخیر --}}
    <div>
        <h3 class="text-sm font-semibold text-slate-800 mb-2">رکوردهای اخیر مصرف</h3>
        <div class="overflow-x-auto rounded-lg border border-slate-200">
            <table class="w-full min-w-[700px] border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50">
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">تاریخ</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">سرویس</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">مدل</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">کریدیت</th>
                        <th class="text-right font-medium text-xs text-slate-500 px-3 py-2">هزینه (USD)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($recentRecords as $record)
                    <tr>
                        <td class="px-3 py-2 text-slate-500">{{ $record->created_at_jalali }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ $record->service_name ?? '—' }}</td>
                        <td class="px-3 py-2 text-slate-600" dir="ltr">{{ $record->model ?? '—' }}</td>
                        <td class="px-3 py-2 font-semibold text-slate-900">{{ number_format((float) $record->credit, 4) }}</td>
                        <td class="px-3 py-2">${{ number_format((float) $record->cost_usd, 4) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-6 text-center text-slate-400">رکوردی یافت نشد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $recentRecords->links() }}</div>
    </div>

</div>
