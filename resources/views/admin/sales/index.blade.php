@extends('layouts.admin')

@section('content')
<div class="page-head">
    <div><h1>گزارش فروش</h1><div class="muted">محور اصلی تحلیل سیستم: درآمد، تراکنش و عملکرد پلن‌ها</div></div>
    <form class="filters" method="GET">
        <div><label>از تاریخ</label><input type="date" name="from" value="{{ $from }}"></div>
        <div><label>تا تاریخ</label><input type="date" name="to" value="{{ $to }}"></div>
        <div><label>وضعیت</label><select name="status"><option value="">همه وضعیت‌ها</option><option value="success" @selected($status === 'success')>موفق</option><option value="pending" @selected($status === 'pending')>در انتظار</option><option value="failed" @selected($status === 'failed')>ناموفق</option></select></div>
        <div><label>پلن</label><select name="plan_id"><option value="">همه پلن‌ها</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected($planId === $plan->id)>{{ $plan->name }}</option>@endforeach</select></div>
        <button type="submit">فیلتر گزارش</button>
    </form>
</div>

<div class="grid">
    <div class="card kpi"><div class="label">درآمد قطعی</div><div class="value">{{ number_format($summary['revenue']) }}</div><div class="sub">فقط paymentهای موفق</div></div>
    <div class="card kpi"><div class="label">کل تراکنش‌ها</div><div class="value">{{ number_format($summary['transactions']) }}</div><div class="sub">با فیلتر انتخاب‌شده</div></div>
    <div class="card kpi"><div class="label">موفق</div><div class="value positive">{{ number_format($summary['successful_transactions']) }}</div><div class="sub">تبدیل به درآمد</div></div>
    <div class="card kpi"><div class="label">در انتظار / ناموفق</div><div class="value warning">{{ number_format($summary['pending']) }} / {{ number_format($summary['failed']) }}</div><div class="sub">نیازمند پیگیری</div></div>
</div>

<div class="grid two">
    <section class="card"><h2>روند درآمد روزانه</h2>@php($maxRevenue = max(1, (int) $daily->max('revenue')))<div class="chart">@foreach($daily as $point)<div class="bar-col" title="{{ $point['day'] }}: {{ number_format($point['revenue']) }}"><span style="height:{{ max(2, round(($point['revenue'] / $maxRevenue) * 145)) }}px"></span><small>{{ substr($point['day'], 5) }}</small></div>@endforeach</div></section>
    <section class="card"><h2>فروش بر اساس پلن</h2><div class="table-wrap"><table><thead><tr><th>پلن</th><th>تراکنش</th><th>درآمد</th></tr></thead><tbody>@forelse($by_plan as $row)<tr><td>{{ $row->plan_name }}</td><td>{{ number_format($row->transactions) }}</td><td>{{ number_format($row->revenue) }}</td></tr>@empty<tr><td colspan="3" class="muted">داده‌ای نیست.</td></tr>@endforelse</tbody></table></div></section>
</div>

<section class="card"><h2>خریداران برتر این گزارش</h2><div class="table-wrap"><table><thead><tr><th>کاربر</th><th>تراکنش</th><th>درآمد</th><th></th></tr></thead><tbody>@forelse($top_buyers as $buyer)<tr><td>{{ $buyer->name }}<br><small class="muted">{{ $buyer->email }}</small></td><td>{{ number_format($buyer->transactions) }}</td><td><strong>{{ number_format($buyer->revenue) }}</strong></td><td><a class="btn light" href="{{ route('admin.users.show', $buyer->user_id) }}">جزئیات کاربر</a></td></tr>@empty<tr><td colspan="4" class="muted">خریدی نیست.</td></tr>@endforelse</tbody></table></div></section>

<section class="card"><h2>ریز تراکنش‌ها</h2><div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>کاربر</th><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>Authority / Ref</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td>{{ $payment->created_at }}</td><td><a href="{{ route('admin.users.show', $payment->user_id) }}">{{ $payment->user_name }}</a><br><small class="muted">{{ $payment->user_email }}</small></td><td>{{ $payment->plan_name }} <small class="muted">· {{ $payment->period_months ?? 1 }} ماهه</small></td><td>{{ number_format($payment->amount) }}</td><td><span class="badge {{ $payment->status === 'success' ? 'success' : ($payment->status === 'failed' ? 'danger' : 'warning') }}">{{ $payment->status }}</span></td><td>{{ $payment->ref_id ?: $payment->authority ?: '—' }}</td></tr>@empty<tr><td colspan="6" class="muted">تراکنشی ثبت نشده است.</td></tr>@endforelse</tbody></table></div>{{ $payments->links('pagination::simple-default') }}</section>
@endsection
