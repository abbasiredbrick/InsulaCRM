@extends('layouts.app')

@section('title', __('Portal Integrations'))
@section('page-title', __('Portal Integrations'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('settings.index') }}">{{ __('Settings') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('Portal Integrations') }}</li>
@endsection

@section('content')
<div class="alert alert-info">
    {{ __('Publish units to Bayut / Dubizzle and Property Finder over their APIs, and receive leads back automatically.') }}
    {{ __('Generate the credentials in your portal account, paste them here, then press Push on any portal-ready unit.') }}
</div>

@foreach([
    ['portal' => 'bayut', 'title' => 'Bayut / Dubizzle', 'desc' => __('Generate an agency Push API Bearer token in Bayut Pro → Settings, and enter the API base URL Bayut provided for your account. One token covers both Bayut and Dubizzle when Dubizzle is enabled.')],
    ['portal' => 'propertyfinder', 'title' => 'Property Finder', 'desc' => __('Generate an Enterprise API key and secret from PF Expert → Developer Resources. Enter your agent public profile ID and a location ID, then publish (units are created as drafts and submitted).')],
] as $box)
@php
    $integration = $integrations[$box['portal']] ?? null;
    $active = $integration?->is_active ?? false;
@endphp
<div class="card mb-3">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">{{ __($box['title']) }}</h3>
            <div class="btn-list">
                @if($integration)
                <form method="POST" action="{{ route('portal-integrations.toggle', $box['portal']) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm {{ $active ? 'btn-outline-danger' : 'btn-outline-green' }}">
                        {{ $active ? __('Disable') : __('Enable') }}
                    </button>
                </form>
                @if($active)
                <form method="POST" action="{{ route('portal-integrations.test', $box['portal']) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('Test connection') }}</button>
                </form>
                <form method="POST" action="{{ route('portal-integrations.sync-leads', $box['portal']) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Sync leads') }}</button>
                </form>
                @endif
                @endif
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="text-muted small mb-3">{{ __($box['desc']) }}</div>

        <form method="POST" action="{{ route('portal-integrations.update', $box['portal']) }}">
            @csrf
            <div class="row g-3">
                @if($box['portal'] === 'bayut')
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Push API Bearer token') }}</label>
                        <input type="text" name="api_token" class="form-control monospace" placeholder="{{ $integration?->maskedToken() ?? '• • • •' }}" autocomplete="off" value="">
                        @if($integration?->api_token)<div class="form-hint">{{ __('Token stored:') }} {{ $integration->maskedToken() }}</div>@endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('API base URL') }}</label>
                        <input type="url" name="base_url" class="form-control monospace" value="{{ $integration?->base_url ?? '' }}" placeholder="https://push.bayut.com">
                        <div class="form-hint">{{ __('Provided with your Bayut Pro Push API documentation.') }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Agent reference') }}</label>
                        <input type="text" name="agent_reference" class="form-control" value="{{ $integration?->agent_reference ?? '' }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Leads API token') }}</label>
                        <input type="password" name="leads_api_token" class="form-control monospace" placeholder="{{ $integration?->maskedLeadsToken() ?? '• • • •' }}" autocomplete="new-password" value="">
                        @if($integration?->leads_api_token)<div class="form-hint">{{ __('Token stored:') }} {{ $integration->maskedLeadsToken() }}</div>@endif
                        <div class="form-hint">{{ __('Overall leads Extract API key. Used to pull leads from Bayut and Dubizzle.') }}</div>
                    </div>
                @else
                    <div class="col-md-6">
                        <label class="form-label">{{ __('API key') }}</label>
                        <input type="text" name="api_token" class="form-control monospace" placeholder="{{ $integration?->maskedToken() ?? '• • • •' }}" autocomplete="off" value="">
                        @if($integration?->api_token)<div class="form-hint">{{ __('Key stored:') }} {{ $integration->maskedToken() }}</div>@endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('API secret') }}</label>
                        <input type="password" name="api_secret" class="form-control monospace" placeholder="{{ $integration?->maskedSecret() ?? '• • • •' }}" autocomplete="new-password" value="">
                        @if($integration?->api_secret)<div class="form-hint">{{ __('Secret stored:') }} {{ $integration->maskedSecret() }}</div>@endif
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Base URL') }}</label>
                        <input type="url" name="base_url" class="form-control monospace" value="{{ $integration?->base_url ?? \App\Services\Portals\PropertyFinderPortalService::DEFAULT_BASE_URL }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Public profile ID') }}</label>
                        <input type="text" name="public_profile_id" class="form-control" value="{{ $integration?->public_profile_id ?? '' }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Default location ID') }}</label>
                        <input type="text" name="default_location_id" class="form-control" value="{{ $integration?->default_location_id ?? '' }}">
                    </div>
                @endif
                <div class="col-md-6">
                    <label class="form-label">{{ __('Inbound lead webhook secret') }}</label>
                    <input type="password" name="webhook_secret" class="form-control monospace" placeholder="{{ $integration?->maskedWebhookSecret() ?? '• • • •' }}" autocomplete="new-password" value="">
                    <div class="form-hint">{{ __('Used to verify lead pushes. Leave empty to keep the stored value.') }}</div>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </form>

        @if($box['portal'] === 'bayut')
        <div class="alert alert-secondary mt-3 mb-0">
            <div class="fw-bold small text-uppercase">{{ __('Bayut / Dubizzle lead reception URL') }}</div>
            <div class="monospace">{{ $integration?->webhook_url ?? route('portal.webhooks.receive', 'bayut', true) }}</div>
            <div class="form-hint">{{ __('Register this URL with Bayut (ps-reply@) so WhatsApp, call and story leads are pushed here as they happen.') }}</div>
        </div>
        @else
        <div class="alert alert-secondary mt-3 mb-0">
            <div class="fw-bold small text-uppercase">{{ __('Property Finder webhook URL') }}</div>
            <div class="monospace">{{ $integration?->webhook_url ?? route('portal.webhooks.receive', 'propertyfinder', true) }}</div>
            <div class="form-hint">{{ __('Subscribe to lead webhook events in PF Enterprise so new enquiries land here instantly.') }}</div>
        </div>
        @endif

        @if($box['portal'] === 'bayut' && $integration?->leads_last_synced_at)
        <div class="alert alert-secondary mt-3 mb-0">
            <div class="fw-bold small text-uppercase">{{ __('Leads pull') }}</div>
            <div class="form-hint">{{ __('Last pulled:') }} {{ $integration->leads_last_synced_at->format('d M Y H:i') }}</div>
        </div>
        @endif

        @if($box['portal'] === 'bayut' && $integration?->leads_last_error)
        <div class="alert alert-warning mt-3 mb-0">{{ $integration->leads_last_error }}</div>
        @endif

        @if($integration?->last_error)
        <div class="alert alert-danger mt-3 mb-0">{{ $integration->last_error }}</div>
        @endif
    </div>
</div>
@endforeach

<div class="dialog dialog-department">
    <div class="dialog-body">
        <a href="{{ route('inventory.portal') }}" class="btn btn-outline-secondary">{{ __('Back to Listing Portals') }}</a>
    </div>
</div>
@endsection

@push('styles')
<style>
.monospace { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem; }
</style>
@endpush