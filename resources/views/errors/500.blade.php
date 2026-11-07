@extends('errors.layout')

@section('code', '500')
@section('title', 'یه مشکلی از سمت ما پیش اومد')
@section('description', 'سرورهای یوتیکس با یه خطای غیرمنتظره مواجه شدن. تیم فنی مطلع شده، لطفاً چند دقیقه دیگه دوباره امتحان کن.')
@section('footnote', 'خطای داخلی سرور.')
@section('secondaryLabel', 'تلاش دوباره')
@section('secondaryAction', 'window.location.reload()')

@section('icon')
<svg class="h-10 w-10 sm:h-14 sm:w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
@endsection
