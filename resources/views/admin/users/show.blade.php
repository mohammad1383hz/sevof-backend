@extends('layouts.admin')

@section('content')
@php($formatSeconds = function ($seconds) {
    if ($seconds === null) return 'Insufficient Data';
    $seconds = (int) $seconds;
    if ($seconds < 60) return $seconds . ' ثانیه';
    if ($seconds < 3600) return floor($seconds / 60) . ' دقیقه';
    return floor($seconds / 3600) . ' ساعت و ' . floor(($seconds % 3600) / 60) . ' دقیقه';
})
<div class="page-head">
    <div><h1>{{ $user->name }}</h1><div class="muted">{{ $user->email }} @if($user->phone) · {{ $user->phone }} @endif</div></div>
    <a class="btn light" href="{{ route('admin.users.index') }}">بازگشت به کاربران</a>
</div>

<div class="grid">
    <div class="card kpi"><div class="label">درآمد قطعی</div><div class="value">{{ number_format($snapshot['totals']['revenue']) }}</div><div class="sub">{{ number_format($snapshot['totals']['payments']) }} تراکنش</div></div>
    <div class="card kpi"><div class="label">ورود موفق</div><div class="value">{{ number_format($snapshot['totals']['logins']) }}</div><div class="sub">تاریخچه login</div></div>
    <div class="card kpi"><div class="label">محتوا</div><div class="value">{{ number_format($snapshot['totals']['articles']) }}</div><div class="sub">ایده: {{ number_format($snapshot['totals']['ideas']) }}</div></div>
    <div class="card kpi"><div class="label">مصرف توکن</div><div class="value">{{ number_format($snapshot['totals']['tokens']) }}</div><div class="sub">اعتبار: {{ number_format($snapshot['totals']['credit'], 4) }}</div></div>
</div>

<div class="grid">
    <div class="card kpi"><div class="label">Engagement Score</div><div class="value">{{ $behavior['engagement_score'] }}/100</div><div class="sub">{{ $behavior['segment'] }}</div></div>
    <div class="card kpi"><div class="label">Purchase Intent</div><div class="value">{{ $behavior['intent_score'] }}/100</div><div class="sub">Lead: {{ $behavior['lead'] }}</div></div>
    <div class="card kpi"><div class="label">Current Funnel Stage</div><div class="value" style="font-size:16px">{{ $behavior['current_funnel_stage'] }}</div><div class="sub">Activation: {{ $behavior['activated'] ? 'بله' : 'خیر' }}</div></div>
    <div class="card kpi"><div class="label">Retention</div><div class="value" style="font-size:16px">{{ $behavior['returning'] ? 'Returning' : 'One-time' }}</div><div class="sub">Active days: {{ number_format($behavior['active_days']) }}</div></div>
</div>

<div class="grid two">
    <section class="card"><h2>Funnel Progress</h2>
        @foreach($behavior['funnel_progress'] as $stage)
            <p><span class="badge {{ $stage['status'] === 'completed' ? 'success' : ($stage['status'] === 'not_measurable' ? 'warning' : 'danger') }}">{{ $stage['status'] === 'completed' ? '✓' : ($stage['status'] === 'not_measurable' ? '?' : '✗') }}</span> {{ $stage['label'] }} @if($stage['at'])<small class="muted"> · {{ \Carbon\Carbon::parse($stage['at'])->format('Y-m-d H:i') }}</small>@endif</p>
        @endforeach
    </section>
    <section class="card"><h2>Explainable Scores</h2>
        <h3>Intent</h3><p class="muted">{{ implode('، ', $behavior['intent_reasons']) }}</p>
        <h3>Engagement</h3><p class="muted">{{ implode('، ', $behavior['engagement_reasons']) }}</p>
        <p>First Value: <strong>{{ $behavior['first_value_at'] ? \Carbon\Carbon::parse($behavior['first_value_at'])->format('Y-m-d H:i') : 'Insufficient Data' }}</strong></p>
        <p>Total actions: <strong>{{ number_format($behavior['total_actions']) }}</strong> · Meaningful: <strong>{{ number_format($behavior['meaningful_actions']) }}</strong></p>
        <p>First measurable: <strong>{{ $behavior['first_seen_at'] ? \Carbon\Carbon::parse($behavior['first_seen_at'])->format('Y-m-d H:i') : 'Insufficient Data' }}</strong> · Last seen: <strong>{{ $behavior['last_seen_at'] ? \Carbon\Carbon::parse($behavior['last_seen_at'])->format('Y-m-d H:i') : '—' }}</strong></p>
        <p>Total sessions: <strong>{{ $behavior['total_sessions'] === null ? 'Insufficient Data' : number_format($behavior['total_sessions']) }}</strong> · Page views: <strong>{{ number_format($behavior['page_views']) }}</strong></p>
    </section>
</div>

<div class="grid two">
    <section class="card"><h2>Retention Flags</h2><div class="table-wrap"><table><thead><tr><th>Same day</th><th>D1</th><th>D2</th><th>D3</th><th>D7</th><th>D14</th><th>D30</th></tr></thead><tbody><tr>@foreach(['same_day','d1','d2','d3','d7','d14','d30'] as $key)<td>{{ $behavior['retention'][$key] === null ? '—' : ($behavior['retention'][$key] ? 'Yes' : 'No') }}</td>@endforeach</tr></tbody></table></div></section>
    <section class="card"><h2>Time to Value</h2><div class="table-wrap"><table><thead><tr><th>مسیر</th><th>زمان</th></tr></thead><tbody>@foreach($behavior['time_to_value'] as $key => $seconds)<tr><td>{{ str_replace('_', ' → ', $key) }}</td><td>{{ $formatSeconds($seconds) }}</td></tr>@endforeach</tbody></table></div></section>
</div>

<div class="grid two">
    <section class="card"><h2>پرداخت‌ها</h2><div class="table-wrap"><table><thead><tr><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>مرجع</th><th>تاریخ</th></tr></thead><tbody>
    @forelse($snapshot['payments'] as $payment)<tr><td>{{ $payment->plan_name }} <small class="muted">· {{ $payment->period_months ?? 1 }} ماهه</small></td><td>{{ number_format($payment->amount) }}</td><td><span class="badge {{ $payment->status === 'success' ? 'success' : ($payment->status === 'failed' ? 'danger' : 'warning') }}">{{ $payment->status }}</span></td><td>{{ $payment->ref_id ?: $payment->authority ?: '—' }}</td><td>{{ $payment->created_at }}</td></tr>@empty<tr><td colspan="5" class="muted">پرداختی نیست.</td></tr>@endforelse
    </tbody></table></div></section>
    <section class="card"><h2>اشتراک‌ها</h2><div class="table-wrap"><table><thead><tr><th>پلن</th><th>وضعیت</th><th>شروع</th><th>پایان</th><th>مصرف</th></tr></thead><tbody>
    @forelse($snapshot['subscriptions'] as $subscription)<tr><td>{{ $subscription->plan_name }} <small class="muted">· {{ $subscription->period_months ?? 1 }} ماهه</small></td><td><span class="badge {{ $subscription->status === 'active' ? 'success' : 'warning' }}">{{ $subscription->status }}</span></td><td>{{ $subscription->starts_at }}</td><td>{{ $subscription->ends_at ?: '—' }}</td><td>{{ number_format($subscription->tokens_used) }}</td></tr>@empty<tr><td colspan="5" class="muted">اشتراکی نیست.</td></tr>@endforelse
    </tbody></table></div></section>
</div>

<section class="card"><h2>ورودها</h2><div class="table-wrap"><table><thead><tr><th>روش</th><th>نتیجه</th><th>IP</th><th>User-Agent</th><th>زمان</th></tr></thead><tbody>
@forelse($snapshot['logins'] as $login)<tr><td>{{ $login->login_method }}</td><td><span class="badge {{ $login->success ? 'success' : 'danger' }}">{{ $login->success ? 'موفق' : 'ناموفق' }}</span></td><td>{{ $login->ip_address ?: '—' }}</td><td>{{ \Illuminate\Support\Str::limit($login->user_agent ?: '—', 70) }}</td><td>{{ $login->created_at }}</td></tr>@empty<tr><td colspan="5" class="muted">لاگ login موجود نیست؛ migration و ثبت AuthController را اعمال کنید.</td></tr>@endforelse
</tbody></table></div></section>

