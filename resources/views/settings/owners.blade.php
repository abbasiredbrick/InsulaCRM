@extends('layouts.app')

@section('title', __('Owners'))
@section('page-title', __('Owners'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('settings.index') }}">{{ __('Settings') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('Owners') }}</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-1">
            {{ __('City → Community → Sub-community is where a unit is; this is who owns it. Each owner is stored once (name, phone, email, office address and a pinned office location) and units link to them, so the same person is never retyped. The office location opens on a map and drives via Google Maps or Waze for a meeting.') }}
        </p>
        <p class="mb-0 mt-1 text-muted">
            {{ __('De-duplication is normalized-exact on the name: adding an owner that already exists reuses the existing record instead of creating a second one.') }}
        </p>
    </div>
    <div class="text-nowrap">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOwnerModal">{{ __('Add Owner') }}</button>
        <a href="{{ route('settings.index') }}" class="btn btn-link text-decoration-none text-muted">← {{ __('Back to settings') }}</a>
    </div>
</div>

<form method="GET" action="{{ route('settings.owners.index') }}" data-live-filter class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">{{ __('Search owners') }}</label>
            <input type="text" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="{{ __('Type a name, phone, email or address...') }}">
        </div>
        @if($search !== '')
        <div class="col-md-auto">
            <a href="{{ route('settings.owners.index') }}" class="btn btn-sm btn-link">{{ __('Clear') }}</a>
        </div>
        @endif
    </div>
</form>

<div data-live-results>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ __('Owner') }}</th>
                        <th>{{ __('Phone') }}</th>
                        <th>{{ __('Email') }}</th>
                        <th>{{ __('Office address') }}</th>
                        <th>{{ __('Office location') }}</th>
                        <th class="text-center">{{ __('Units') }}</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($owners as $owner)
                        <tr>
                            <td><strong>{{ $owner->name }}</strong></td>
                            <td>{{ $owner->phone ?: '—' }}</td>
                            <td>{{ $owner->email ?: '—' }}</td>
                            <td>{{ $owner->office_address ?: '—' }}</td>
                            <td class="text-nowrap">
                                @php
                                    $mapUrl = $owner->mapsSearchUrl();
                                    $directionsUrl = $owner->mapsDirectionsUrl();
                                    $wazeUrl = $owner->wazeUrl();
                                @endphp
                                @if($mapUrl)
                                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="{{ __('Open office on Google Maps') }}">{{ __('Map') }}</a>
                                @endif
                                @if($directionsUrl)
                                    <a href="{{ $directionsUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="{{ __('Get directions (Google Maps)') }}">{{ __('Directions') }}</a>
                                @endif
                                @if($wazeUrl)
                                    <a href="{{ $wazeUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="{{ __('Drive with Waze') }}">{{ __('Waze') }}</a>
                                @endif
                                @if(! $mapUrl && ! $directionsUrl && ! $wazeUrl)
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $owner->properties_count }}</td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editOwnerModal{{ $owner->id }}">{{ __('Edit') }}</button>
                                @if($owner->properties_count > 0)
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="{{ __(':count unit(s) still point at this owner.', ['count' => $owner->properties_count]) }}">{{ __('Delete') }}</button>
                                @else
                                    <form method="POST" action="{{ route('settings.owners.destroy', $owner) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove owner ":name"?', ['name' => $owner->name]) }}')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">{{ __('No owners yet. Add one, or pick "Create" from the owner field on a unit.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@foreach($owners as $owner)
    <div class="modal modal-blur fade" id="editOwnerModal{{ $owner->id }}" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('settings.owners.update', $owner) }}">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Edit owner') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">{{ __('Name') }}</label>
                                <input type="text" name="name" class="form-control" value="{{ $owner->name }}" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Phone') }}</label>
                                <input type="text" name="phone" class="form-control" value="{{ $owner->phone }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Email') }}</label>
                                <input type="email" name="email" class="form-control" value="{{ $owner->email }}">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">{{ __('Office address') }}</label>
                                <input type="text" name="office_address" class="form-control" value="{{ $owner->office_address }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">{{ __('Office location') }}</label>
                                <input type="text" name="office_location" class="form-control" value="{{ $owner->map_query }}" placeholder="{{ __('Paste a Google Maps link or type the office address') }}">
                                <div class="form-hint">
                                    {{ __('Leave blank to keep the current location.') }}
                                    @if($owner->mapsSearchUrl())
                                        <a href="{{ $owner->mapsSearchUrl() }}" target="_blank" rel="noopener">{{ __('Current location') }}</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Save changes') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach

<x-add-owner-modal :action="route('settings.owners.store')" />
@endsection
