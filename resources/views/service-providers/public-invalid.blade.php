@extends('layouts.auth')

@section('title', __('Registration link unavailable'))

@section('content')
    @php
        $messages = [
            'used' => 'This registration link has already been used.',
            'expired' => 'This registration link has expired. Please ask the team who invited you for a new link.',
            'revoked' => 'This registration link is no longer valid. Please ask the team who invited you for a new link.',
        ];
        $reason = Session::has('reason') ? Session::get('reason') : ($messages[$state] ?? 'This registration link is not valid.');
    @endphp
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="fs-3 fw-semibold mb-2">{{ __('Registration link unavailable') }}</div>
            <p class="text-muted mb-0">{{ $reason }}</p>
        </div>
    </div>
@endsection