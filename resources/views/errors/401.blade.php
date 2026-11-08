@extends('errors.layout')

@section('code', '401')
@section('title', 'Please sign in')
@section('description', 'You need to be signed in to see this page. If you were already signed in, your session may have expired.')
@section('footnote', 'Sign-in required.')
@section('secondaryLabel', 'Sign in')
@section('secondaryAction', "window.location.href='" . rtrim((string) config('app.front_url'), '/') . "/login'")

@section('icon')
<svg class="h-10 w-10 sm:h-14 sm:w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <rect x="5" y="11" width="14" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
@endsection
