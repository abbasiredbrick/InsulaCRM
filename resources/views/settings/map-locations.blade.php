@extends('layouts.app')

@section('title', __('Map Locations'))
@section('page-title', __('Map Locations'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('settings.index') }}">{{ __('Settings') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('Map Locations') }}</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-1">
            {{ __('Communities group the buildings (sub-communities) your units live in. Each building has one Google Maps location shared by all of its units — set or correct it here, rename a building and every one of its units follows, or merge two spellings of the same building into one. Re-importing a sheet never duplicates a building.') }}
        </p>
        @php
            $totalMissing = $buildings->whereNull('map_url')->count();
        @endphp
        <p class="mb-0 mt-1 text-muted">
            <strong>{{ $totalMissing }}</strong> {{ __('building(s) in view still have no location.') }}
        </p>
        <p class="mb-0 mt-1 text-muted">
            {{ __('Buildings also need coordinates before they appear as pins on the shared inventory map. Use') }}
            <code>php artisan maps:geocode-locations</code>{{ __(' to resolve them in bulk; single saves here geocode automatically.') }}
        </p>
    </div>
    <div class="text-nowrap">
        @if($totalMissing > 0)
        <form method="POST" action="{{ route('settings.map-locations.generate-all') }}" class="d-none">
            @csrf
        </form>
        <button type="button" class="btn btn-outline-secondary" onclick="event.preventDefault(); document.querySelector('form[action=&quot;{{ route('settings.map-locations.generate-all') }}&quot;]')?.submit();">
            {{ __('Generate for all missing') }}
        </button>
        @endif
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addBuildingModal">{{ __('Add Building') }}</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addCommunityModal">{{ __('Add Community') }}</button>
        <a href="{{ route('settings.index') }}" class="btn btn-link text-decoration-none text-muted">← {{ __('Back to settings') }}</a>
    </div>
</div>

<form method="GET" action="{{ route('settings.map-locations.index') }}" data-live-filter class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">{{ __('Search communities or buildings') }}</label>
            <input type="text" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="{{ __('Type to filter...') }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('Community') }}</label>
            <select name="community" class="form-select form-select-sm">
                <option value="">All communities</option>
                @foreach($communities as $community)
                    <option value="{{ $community->id }}" @selected($selectedCommunity === (int) $community->id)>{{ $community->name }}</option>
                @endforeach
            </select>
        </div>
        @if($search !== '' || $selectedCommunity !== 0)
        <div class="col-md-auto">
            <a href="{{ route('settings.map-locations.index') }}" class="btn btn-sm btn-link">{{ __('Clear') }}</a>
        </div>
        @endif
    </div>
</form>

<div data-live-results>
    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('Communities') }}</h3>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <tbody>
                            @forelse($communities as $community)
                                <tr>
                                    <td class="w-100">
                                        <a href="{{ route('settings.map-locations.index', ['community' => $community->id, 'q' => $search ?: null]) }}" class="text-decoration-none {{ $selectedCommunity === (int) $community->id ? 'fw-bold' : '' }}">
                                            {{ $community->name }}
                                        </a>
                                        <div class="text-muted small">{{ $community->city ?: __('—') }}</div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-azure-lt">{{ $community->map_locations_count }}</span>
                                    </td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCommunityModal{{ $community->id }}">{{ __('Edit') }}</button>
                                        @if($community->map_locations_count > 0)
                                        <button type="button" class="btn btn-sm btn-outline-danger" disabled title="{{ __('Remove its buildings first') }}">{{ __('Delete') }}</button>
                                        @else
                                        <form method="POST" action="{{ route('settings.map-locations.communities.destroy', $community) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete community \':name\'?', ['name' => $community->name]) }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>

                                <div class="modal fade" id="editCommunityModal{{ $community->id }}" tabindex="-1">
                                    <div class="modal-dialog">
                                        <form method="POST" action="{{ route('settings.map-locations.communities.update', $community) }}">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">{{ __('Edit community') }}</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label required">{{ __('Name') }}</label>
                                                        <input type="text" name="name" class="form-control" value="{{ $community->name }}" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label">{{ __('City') }}</label>
                                                        <input type="text" name="city" class="form-control" value="{{ $community->city }}">
                                                    </div>
                                                    <small class="form-hint">{{ __('Renaming cascades to every building and unit of this community.') }}</small>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-muted text-center py-4">{{ __('No communities yet. Add one, or let a sheet import create buildings and their communities for you.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>{{ __('Building') }}</th>
                                <th>{{ __('Community') }}</th>
                                <th>{{ __('City') }}</th>
                                <th class="text-center">{{ __('Units') }}</th>
                                <th>{{ __('Map pin') }}</th>
                                <th style="min-width:340px">{{ __('Location / Map link') }}</th>
                                <th class="text-nowrap">{{ __('Rename / Merge') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $allTargets = $buildings->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->sub_community])->values();
                            @endphp
                            @forelse($buildings as $b)
                                @php
                                    $cid = (string) ($b->community_id ?? 0);
                                    $mergeTargets = collect($byCommunity[$cid] ?? $allTargets->all())
                                        ->filter(fn ($t) => (int) $t['value'] !== (int) $b->id)
                                        ->values();
                                @endphp
                                <tr>
                                    <td class="fw-bold">
                                        {{ $b->sub_community }}
                                        @if($b->map_url === null)
                                            <span class="badge bg-secondary-lt text-muted ms-1">{{ __('no link') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('settings.map-locations.index', ['community' => $b->community_id ?: '', 'q' => $search ?: null]) }}" class="text-decoration-none {{ $b->community_id ? '' : 'text-muted' }}">
                                            {{ $b->community ?: '—' }}
                                        </a>
                                    </td>
                                    <td>{{ $b->city ?: '—' }}</td>
                                    <td class="text-center">
                                        <span class="badge bg-azure-lt">{{ $b->properties_count }}</span>
                                    </td>
                                    <td>
                                        @if($b->latitude !== null && $b->longitude !== null)
                                            <span class="badge bg-success-lt" title="{{ $b->latitude }}, {{ $b->longitude }}">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="margin-right:2px;"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7z"/></svg>
                                                {{ __('Pinned') }}
                                            </span>
                                        @else
                                            <span class="badge bg-secondary-lt text-muted">{{ __('Not geocoded') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <form action="{{ route('settings.map-locations.set-location', $b) }}" method="POST" class="d-flex gap-2">
                                            @csrf
                                            <input type="text" name="location" class="form-control form-control-sm" value="{{ $b->map_url ?: $b->suggested ?: '' }}" placeholder="{{ __('Google Maps link, or a place name') }}">
                                            <button type="submit" name="mode" value="save" class="btn btn-sm btn-primary text-nowrap">{{ __('Save') }}</button>
                                            <button type="submit" name="mode" value="auto" class="btn btn-sm btn-outline-secondary text-nowrap">{{ __('Generate') }}</button>
                                            <button type="submit" name="mode" value="clear" class="btn btn-sm btn-link text-danger text-decoration-none text-nowrap">{{ __('Clear') }}</button>
                                        </form>
                                        <div class="mt-1" style="font-size:0.75rem;">
                                            @if($b->map_url)
                                                <a href="{{ $b->map_url }}" target="_blank" rel="noopener" class="text-decoration-none">{{ \Illuminate\Support\Str::limit($b->map_url, 80) }}</a>
                                            @elseif($b->suggested)
                                                <span class="text-secondary">{{ __('Suggested:') }}</span>
                                                <a href="{{ $b->suggested }}" target="_blank" rel="noopener" class="text-decoration-none">{{ \Illuminate\Support\Str::limit($b->suggested, 60) }}</a>
                                            @else
                                                <span class="text-muted">{{ __('No location yet.') }}</span>
                                            @endif
                                            @if($b->current_query)
                                                <span class="text-muted d-block">「{{ $b->current_query }}」</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <form action="{{ route('settings.map-locations.rename', $b) }}" method="POST" class="d-flex gap-2 mb-1">
                                            @csrf
                                            <input type="text" name="name" class="form-control form-control-sm" value="{{ $b->sub_community }}" placeholder="{{ __('New name') }}" required>
                                            <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap">{{ __('Rename') }}</button>
                                        </form>
                                        <form action="{{ route('settings.map-locations.merge') }}" method="POST" class="d-flex gap-2">
                                            @csrf
                                            <input type="hidden" name="keep_id" value="{{ $b->id }}">
                                            <select name="discard_id" class="form-select form-select-sm" required>
                                                <option value="">{{ __('Merge into this building...') }}</option>
                                                @foreach($mergeTargets as $target)
                                                    <option value="{{ $target['value'] }}">{{ $target['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-outline-warning text-nowrap">{{ __('Merge') }}</button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        @if((int) $b->properties_count > 0)
                                        <button type="button" class="btn btn-sm btn-outline-danger" disabled title="{{ __('Merge its units first') }}">{{ __('Remove') }}</button>
                                        @else
                                        <form method="POST" action="{{ route('settings.map-locations.buildings.destroy', $b) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove building \':name\'? It has no units.', ['name' => $b->sub_community]) }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                                        </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-muted text-center py-4">
                                        {{ $search !== '' ? __('No buildings match that search.') : __('No buildings yet. Import an availability sheet or add a unit in the inventory.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Add Community modal --}}
<div class="modal fade" id="addCommunityModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('settings.map-locations.communities.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add community') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">{{ __('Name') }}</label>
                        <input type="text" name="name" class="form-control" placeholder="{{ __('e.g. Al Ryada, Marjan Island') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('City') }}</label>
                        <input type="text" name="city" class="form-control" placeholder="{{ __('Abu Dhabi') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Add community') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Add Building modal --}}
<div class="modal fade" id="addBuildingModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('settings.map-locations.buildings.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add building') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">{{ __('Building / tower') }}</label>
                        <input type="text" name="sub_community" class="form-control" placeholder="{{ __('e.g. Marina Heights Tower 2') }}" required>
                        <small class="form-hint">{{ __('If a building with this name (or a similar spelling) already exists, it is reused — nothing is duplicated.') }}</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Community') }}</label>
                        <select name="community_id" class="form-select">
                            <option value="">{{ __('None') }}</option>
                            @foreach($communities as $community)
                                <option value="{{ $community->id }}" @selected($selectedCommunity === (int) $community->id)>{{ $community->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('City') }}</label>
                        <input type="text" name="city" class="form-control" placeholder="{{ __('Abu Dhabi') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Add building') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection