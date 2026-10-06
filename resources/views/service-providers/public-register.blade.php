@extends('layouts.auth')

@section('title', __('Service Provider Registration'))

@section('content')
    <div class="mb-3 text-center">
        <h1 class="mb-1">{{ __('Register as a Service Provider') }}</h1>
        <div class="text-muted">{{ $link->tenant->name }} invites your company to join its approved service providers.</div>
    </div>

    @include('service-providers._application_form', [
        'action' => $action,
        'provider' => null,
    ])

    <div class="text-center text-muted mt-3">
        <small>{{ __('Questions? Reply to the invitation email you received.') }}</small>
    </div>
@endsection