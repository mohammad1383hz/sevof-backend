@extends('layouts.admin')

@section('content')
@php
    $jdate = fn ($date, string $format = 'Y/m/d') => \Morilog\Jalali\Jalalian::fromCarbon(\Carbon\Carbon::parse($date))->format($format);
@endphp

<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6" dir="rtl">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">داشبورد مدیریتی</h1>
        <p class="text-sm text-gray-500 mt-1">
            نمایش عملکرد از <span dir="ltr" class="inline-block">{{ $from }}</span>
            تا <span dir="ltr" class="inline-block">{{ $to }}</span>
        </p>
        @if(!empty($invalidDate))
            <p class="text-xs text-red-600 mt-1">تاریخ واردشده معتبر نبود و نادیده گرفته شد. فرمت صحیح: ۱۴۰۵/۰۷/۲۵</p>
        @endif
    </div>

    <form class="flex flex-wrap items-end gap-3 bg-white p-4 rounded-xl shadow-sm border border-gray-100" method="GET">
        <div class="flex flex-col">
            <label class="text-xs text-gray-500 mb-1">از تاریخ (شمسی)</label>
            <input type="text" name="from" value="{{ $from }}" dir="ltr"
                   inputmode="numeric" autocomplete="off" placeholder="1405/07/01"
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-36 text-center focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <div class="flex flex-col">
            <label class="text-xs text-gray-500 mb-1">تا تاریخ (شمسی)</label>
            <input type="text" name="to" value="{{ $to }}" dir="ltr"
                   inputmode="numeric" autocomplete="off" placeholder="1405/07/30"
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-36 text-center focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <button type="submit"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
            به‌روزرسانی
        </button>
    </form>
</div>

{{-- KPI Row 1: Sales --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6" dir="rtl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">درآمد قطعی</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['sales']['revenue']) }}</div>
        <div class="text-xs text-gray-400 mt-1">جمع پرداخت‌های موفق</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">تعداد خرید موفق</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['sales']['success_count']) }}</div>
        <div class="text-xs text-gray-400 mt-1">میانگین خرید: {{ number_format($report['sales']['average_order_value']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">نرخ موفقیت پرداخت</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['sales']['conversion_rate'], 1) }}٪</div>
        <div class="text-xs text-gray-400 mt-1">در برابر کل تلاش‌های پرداخت</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">خریداران یکتا (کل دوره)</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['buyers']['unique']) }}</div>
        <div class="text-xs text-gray-400 mt-1">کاربران دارای حداقل یک خرید موفق، از ابتدا تاکنون</div>
    </div>
</div>

{{-- Repeat buyers (all time) --}}
@php($uniqueBuyers = max(0, (int) $report['buyers']['unique']))
@php($repeatCards = [
    ['label' => 'بیش از ۱ خرید', 'hint' => 'حداقل ۲ خرید موفق', 'count' => $report['buyers']['more_than_1']],
    ['label' => 'بیش از ۲ خرید', 'hint' => 'حداقل ۳ خرید موفق', 'count' => $report['buyers']['more_than_2']],
    ['label' => 'بیش از ۳ خرید', 'hint' => 'حداقل ۴ خرید موفق', 'count' => $report['buyers']['more_than_3']],
])
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6" dir="rtl">
    @foreach($repeatCards as $card)
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <div class="text-xs text-gray-500 mb-1">خریداران با {{ $card['label'] }} (کل دوره)</div>
            <div class="flex items-baseline gap-2">
                <div class="text-2xl font-bold text-gray-800">{{ number_format($card['count']) }}</div>
                <div class="text-sm text-indigo-600">
                    {{ $uniqueBuyers > 0 ? number_format(($card['count'] / $uniqueBuyers) * 100, 1) : '0.0' }}٪ از خریداران
                </div>
            </div>
            <div class="text-xs text-gray-400 mt-1">{{ $card['hint'] }}</div>
        </div>
    @endforeach
</div>

{{-- KPI Row 2: Users / Subscriptions / Content --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6" dir="rtl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">کل کاربران</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['users']['total']) }}</div>
        <div class="text-xs text-gray-400 mt-1">کاربر جدید دوره: {{ number_format($report['users']['new']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">اشتراک فعال</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['subscriptions']['active']) }}</div>
        <div class="text-xs text-gray-400 mt-1">اشتراک جدید دوره: {{ number_format($report['subscriptions']['new']) }}</div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <div class="text-xs text-gray-500 mb-1">مقاله تکمیل‌شده</div>
        <div class="text-2xl font-bold text-gray-800">{{ number_format($report['content']['completed_articles']) }}</div>
        <div class="text-xs text-gray-400 mt-1">مقاله منتشرشده: {{ number_format($report['content']['published_articles']) }}</div>
    </div>
</div>

{{-- Daily sales chart + Plan sales table --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6" dir="rtl">
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">فروش روزانه</h2>
        @php($maxRevenue = max(1, (int) $report['sales_series']->max('revenue')))
        <div class="flex items-end gap-2 h-[180px] overflow-x-auto pb-2" dir="ltr">
            @foreach($report['sales_series'] as $point)
                <div class="flex flex-col items-center justify-end h-full min-w-[24px] flex-1"
                     title="{{ $jdate($point['day']) }}: {{ number_format($point['revenue']) }}">
                    <span class="w-full max-w-[18px] bg-indigo-500 rounded-t-md transition-all hover:bg-indigo-600"
                          style="height:{{ max(2, round(($point['revenue'] / $maxRevenue) * 145)) }}px"></span>
                    <small class="text-[10px] text-gray-400 mt-1 whitespace-nowrap">{{ $jdate($point['day'], 'm/d') }}</small>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-400 mt-3">ارتفاع ستون‌ها بر اساس درآمد پرداخت‌های موفق است. تاریخ‌ها شمسی هستند.</p>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">فروش بر اساس پلن</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500">
                        <th class="py-2 font-medium">پلن</th>
                        <th class="py-2 font-medium">تعداد</th>
                        <th class="py-2 font-medium">درآمد</th>
                        <th class="py-2 font-medium">سهم</th>
                    </tr>
                </thead>
                <tbody>
                @php($totalRevenue = max(1, (int) $report['sales']['revenue']))
                @forelse($report['plan_sales'] as $plan)
                    <tr class="border-b border-gray-50 hover:bg-gray-50">
                        <td class="py-2 text-gray-700">{{ $plan->plan_name }}</td>
                        <td class="py-2 text-gray-700">{{ number_format($plan->transactions) }}</td>
                        <td class="py-2 text-gray-700">{{ number_format($plan->revenue) }}</td>
                        <td class="py-2 text-gray-700">{{ number_format(($plan->revenue / $totalRevenue) * 100, 1) }}٪</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-gray-400">داده‌ای در این بازه وجود ندارد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

{{-- Payment status + Top buyers --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6" dir="rtl">
    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">وضعیت پرداخت‌ها</h2>
        <div class="space-y-2 text-sm text-gray-600">
            <p>موفق: <strong class="text-green-600">{{ number_format($report['sales']['success_count']) }}</strong></p>
            <p>در انتظار: <strong class="text-amber-500">{{ number_format($report['sales']['pending_count']) }}</strong></p>
            <p>ناموفق: <strong class="text-red-600">{{ number_format($report['sales']['failed_count']) }}</strong></p>
            <hr class="my-3 border-gray-100">
            <p>تعداد درخواست‌های AI: <strong class="text-gray-800">{{ number_format($report['usage']['requests']) }}</strong></p>
            <p>توکن مصرف‌شده: <strong class="text-gray-800">{{ number_format($report['usage']['tokens']) }}</strong></p>
            <p>اعتبار مصرف‌شده: <strong class="text-gray-800">{{ number_format($report['usage']['credit'], 4) }}</strong></p>
        </div>
    </section>

    <section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">خریداران برتر <span class="text-xs font-normal text-gray-400">(در بازه انتخابی)</span></h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right">
                <thead>
                    <tr class="border-b border-gray-100 text-gray-500">
                        <th class="py-2 font-medium">کاربر</th>
                        <th class="py-2 font-medium">تعداد خرید</th>
                        <th class="py-2 font-medium">درآمد</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($report['top_buyers']->take(8) as $buyer)
                    <tr class="border-b border-gray-50 hover:bg-gray-50">
                        <td class="py-2">
                            <a href="{{ route('admin.users.show', $buyer->user_id) }}" class="text-indigo-600 hover:underline font-medium">{{ $buyer->name }}</a>
                            <br><small class="text-gray-400">{{ $buyer->email }}</small>
                        </td>
                        <td class="py-2 text-gray-700">{{ number_format($buyer->transactions) }}</td>
                        <td class="py-2 text-gray-700">{{ number_format($buyer->revenue) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-6 text-center text-gray-400">خرید موفقی ثبت نشده است.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

{{-- Recent activities --}}
<section class="bg-white rounded-xl shadow-sm border border-gray-100 p-5" dir="rtl">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-800">آخرین فعالیت‌های سیستم</h2>
        <a href="{{ route('admin.activities.index') }}"
           class="text-sm text-indigo-600 hover:text-indigo-700 font-medium border border-indigo-100 hover:bg-indigo-50 px-3 py-1.5 rounded-lg transition">
            مشاهده همه فعالیت‌ها
        </a>
    </div>

    <div class="flex flex-col gap-3">
        @forelse($report['recent_activities'] as $event)
            <div class="border border-gray-100 rounded-lg p-3 hover:bg-gray-50 transition">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <strong class="text-gray-800 text-sm">{{ $event['label'] }}</strong>
                    <small class="text-gray-400 text-xs" dir="ltr">{{ $jdate($event['at'], 'Y/m/d H:i') }}</small>
                </div>
                <div class="text-sm text-gray-600 mt-1">
                    {{ $event['subject'] ?? 'بدون موضوع' }}
                    @if(isset($event['amount']))
                        <span class="mx-1">·</span> مبلغ {{ number_format($event['amount']) }}
                    @endif
                </div>
            </div>
        @empty
            <p class="text-gray-400 text-center py-8">در این بازه فعالیتی ثبت نشده است.</p>
        @endforelse
    </div>
</section>
@endsection
