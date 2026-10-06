@extends('layouts.auth')

@section('title', __('Edit your Application'))

@section('content')
    <div class="mb-3 text-center">
        <h1 class="mb-1">{{ __('Edit your Application') }}</h1>
        <div class="text-muted">{{ $provider->company_name }}</div>
    </div>

    @if($comment)
        <div class="alert alert-warning mb-3">
            <div class="fw-semibold">{{ __('Changes requested by the review team:') }}</div>
            <div>{{ $comment }}</div>
        </div>
    @endif

    @include('service-providers._application_form', [
        'action' => $action,
        'provider' => $provider,
    ])
@endsection