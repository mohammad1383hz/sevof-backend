@extends('errors.layout')

@section('code', '500')
@section('title', 'Something went wrong on our side')
@section('description', 'Sevof hit an unexpected error. Please try again in a few minutes.')
@section('footnote', 'Internal server error.')
@section('secondaryLabel', 'Try again')
@section('secondaryAction', 'window.location.reload()')

@section('icon')
<svg class="h-10 w-10 sm:h-14 sm:w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
    <path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
@endsection
