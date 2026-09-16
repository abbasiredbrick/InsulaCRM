@extends('layouts.app')

@section('title', __('Listed Units'))
@section('page-title', __('Listed Units'))

@section('content')
{{-- KPI Cards (desktop/tablet only — hidden in the mobile PWA) --}}
<div class="row mb-3 d-none d-md-flex">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-primary text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M9 8l1 0"/><path d="M9 12l1 0"/><path d="M9 16l1 0"/><path d="M14 8l1 0"/><path d="M14 12l1 0"/><path d="M14 16l1 0"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['listed'] }}</div>
                        <div class="text-muted">{{ __('Listed Units') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-green text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['live'] }}</div>
                        <div class="text-muted">{{ __('Live on a Portal') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-azure text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3v18"/><path d="M16 7l-4 4l-4 -4"/><path d="M4 21h16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['ready'] }}</div>
                        <div class="text-muted">{{ __('Ready to List') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-orange text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3v18"/><path d="M16 7l-4 4l-4 -4"/><path d="M4 21h16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['avg_rent'] ? Fmt::currency($kpis['avg_rent']) : '-' }}</div>
                        <div class="text-muted">{{ __('Avg Rent / year') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Listed Units') }}</h3>
        <div class="card-actions">
            <a href="{{ route('listings.mandates') }}" class="btn btn-sm btn-outline-secondary">{{ __('Sales Mandates') }}</a>
            <a href="{{ route('inventory.portal') }}" class="btn btn-sm btn-outline-secondary">{{ __('Portals') }}</a>
            @if(auth()->user()->isAdmin())
                <a href="{{ route('listings.readiness') }}" class="btn btn-sm btn-outline-secondary">{{ __('Bayut Readiness') }}</a>
            @endif
            <a href="{{ route('inventory.create') }}" class="btn btn-sm btn-primary">{{ __('New Unit') }}</a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('listings.index') }}" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">{{ __('Source') }}</label>
                <select name="source" class="form-select form-select-sm">
                    <option value="">{{ __('All Sources') }}</option>
                    @foreach($sources as $s)
                        <option value="{{ $s->id }}" {{ request('source') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Building') }}</label>
                <select name="building" class="form-select form-select-sm">
                    <option value="">{{ __('All Buildings') }}</option>
                    @foreach($buildings as $b)
                        <option value="{{ $b }}" {{ request('building') === $b ? 'selected' : '' }}>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Intent') }}</label>
                <select name="intent" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Property::INTENTS as $key => $label)
                        <option value="{{ $key }}" {{ request('intent') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            @if(auth()->user()->isAdmin() && $agents->isNotEmpty())
            <div class="col-md-2">
                <label class="form-label">{{ __('Agent') }}</label>
                <select name="agent" class="form-select form-select-sm">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-md-3">
                <label class="form-label">{{ __('Search') }}</label>
                <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Title, unit, building, community...') }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                <a href="{{ route('listings.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Fees') }}</th>
                    <th>{{ __('Source') }}</th>
                    <th>{{ __('Portals') }}</th>
                    <th>{{ __('Listed Since') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $unit)
                @php
                    $portalDates = [$unit->bayut_listed_at, $unit->dubizzle_listed_at, $unit->propertyfinder_listed_at];
                    $listedOn = $unit->listed_at ?: collect($portalDates)->filter()->max();
                @endphp
                <tr>
                    <td>
                        <div class="fw-bold">{{ $unit->display_name }}</div>
                        @if($unit->unit_no)
                            <div class="text-muted small">{{ __('Unit') }} {{ $unit->unit_no }}</div>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <strong>{{ $unit->price_line }}</strong>
                    </td>
                    <td>
                        @if($unit->deposit_amount || $unit->admin_fee || $unit->tawtheeq_fee)
                            <div class="text-nowrap">
                                @if($unit->deposit_amount)<span class="text-muted">{{ __('Dep') }} {{ Fmt::currency($unit->deposit_amount) }}</span>@endif
                                @if($unit->admin_fee)<span class="text-muted ms-1">{{ __('Adm') }} {{ Fmt::currency($unit->admin_fee) }}</span>@endif
                                @if($unit->tawtheeq_fee)<span class="text-muted ms-1">{{ __('Taw') }} {{ Fmt::currency($unit->tawtheeq_fee) }}</span>@endif
                            </div>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>{{ $unit->availabilitySource->name ?? '-' }}</td>
                    <td>
                        <span class="me-1" title="Bayut">
                            <span class="badge {{ $unit->bayut_status === 'live' ? 'bg-green' : ($unit->bayut_status === 'removed' ? 'bg-danger' : 'bg-secondary') }}" style="font-size:.65rem;">B</span>
                        </span>
                        <span class="me-1" title="Dubizzle">
                            <span class="badge {{ $unit->dubizzle_status === 'live' ? 'bg-green' : ($unit->dubizzle_status === 'removed' ? 'bg-danger' : 'bg-secondary') }}" style="font-size:.65rem;">D</span>
                        </span>
                        <span title="Property Finder">
                            <span class="badge {{ $unit->propertyfinder_status === 'live' ? 'bg-green' : ($unit->propertyfinder_status === 'removed' ? 'bg-danger' : 'bg-secondary') }}" style="font-size:.65rem;">PF</span>
                        </span>
                    </td>
                    <td class="text-nowrap">{{ $listedOn ? $listedOn->format('d M Y') : '-' }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('inventory.show', $unit) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                        <a href="{{ route('inventory.edit', $unit) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">{{ __('No listed units found. Set a unit to "Listed" in the inventory when it goes live on the portals.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($units->hasPages())
    <div class="card-footer d-flex align-items-center">
        {{ $units->appends(request()->query())->links('vendor.pagination.tabler') }}
    </div>
    @endif
</div>
@endsection