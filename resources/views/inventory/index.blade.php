@extends('layouts.app')

@section('title', __('Inventory'))
@section('page-title', __('Inventory'))

@section('content')
{{-- KPI Cards --}}
<div class="row mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-primary text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M5 21v-14l8 -4v18"/><path d="M19 21v-10l-6 -4"/><path d="M9 9l0 .01"/><path d="M9 12l0 .01"/><path d="M9 15l0 .01"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['total'] }}</div>
                        <div class="text-muted">{{ __('Total Units') }}</div>
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
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M9 8l1 0"/><path d="M9 12l1 0"/><path d="M9 16l1 0"/><path d="M14 8l1 0"/><path d="M14 12l1 0"/><path d="M14 16l1 0"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['active'] }}</div>
                        <div class="text-muted">{{ __('Active Listings') }}</div>
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
                        <span class="bg-blue text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3v18"/><path d="M16 7l-4 4l-4 -4"/><path d="M4 21h16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['for_rent'] }}</div>
                        <div class="text-muted">{{ __('For Rent') }}</div>
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
                        <div class="font-weight-medium">{{ $kpis['for_sale'] }}</div>
                        <div class="text-muted">{{ __('For Sale') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Units') }}</h3>
        <div class="card-actions">
            <a href="{{ route('inventory.portal') }}" class="btn btn-sm btn-outline-secondary">{{ __('Portals') }}</a>
            <a href="{{ route('inventory.create') }}" class="btn btn-sm btn-primary">{{ __('New Unit') }}</a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('inventory.index') }}" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">{{ __('Intent') }}</label>
                <select name="intent" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Property::INTENTS as $key => $label)
                        <option value="{{ $key }}" {{ request('intent') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Availability') }}</label>
                <select name="availability" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Property::AVAILABILITIES as $key => $label)
                        <option value="{{ $key }}" {{ request('availability') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Stock') }}</label>
                <select name="market_class" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Property::MARKET_CLASSES as $key => $label)
                        <option value="{{ $key }}" {{ request('market_class') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Category') }}</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                        <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
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
                <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Title, address, community, unit...') }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                <a href="{{ route('inventory.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Intent') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Availability') }}</th>
                    <th>{{ __('Leads') }}</th>
                    <th>{{ __('Agent') }}</th>
                    <th>{{ __('Portals') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $unit)
                <tr>
                    <td>
                        <div class="fw-bold">{{ $unit->display_name }}</div>
                        <div class="text-muted small">
                            {{ $unit->sub_community ?: $unit->community }}{{ $unit->sub_community && $unit->community ? ', ' . $unit->community : '' }}
                            @if($unit->bedrooms || $unit->bathrooms)
                                • @if($unit->bedrooms){{ $unit->bedrooms }} {{ __('bd') }}@endif
                                @if($unit->bathrooms) / {{ $unit->bathrooms }} {{ __('ba') }}@endif
                            @endif
                            @if($unit->square_footage) • {{ \App\Helpers\TenantFormatHelper::area($unit->square_footage) }}@endif
                        </div>
                        <div class="text-muted small">{{ $unit->unit_no ? __('Unit') . ' ' . $unit->unit_no . ' • ' : '' }}{{ __(\App\Models\Property::MARKET_CLASSES[$unit->market_class] ?? $unit->market_class) }}</div>
                    </td>
                    <td>
                        @php
                            $intentColors = ['rent' => 'blue', 'sale' => 'green', 'both' => 'purple'];
                        @endphp
                        <span class="badge bg-{{ $intentColors[$unit->intent] ?? 'secondary' }}">{{ __(\App\Models\Property::INTENTS[$unit->intent] ?? $unit->intent) }}</span>
                    </td>
                    <td class="text-nowrap">{{ $unit->price_line }}</td>
                    <td>
                        @php
                            $availabilityColors = [
                                'draft' => 'secondary', 'ready_to_list' => 'azure',
                                'listed' => 'green', 'reserved' => 'orange',
                                'leased' => 'blue', 'sold' => 'purple', 'unlisted' => 'dark',
                            ];
                        @endphp
                        <span class="badge bg-{{ $availabilityColors[$unit->availability] ?? 'secondary' }}">{{ __(\App\Models\Property::AVAILABILITIES[$unit->availability] ?? $unit->availability) }}</span>
                    </td>
                    <td>
                        @forelse($unit->leads as $linkedLead)
                            <a href="{{ route('leads.show', $linkedLead) }}" class="badge bg-cyan-lt text-decoration-none me-1">{{ $linkedLead->full_name ?: ($linkedLead->email ?: '#' . $linkedLead->id) }}</a>
                        @empty
                            <span class="text-muted">-</span>
                        @endforelse
                    </td>
                    <td>{{ $unit->assignedAgent->name ?? '-' }}</td>
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
                    <td class="text-nowrap">
                        <a href="{{ route('inventory.show', $unit) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                        <a href="{{ route('inventory.edit', $unit) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">{{ __('No units yet. Add your first unit to start building inventory.') }}</td>
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