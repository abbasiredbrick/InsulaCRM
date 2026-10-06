@extends('layouts.app')

@section('title', __('Registration Links'))
@section('page-title', __('Registration Links'))

@section('page-actions')
    <a href="{{ route('service-providers.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Directory') }}</a>
    @if(auth()->user()->isAdmin())
        <a href="{{ route('service-providers.review') }}" class="btn btn-sm btn-outline-secondary">{{ __('Review queue') }}</a>
    @endif
@endsection

@section('content')
@php
    $stateColors = ['active' => 'bg-green-lt', 'used' => 'bg-blue-lt', 'expired' => 'bg-secondary-lt', 'revoked' => 'bg-red-lt'];
@endphp

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">{{ __('New registration link') }}</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('service-providers.links.store') }}" class="row g-3">
                    @csrf
                    <div class="col-12">
                        <label class="form-label">{{ __('Service provider name') }}</label>
                        <input type="text" name="provider_name" class="form-control" value="{{ old('provider_name') }}" placeholder="{{ __('Optional') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('Service provider email') }}</label>
                        <input type="email" name="provider_email" class="form-control" value="{{ old('provider_email') }}" placeholder="{{ __('Optional — lets you email the link directly') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('Internal note') }}</label>
                        <input type="text" name="internal_note" class="form-control" value="{{ old('internal_note') }}" placeholder="{{ __('e.g. Contact at Emirates Hills') }}">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary w-100">{{ __('Generate link') }}</button>
                    </div>
                </form>
                <div class="alert alert-info mt-3 mb-0 small">
                    {{ __('Each link is valid for 30 days and can be used once. Share a separate link with every provider.') }}
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Sent links') }}</h3>
            </div>
            <div class="card-body">
                @if(session('created_link_url'))
                    <div class="alert alert-success">
                        <div class="fw-semibold mb-1">{{ __('Link created') }}</div>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-sm" id="created-link-value" value="{{ session('created_link_url') }}" readonly>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText(document.getElementById('created-link-value').value).then(()=>showToast('{{ __('Link copied') }}'))">{{ __('Copy') }}</button>
                        </div>
                    </div>
                @endif

                @forelse($links as $link)
                    <div class="border-bottom py-3">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <div>
                                <div class="fw-semibold">{{ $link->provider_name ?: __('Unnamed provider') }}</div>
                                <div class="text-muted small">
                                    {{ $link->provider_email ?: __('No email') }}
                                    @if($link->internal_note) • {{ $link->internal_note }}@endif
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge {{ $stateColors[$link->state()] ?? 'bg-secondary-lt' }}">
                                    {{ __(ucfirst($link->state())) }} @until($link->isUsable()) • {{ $link->expires_at->format('M j') }}@enduntil
                                </span>
                                @if($link->isUsable())
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText('{{ $link->url() }}').then(()=>showToast('{{ __('Link copied') }}'))">{{ __('Copy') }}</button>
                                    @if($link->provider_email)
                                        <form method="POST" action="{{ route('service-providers.links.send', $link) }}" onsubmit="return confirm('{{ __('Email this link to the provider?') }}')">
                                            @csrf
                                            <input type="hidden" name="provider_email" value="{{ $link->provider_email }}">
                                            <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Email') }}</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('service-providers.links.revoke', $link) }}" onsubmit="return confirm('{{ __('Revoke this link?') }}')">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Revoke') }}</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <div class="text-muted small mt-1 text-break">{{ $link->url() }}</div>
                    </div>
                @empty
                    <div class="text-muted">{{ __('No links have been generated yet.') }}</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection