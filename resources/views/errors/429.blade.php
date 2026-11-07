@extends('errors.layout')

@section('code', '429')
@section('title', 'یه‌کم آروم‌تر!')
@section('description', 'در بازه‌ی زمانی کوتاهی درخواست‌های زیادی فرستادی. چند لحظه صبر کن و دوباره امتحان کن.')
@section('footnote', 'تعداد درخواست‌ها بیش از حد مجاز بوده.')
@section('secondaryLabel', 'تلاش دوباره')
@section('secondaryAction', 'window.location.reload()')

@section('icon')
<svg class="h-10 w-10 sm:h-14 sm:w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <circle cx="12" cy="12" r="9"/>
    <path d="M12 7v5l3.5 2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
@endsection
