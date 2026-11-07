@extends('errors.layout')

@section('code', '401')
@section('title', 'اول باید وارد بشی')
@section('description', 'برای دیدن این صفحه باید وارد حسابت بشی. اگه قبلاً وارد شده بودی، ممکنه نشستت منقضی شده باشه.')
@section('footnote', 'برای این بخش نیاز به ورود داری.')
@section('secondaryLabel', 'ورود به حساب')
@section('secondaryAction', "window.location.href='" . url('/login') . "'")

@section('icon')
<svg class="h-10 w-10 sm:h-14 sm:w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <rect x="5" y="11" width="14" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
@endsection
