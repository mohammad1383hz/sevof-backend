@extends('errors.layout')

@section('code', '404')
@section('title', 'Page not found')
@section('description', 'The page you are looking for was moved or never existed.')
@section('secondaryLabel', 'Go back')
@section('secondaryAction', 'history.back()')

@section('icon')
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <circle cx="11" cy="11" r="7"/>
    <path d="M20 20l-3.5-3.5" stroke-linecap="round"/>
</svg>
@endsection
