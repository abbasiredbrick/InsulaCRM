@extends('layouts.app')

@section('title', __('Inventory'))
@section('page-title', __('Inventory'))

@section('content')
{{-- KPI Cards (desktop/tablet only — hidden in the mobile PWA) --}}
<div class="row mb-3 d-none d-md-flex">
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
            <button type="button" class="btn btn-sm btn-outline-primary" id="copy-share-link" title="{{ __('Copy a client-facing link of the current view') }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z"/><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1"/></svg>
                {{ __('Copy share link') }}
            </button>
            <a href="{{ route('inventory.portal') }}" class="btn btn-sm btn-outline-secondary">{{ __('Portals') }}</a>
            <a href="{{ route('inventory.create') }}" class="btn btn-sm btn-primary">{{ __('New Unit') }}</a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('inventory.index') }}" id="inventory-search-form">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Search') }}</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Title, address, community, unit...') }}">
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
                <div class="col-md-2">
                    <label class="form-label">{{ __('Availability') }}</label>
                    <select name="availability" class="form-select form-select-sm">
                        <option value="">{{ __('All') }}</option>
                        @foreach(\App\Models\Property::AVAILABILITIES as $key => $label)
                            <option value="{{ $key }}" {{ request('availability') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Search') }}</button>
                    <a href="{{ route('inventory.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#advancedSearch">
                        {{ __('Advanced') }}
                    </button>
                </div>
            </div>

            <div id="advancedSearch" class="collapse mt-3 {{ request()->hasAny(['market_class','category','furnishing','rent_period','community','sub_community','building_no','floor_no','bedrooms_min','bedrooms_max','bathrooms','rent_min','rent_max','sale_min','sale_max','area_min','area_max','developer_name','rera_permit_no','title_deed_no','plot_no','owner_name','has_photos','has_portal_live','parking','source','agent']) ? 'show' : '' }}">
                <div class="border rounded p-3 bg-light">
                    <div class="row g-2 align-items-end">
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
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Furnishing') }}</label>
                            <select name="furnishing" class="form-select form-select-sm">
                                <option value="">{{ __('All') }}</option>
                                @foreach(\App\Models\Property::FURNISHING as $key => $label)
                                    <option value="{{ $key }}" {{ request('furnishing') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Rent period') }}</label>
                            <select name="rent_period" class="form-select form-select-sm">
                                <option value="">{{ __('All') }}</option>
                                @foreach(\App\Models\Property::RENT_PERIODS as $key => $label)
                                    <option value="{{ $key }}" {{ request('rent_period') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Source') }}</label>
                            <select name="source" class="form-select form-select-sm">
                                <option value="">{{ __('All') }}</option>
                                @foreach($sources as $s)
                                    <option value="{{ $s->id }}" {{ request('source') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if($canFilterByAgent && $agents->isNotEmpty())
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
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Community') }}</label>
                            <select name="community" class="form-select form-select-sm">
                                <option value="">{{ __('All') }}</option>
                                @foreach($communities as $c)
                                    <option value="{{ $c }}" {{ request('community') === $c ? 'selected' : '' }}>{{ $c }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Building') }}</label>
                            <select name="sub_community" class="form-select form-select-sm">
                                <option value="">{{ __('All') }}</option>
                                @foreach($subCommunities as $s)
                                    <option value="{{ $s }}" {{ request('sub_community') === $s ? 'selected' : '' }}>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Building No.') }}</label>
                            <input type="text" name="building_no" class="form-control form-control-sm" value="{{ request('building_no') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Floor No.') }}</label>
                            <input type="text" name="floor_no" class="form-control form-control-sm" value="{{ request('floor_no') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Beds from') }}</label>
                            <select name="bedrooms_min" class="form-select form-select-sm">
                                <option value="" @selected(! request()->has('bedrooms_min'))>{{ __('Any') }}</option>
                                @for($i = 0; $i <= 6; $i++)
                                    <option value="{{ $i }}" {{ (string) request('bedrooms_min') === (string) $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Beds to') }}</label>
                            <select name="bedrooms_max" class="form-select form-select-sm">
                                <option value="" @selected(! request()->has('bedrooms_max'))>{{ __('Any') }}</option>
                                @for($i = 0; $i <= 6; $i++)
                                    <option value="{{ $i }}" {{ (string) request('bedrooms_max') === (string) $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Baths') }}</label>
                            <select name="bathrooms" class="form-select form-select-sm">
                                <option value="">{{ __('Any') }}</option>
                                @for($i = 1; $i <= 6; $i++)
                                    <option value="{{ $i }}" {{ request('bathrooms') == $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Parking ≥') }}</label>
                            <select name="parking" class="form-select form-select-sm">
                                <option value="">{{ __('Any') }}</option>
                                @for($i = 1; $i <= 4; $i++)
                                    <option value="{{ $i }}" {{ request('parking') == $i ? 'selected' : '' }}>{{ $i }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Rent min (AED)') }}</label>
                            <input type="number" name="rent_min" min="0" step="1000" class="form-control form-control-sm" value="{{ request('rent_min') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Rent max (AED)') }}</label>
                            <input type="number" name="rent_max" min="0" step="1000" class="form-control form-control-sm" value="{{ request('rent_max') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Sale min (AED)') }}</label>
                            <input type="number" name="sale_min" min="0" step="1000" class="form-control form-control-sm" value="{{ request('sale_min') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Sale max (AED)') }}</label>
                            <input type="number" name="sale_max" min="0" step="1000" class="form-control form-control-sm" value="{{ request('sale_max') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Area from (sqft)') }}</label>
                            <input type="number" name="area_min" min="0" step="10" class="form-control form-control-sm" value="{{ request('area_min') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Area to (sqft)') }}</label>
                            <input type="number" name="area_max" min="0" step="10" class="form-control form-control-sm" value="{{ request('area_max') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Developer') }}</label>
                            <input type="text" name="developer_name" class="form-control form-control-sm" value="{{ request('developer_name') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('RERA permit') }}</label>
                            <input type="text" name="rera_permit_no" class="form-control form-control-sm" value="{{ request('rera_permit_no') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Title deed no.') }}</label>
                            <input type="text" name="title_deed_no" class="form-control form-control-sm" value="{{ request('title_deed_no') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Plot no.') }}</label>
                            <input type="text" name="plot_no" class="form-control form-control-sm" value="{{ request('plot_no') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Owner') }}</label>
                            <input type="text" name="owner_name" class="form-control form-control-sm" value="{{ request('owner_name') }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="has_photos" value="1" {{ request('has_photos') ? 'checked' : '' }}>
                                <span class="form-check-label">{{ __('Has photos') }}</span>
                            </label>
                        </div>
                        <div class="col-md-2">
                            <label class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="has_portal_live" value="1" {{ request('has_portal_live') ? 'checked' : '' }}>
                                <span class="form-check-label">{{ __('Live on a portal') }}</span>
                            </label>
                        </div>
                    </div>
                </div>
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
                        @if($unit->unit_no)
                            <div class="text-muted small">{{ __('Unit') }} {{ $unit->unit_no }}</div>
                        @endif
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

@push('scripts')
<script>
document.getElementById('copy-share-link')?.addEventListener('click', function () {
    const form = document.getElementById('inventory-search-form');
    const params = {};

    function grab(attr, key) {
        const el = form.querySelector('[name="' + attr + '"]');
        if (el && el.value !== '') params[key] = el.value;
    }

    grab('search', 'search');
    grab('community', 'community');
    grab('sub_community', 'building');
    grab('furnishing', 'furnishing');
    grab('category', 'category');
    grab('rent_max', 'max_rent');

    const bedsMin = form.querySelector('[name="bedrooms_min"]');
    const bedsMax = form.querySelector('[name="bedrooms_max"]');
    if (bedsMin && bedsMax && bedsMin.value !== '' && bedsMin.value === bedsMax.value) {
        params.bedrooms = bedsMin.value;
    }

    const base = '{{ route('share.inventory', auth()->user()->tenant->slug) }}';
    const qs = new URLSearchParams(params).toString();
    const url = base + (qs ? '?' + qs : '');

    const btn = this;
    navigator.clipboard.writeText(url).then(function () {
        const original = btn.innerHTML;
        btn.innerHTML = '{{ __('Copied ✓') }}';
        setTimeout(function () { btn.innerHTML = original; }, 1500);
    });
});
</script>
@endpush
@endsection