<div class="grid two">
    <section class="card"><h2>سایت‌ها</h2><div class="table-wrap"><table><thead><tr><th>دامنه</th><th>وضعیت</th><th>موضوع</th><th>تاریخ</th><th>واحد قیمت محصولات</th></tr></thead><tbody>@forelse($snapshot['sites'] as $site)<tr><td>{{ $site->domain }}</td><td>{{ $site->status }}</td><td>{{ $site->niche ?: '—' }}</td><td>{{ $site->created_at }}</td><td><form method="POST" action="{{ route('admin.sites.product-currency', $site->id) }}" onchange="this.submit()">@csrf<select name="currency" aria-label="واحد قیمت محصولات {{ $site->domain }}"><option value="IRT" @selected(($site->product_currency ?? 'IRT') !== 'IRR')>تومان</option><option value="IRR" @selected(($site->product_currency ?? null) === 'IRR')>ریال</option></select></form></td></tr>@empty<tr><td colspan="5" class="muted">سایتی نیست.</td></tr>@endforelse</tbody></table></div></section>
    <section class="card"><h2>مقاله‌ها</h2><div class="table-wrap"><table><thead><tr><th>عنوان</th><th>وضعیت</th><th>SEO</th><th>کلمات</th><th>تاریخ</th></tr></thead><tbody>@forelse($snapshot['articles'] as $article)<tr><td>{{ \Illuminate\Support\Str::limit($article->title, 50) }}</td><td>{{ $article->status }}</td><td>{{ $article->seo_score ?? '—' }}</td><td>{{ number_format($article->word_count ?? 0) }}</td><td>{{ $article->created_at }}</td></tr>@empty<tr><td colspan="5" class="muted">مقاله‌ای نیست.</td></tr>@endforelse</tbody></table></div></section>
</div>

<div class="grid two">
    <section class="card"><h2>تحقیقات محتوا</h2><div class="table-wrap"><table><thead><tr><th>نوع</th><th>وضعیت</th><th>منبع</th><th>زمان</th></tr></thead><tbody>@forelse($snapshot['research_sessions'] as $session)<tr><td>{{ $session->type }}</td><td>{{ $session->status }}</td><td>{{ $session->data_source ?? '—' }}</td><td>{{ $session->created_at }}</td></tr>@empty<tr><td colspan="4" class="muted">تحقیقی نیست.</td></tr>@endforelse</tbody></table></div></section>
    <section class="card"><h2>پیام‌های ویرایش مقاله</h2><div class="table-wrap"><table><thead><tr><th>مقاله</th><th>نقش</th><th>هدف</th><th>اعمال شد</th><th>زمان</th></tr></thead><tbody>@forelse($snapshot['chat_messages'] as $message)<tr><td>{{ \Illuminate\Support\Str::limit($message->article_title, 35) }}</td><td>{{ $message->role }}</td><td>{{ $message->target_label ?: $message->target_type ?: 'عمومی' }}</td><td>{{ $message->applied ? 'بله' : 'خیر' }}</td><td>{{ $message->created_at }}</td></tr>@empty<tr><td colspan="5" class="muted">پیامی نیست.</td></tr>@endforelse</tbody></table></div></section>
</div>

<section class="card"><h2>Timeline کامل فعالیت کاربر</h2><div class="timeline">
@forelse($snapshot['timeline'] as $event)
<div class="event"><div class="event-head"><strong>{{ $event['label'] }}</strong><small class="muted">{{ \Carbon\Carbon::parse($event['at'])->format('Y-m-d H:i:s') }}</small></div><div class="event-body">{{ $event['subject'] ?? '—' }} @if(isset($event['status'])) · وضعیت: {{ $event['status'] }} @endif @if(isset($event['amount'])) · مبلغ: {{ number_format($event['amount']) }} @endif @if(isset($event['method'])) · روش: {{ $event['method'] }} @endif @if(isset($event['tokens'])) · توکن: {{ number_format($event['tokens']) }} @endif</div></div>
@empty<p class="muted">فعالیتی ثبت نشده است.</p>@endforelse
</div></section>
@endsection
