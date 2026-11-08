@extends('errors.layout')

@section('code', '429')
@section('title', 'Slow down a little')
@section('description', 'You sent too many requests in a short time. Wait a moment and try again.')
@section('footnote', 'Too many requests.')
@section('secondaryLabel', 'Try again')
@section('secondaryAction', 'window.location.reload()')

@section('icon')
<svg class="h-10 w-10 sm:h-14 sm:w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <circle cx="12" cy="12" r="9"/>
    <path d="M12 7v5l3.5 2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
@endsection
