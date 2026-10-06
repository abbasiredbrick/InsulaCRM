@extends('layouts.app')

@section('title', $provider->company_name)
@section('page-title', $provider->company_name)

@section('content')
@php
    $statusColors = ['pending' => 'bg-yellow-lt', 'changes_requested' => 'bg-orange-lt', 'approved' => 'bg-green-lt', 'rejected' => 'bg-red-lt'];
@endphp

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Company') }}</h3>
                <div class="card-actions">
                    <span class="badge {{ $statusColors[$provider->status] ?? 'bg-secondary-lt' }}">{{ $provider->statusLabel() }}</span>
                </div>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('Category') }}</dt>
                    <dd class="col-sm-8">{{ __($provider->categoryLabel()) }}</dd>
                    <dt class="col-sm-4">{{ __('Trade license') }}</dt>
                    <dd class="col-sm-8">{{ $provider->trade_license_number }}</dd>
                    <dt class="col-sm-4">{{ __('Services offered') }}</dt>
                    <dd class="col-sm-8">{{ $provider->services_offered ?: '—' }}</dd>
                    <dt class="col-sm-4">{{ __('Area') }}</dt>
                    <dd class="col-sm-8">{{ $provider->city ?: '—' }}</dd>
                    <dt class="col-sm-4">{{ __('Website') }}</dt>
                    <dd class="col-sm-8">
                        @if($provider->website)
                            <a href="{{ $provider->website }}" target="_blank" rel="noopener">{{ $provider->website }}</a>
                        @else
                            —
                        @endif
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Representative') }}</h3>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">{{ __('Name') }}</dt>
                    <dd class="col-sm-8">{{ $provider->representative_name }}</dd>
                    <dt class="col-sm-4">{{ __('Emirates ID') }}</dt>
                    <dd class="col-sm-8">
                        {{ $provider->representative_emirates_id }}
                        @if($provider->documents->whereStrict('doc_type', 'emirates_id')->isNotEmpty())
                            <span class="badge bg-green-lt ms-1">{{ __('verified copy on file') }}</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Documents') }}</h3>
            </div>
            <div class="card-body">
                @forelse($provider->documents as $document)
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/></svg>
                            <div>
                                <div class="fw-semibold">{{ $document->typeLabel() }}</div>
                                <div class="text-muted small">{{ $document->original_name }} • {{ $document->humanSize() }}</div>
                            </div>
                        </div>
                        <a href="{{ route('service-providers.documents.download', $document) }}" class="btn btn-sm btn-outline-secondary">{{ __('Download') }}</a>
                    </div>
                @empty
                    <div class="text-muted">{{ __('No documents on file.') }}</div>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Application history') }}</h3>
            </div>
            <div class="card-body">
                @forelse($provider->reviews as $review)
                    <div class="d-flex gap-3 py-2 border-bottom">
                        <div class="flex-shrink-0 text-muted small">{{ $review->created_at->format('M j, Y g:i A') }}</div>
                        <div>
                            <div class="fw-semibold">{{ $review->actionLabel() }} @if($review->reviewer) <span class="text-muted small">— {{ $review->reviewer->name }}</span>@endif</div>
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
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('Contact') }}</h3>
            </div>
            <div class="card-body d-grid gap-2">
                @if($provider->mobile)
                    <a href="tel:{{ $provider->mobile }}" class="btn btn-outline-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2"/></svg>
                        {{ __('Call') }} {{ $provider->mobile }}
                    </a>
                @endif
                @if($provider->email)
                    <a href="mailto:{{ $provider->email }}" class="btn btn-outline-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>
                        {{ __('Email') }} {{ $provider->email }}
                    </a>
                @endif
                @if($provider->website)
                    <a href="{{ $provider->website }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a15 15 0 0 1 4 9a15 15 0 0 1 -4 9a15 15 0 0 1 -4 -9a15 15 0 0 1 4 -9"/></svg>
                        {{ __('Visit website') }}
                    </a>
                @endif
            </div>
        </div>

        @if(auth()->user()->isAdmin() && $provider->isUnderReview())
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('Review') }}</h3>
                </div>
                <div class="card-body">
                    <a href="{{ route('service-providers.review') }}" class="btn btn-sm btn-primary w-100">{{ __('Open review queue') }}</a>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection