@extends('layouts.app')

@section('title', __('Listing Portals'))
@section('page-title', __('Listing Portals'))

@section('content')
<div class="alert alert-info">
    {{ __('Use the list/unlist toggle per unit to record portal status — including units that are already live on a portal after a manual upload.') }}
    {{ __('Pushing via the API requires portal-ready units (') }}<strong>{{ __('Ready to List') }}</strong>/<strong>{{ __('Listed') }}</strong>, {{ __('a RERA permit number and a category') }}<strong>.</strong>
    <span class="d-block mt-1">
        @if(auth()->user()->isAdmin())
        <a href="{{ route('portal-integrations.index') }}" class="btn btn-sm btn-outline-primary">{{ __('Portal Integrations') }}</a>
        @endif
    </span>
</div>

@if(empty($enabled_portals))
<div class="alert alert-secondary">
    {{ __('No portal API integrations are active yet — sharing the CSV/XML feeds and recording statuses works, but push + automatic lead capture need credentials.') }}
    @if(auth()->user()->isAdmin())
    <a href="{{ route('portal-integrations.index') }}" class="alert-link">{{ __('Configure portals') }}</a>
    @endif
</div>
@endif

<div class="row mb-3">
    @foreach([
        ['key' => 'bayut', 'label' => 'Bayut', 'color' => 'primary'],
        ['key' => 'dubizzle', 'label' => 'Dubizzle', 'color' => 'green'],
        ['key' => 'propertyfinder', 'label' => 'Property Finder', 'color' => 'orange'],
    ] as $portal)
    <div class="col-md-4">
        <div class="card card-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted">{{ __($portal['label']) }}</div>
                        <div class="h2 mb-1">{{ $portals[$portal['key']]['live'] }} <span class="small text-muted">{{ __('live') }}</span></div>
                        <div class="text-muted small">{{ $portals[$portal['key']]['units'] }} {{ __('ready to publish') }}</div>
                    </div>
                    <div class="btn-list">
                        @if($portal['key'] === 'propertyfinder')
                            <a href="{{ route('inventory.export.propertyfinder', request()->only('agent')) }}" class="btn btn-sm btn-outline-{{ $portal['color'] }}">{{ __('XML feed') }}</a>
                        @else
                            <a href="{{ route('inventory.export.bayut', request()->only('agent')) }}" class="btn btn-sm btn-outline-{{ $portal['color'] }}">{{ __('CSV feed') }}</a>
                        @endif
                    </div>
                </div>
                @if($portal['key'] === 'dubizzle')
                <div class="form-hint mt-1">{{ __('Bayut & Dubizzle share the PropertyPlus CSV format; download the same feed for both.') }}</div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>

@if(auth()->user()->isAdmin() && $agents->isNotEmpty())
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('inventory.portal') }}" class="row g-2 align-items-end mb-0">
            <div class="col-auto">
                <label class="form-label">{{ __('Agent filter for export') }}</label>
                <div class="input-group">
                    <select name="agent" class="form-select form-select-sm">
                        <option value="">{{ __('All agents') }}</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" {{ request('agent') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Apply') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Units') }}</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Intent') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Permit') }}</th>
                    <th>{{ __('Bayut') }}</th>
                    <th>{{ __('Dubizzle') }}</th>
                    <th>{{ __('PropFinder') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $unit)
                @php
                    $ready = $unit->is_portal_ready;
                    $missing = [];
                    if (!$unit->intent) $missing[] = __('intent');
                    if (!$ready && empty($unit->rera_permit_no)) $missing[] = __('RERA permit');
                    if (!$ready && empty($unit->property_category)) $missing[] = __('category');
                @endphp
                <tr class="{{ !$ready ? 'table-active' : '' }}">
                    <td>
                        <div class="fw-bold">{{ $unit->display_name }}</div>
                        <div class="text-muted small">{{ $unit->sub_community ?: $unit->community }}{{ $unit->unit_no ? ' • Unit ' . $unit->unit_no : '' }}</div>
                        @if(!$ready)
                            <div class="text-danger small">{{ __('Missing:') }} {{ implode(', ', $missing) }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-secondary">{{ __(\App\Models\Property::INTENTS[$unit->intent] ?? $unit->intent) }}</span>
                        @if($unit->availability === 'listed')
                            <span class="badge bg-green">{{ __('Listed') }}</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $unit->price_line }}</td>
                    <td>{{ $unit->rera_permit_no ?: '—' }}</td>
                    <td>@include('inventory._portal-toggle', ['unit' => $unit, 'portal' => ['key' => 'bayut', 'label' => 'Bayut']])</td>
                    <td>@include('inventory._portal-toggle', ['unit' => $unit, 'portal' => ['key' => 'dubizzle', 'label' => 'Dubizzle']])</td>
                    <td>@include('inventory._portal-toggle', ['unit' => $unit, 'portal' => ['key' => 'propertyfinder', 'label' => 'Property Finder']])</td>
                    <td>
                        <a href="{{ route('inventory.show', $unit) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                        @if($ready && in_array('bayut', $enabled_portals))
                        <form method="POST" action="{{ route('inventory.push', [$unit, 'bayut']) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-azure" {{ $unit->bayut_status === 'live' ? 'disabled' : '' }}>{{ __('Push Bayut') }}</button>
                        </form>
                        @endif
                        @if($ready && in_array('propertyfinder', $enabled_portals))
                        <form method="POST" action="{{ route('inventory.push', [$unit, 'propertyfinder']) }}" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-orange" {{ $unit->propertyfinder_status === 'live' ? 'disabled' : '' }}>{{ __('Push PropFinder') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{ __('No units yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Batch mark live helper: link to each unit for per-portal recording --}}
<div class="alert alert-secondary mt-3 mb-0">
    {{ __('Tip: the list/unlist toggle records a unit as Live on a portal instantly (and can mark externally-listed units too). For richer tracking - listing URL and reference - open the unit and use Record.') }}
</div>
@endsection