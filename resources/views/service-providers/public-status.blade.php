@extends('layouts.auth')

@section('title', __('Application Status'))

@section('content')
    <div class="mb-4 text-center">
        <h1 class="mb-1">{{ __('Application Status') }}</h1>
        <div class="text-muted">{{ $provider->company_name }}</div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @php
        $colors = ['pending' => 'bg-yellow-lt', 'changes_requested' => 'bg-orange-lt', 'approved' => 'bg-green-lt', 'rejected' => 'bg-red-lt'];
    @endphp

    <div class="card mb-3">
        <div class="card-body d-flex align-items-center justify-content-between gap-2">
            <div>
                <div class="fs-3 fw-bold">{{ $provider->company_name }}</div>
                <div class="text-muted">{{ $provider->categoryLabel() }} • {{ $provider->city ?: '—' }}</div>
            </div>
            <span class="badge {{ $colors[$provider->status] ?? 'bg-secondary-lt' }} fs-6">{{ $provider->statusLabel() }}</span>
        </div>
    </div>

    @if($provider->status === 'pending' || $provider->status === 'approving')
        <div class="alert alert-info">{{ __('Your application is under review. You will be emailed when a decision is made.') }}</div>
    @endif

    @if($provider->status === 'changes_requested')
        <div class="alert alert-warning">
            {{ __('The review team asked for changes. Review the comments below and submit your updated application.') }}
        </div>
        <a href="{{ route('service-providers.public.edit', $provider->edit_token) }}" class="btn btn-primary w-100 mb-3">{{ __('Edit and resubmit') }}</a>
    @endif

    @if($provider->status === 'approved')
        <div class="alert alert-success">{{ __('Approved. Our team can now contact you for your services.') }}</div>
    @endif

    @if($provider->status === 'rejected')
        <div class="alert alert-danger">{{ __('Sorry — your application was not approved this time.') }}</div>
    @endif

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('History') }}</h3>
        </div>
        <div class="card-body">
            @forelse($provider->reviews as $review)
                <div class="d-flex gap-3 py-2 border-bottom">
                    <div class="flex-shrink-0 text-muted small">{{ $review->created_at->format('M j, Y g:i A') }}</div>
                    <div>
                        <div class="fw-semibold">{{ $review->actionLabel() }}</div>
                        @if($review->comment)
                            <div class="text-muted">{{ $review->comment }}</div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-muted">{{ __('No activity yet.') }}</div>
            @endforelse
        </div>
    </div>
@endsection