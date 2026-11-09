{{-- Laravel's 403 page plus a way back: after a login the browser may land on
     a page this user may not open (the last user's page). --}}
@extends('errors::minimal')

@section('title', __('Forbidden'))
@section('code', '403')
@section('message')
    {{ __($exception->getMessage() ?: 'Forbidden') }}
    &middot;
    <a href="{{ url('/admin') }}" style="text-decoration: underline">{{ __('Back to dashboard') }}</a>
@endsection
