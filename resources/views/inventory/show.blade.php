@extends('layouts.app')

@section('title', $property->display_name)
@section('page-title', $property->display_name)

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <div>
        <a href="{{ route('inventory.index') }}" class="text-muted text-decoration-none">&larr; {{ __('Inventory') }}</a>
        <div class="h3 mb-0 mt-1">
            @php
                $intentColors = ['rent' => 'blue', 'sale' => 'green', 'both' => 'purple'];
                $availabilityColors = [
                    'draft' => 'secondary', 'ready_to_list' => 'azure', 'upcoming' => 'cyan', 'listed' => 'green',
                    'reserved' => 'orange', 'leased' => 'blue', 'sold' => 'purple', 'unlisted' => 'dark',
                ];
            @endphp
            <span class="badge bg-{{ $intentColors[$property->intent] ?? 'secondary' }}">{{ __(\App\Models\Property::INTENTS[$property->intent] ?? $property->intent) }}</span>
            <span class="badge bg-{{ $availabilityColors[$property->availability] ?? 'secondary' }}">{{ __($property->availability_label) }}</span>
            @if($property->is_portal_ready)
                <span class="badge bg-teal">{{ __('Portal ready') }}</span>
            @endif
        </div>
    </div>
    <div class="btn-list">
        <a href="{{ route('inventory.portal') }}" class="btn btn-outline-secondary">{{ __('Portals') }}</a>
        <a href="{{ route('a2a.create', ['property_id' => $property->id]) }}" class="btn btn-outline-indigo">{{ __('Create A2A') }}</a>
        <a href="{{ route('inventory.edit', $property) }}" class="btn btn-primary">{{ __('Edit') }}</a>
        <form method="POST" action="{{ route('inventory.destroy', $property) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete this unit and its media? This cannot be undone.') }}');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-outline-danger">{{ __('Delete') }}</button>
        </form>
    </div>
</div>

@if($errors->any())
<div class="alert alert-danger" id="photo-upload-errors">
    <ul class="mb-0">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

{{-- Photo gallery / upload --}}
@include('inventory._photos')

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('Pricing') }}</h3></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Rent') }}</div>
                        <div class="fw-bold">{{ $property->rent_price ? \App\Helpers\TenantFormatHelper::currency($property->rent_price) . ' / ' . __(\App\Models\Property::RENT_PERIODS[$property->rent_period] ?? $property->rent_period) : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Deposit') }}</div>
                        <div class="fw-bold">{{ $property->deposit_amount ? \App\Helpers\TenantFormatHelper::currency($property->deposit_amount) : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Admin fee') }}</div>
                        <div class="fw-bold">{{ $property->admin_fee ? \App\Helpers\TenantFormatHelper::currency($property->admin_fee) : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Tawtheeq fee') }}</div>
                        <div class="fw-bold">{{ $property->tawtheeq_fee ? \App\Helpers\TenantFormatHelper::currency($property->tawtheeq_fee) : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Sale') }}</div>
                        <div class="fw-bold">{{ $property->sale_price ? \App\Helpers\TenantFormatHelper::currency($property->sale_price) : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Service charge') }}</div>
                        <div class="fw-bold">{{ $property->service_charge ? \App\Helpers\TenantFormatHelper::currency($property->service_charge) : '—' }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">{{ __('Handover / Key') }}</div>
                        <div class="fw-bold">{{ $property->handover_date ? $property->handover_date->format('d M Y') : '—' }}</div>
                    </div>
                    @if($property->available_from)
                        <div class="col-md-4">
                            <div class="text-muted small">{{ __('Available from') }}</div>
                            <div class="fw-bold">{{ $property->available_from->format('d M Y') }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('Details') }}</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">{{ __('Category') }}</dt>
                    <dd class="col-sm-9">{{ __(\App\Models\Property::CATEGORIES[$property->property_category] ?? $property->property_category) }} • {{ __(\App\Models\Property::MARKET_CLASSES[$property->market_class] ?? $property->market_class) }}</dd>

                    <dt class="col-sm-3">{{ __('Beds / Baths') }}</dt>
                    <dd class="col-sm-9">{{ $property->bedrooms ?? '—' }} {{ __('bd') }} / {{ $property->bathrooms ?? '—' }} {{ __('ba') }}</dd>

                    <dt class="col-sm-3">{{ __('Size') }}</dt>
                    <dd class="col-sm-9">{{ $property->square_footage ? \App\Helpers\TenantFormatHelper::area($property->square_footage) : '—' }}</dd>

                    <dt class="col-sm-3">{{ __('Furnishing') }}</dt>
                    <dd class="col-sm-9">{{ __(\App\Models\Property::FURNISHING[$property->furnishing] ?? '—') }}</dd>

                    <dt class="col-sm-3">{{ __('Parking') }}</dt>
                    <dd class="col-sm-9">{{ $property->parking ?: '—' }}</dd>

                    <dt class="col-sm-3">{{ __('Location') }}</dt>
                    <dd class="col-sm-9">
                        {{ $property->address ?: '' }}{{ $property->address ? ', ' : '' }}
                        {{ $property->sub_community ?: $property->community }}
                        {{ $property->community && $property->sub_community ? ', ' . $property->community : '' }}{{ $property->city ? ', ' . $property->city : '' }}
                    </dd>

                    <dt class="col-sm-3">{{ __('Unit ref') }}</dt>
                    <dd class="col-sm-9">
                        @php
                            $refs = array_filter([$property->building_no, $property->floor_no ? 'Floor ' . $property->floor_no : null, $property->unit_no ? 'Unit ' . $property->unit_no : null, $property->plot_no ? 'Plot ' . $property->plot_no : null]);
                        @endphp
                        {{ $refs ? implode(' • ', $refs) : '—' }}
                    </dd>

                    <dt class="col-sm-3">{{ __('Title Deed No') }}</dt>
                    <dd class="col-sm-9">{{ $property->title_deed_no ?: '—' }}</dd>

                    <dt class="col-sm-3">{{ $property->permit_label }}</dt>
                    <dd class="col-sm-9">{{ $property->rera_permit_no ?: '—' }}</dd>

                    <dt class="col-sm-3">{{ __('Assigned agent') }}</dt>
                    <dd class="col-sm-9">{{ $property->assignedAgent->name ?? '—' }}</dd>

                    @if($property->developer_name)
                    <dt class="col-sm-3">{{ __('Developer') }}</dt>
                    <dd class="col-sm-9">{{ $property->developer_name }} @if($property->handover_date) ({{ __('handover') }} {{ $property->handover_date->format('M Y') }}) @endif</dd>
                    @endif

                    @if($property->virtual_tour_url)
                    <dt class="col-sm-3">{{ __('Virtual tour') }}</dt>
                    <dd class="col-sm-9"><a href="{{ $property->virtual_tour_url }}" target="_blank" rel="noopener">{{ $property->virtual_tour_url }}</a></dd>
                    @endif
                </dl>
            </div>
        </div>

        @if($property->marketing_title || $property->marketing_description || $property->notes)
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('Marketing & Notes') }}</h3></div>
            <div class="card-body">
                @if($property->marketing_title)<h5>{{ $property->marketing_title }}</h5>@endif
                @if($property->marketing_description)
                    <p style="white-space: pre-wrap;">{{ $property->marketing_description }}</p>
                @endif
                @if($property->notes)
                    <div class="alert alert-secondary mb-0">{{ $property->notes }}</div>
                @endif
            </div>
        </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('Linked Leads') }}</h3></div>
            <div class="card-body">
                @forelse($property->leads as $linkedLead)
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <a href="{{ route('leads.show', $linkedLead) }}" class="fw-bold text-decoration-none">{{ $linkedLead->full_name ?: ($linkedLead->email ?: '#' . $linkedLead->id) }}</a>
                        @if($linkedLead->phone)<div class="text-muted small">{{ $linkedLead->phone }}</div>@endif
                    </div>
                </div>
                @empty
                <p class="text-muted mb-2">{{ __('No leads linked to this unit yet.') }}</p>
                <p class="text-muted small mb-0">{{ __('Leads are linked from the lead profile (or automatically from portal leads).') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('Owner') }}</h3></div>
            <div class="card-body">
                <div>{{ $property->owner_name ?: '—' }}</div>
                @if($property->owner_phone)<div class="text-muted">{{ $property->owner_phone }}</div>@endif
                @if($property->owner_email)<div class="text-muted">{{ $property->owner_email }}</div>@endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Portal status') }}</h3></div>
            <div class="list-group list-group-flush">
                @foreach([
                    ['key' => 'bayut', 'label' => 'Bayut', 'status' => $property->bayut_status],
                    ['key' => 'dubizzle', 'label' => 'Dubizzle', 'status' => $property->dubizzle_status],
                    ['key' => 'propertyfinder', 'label' => 'Property Finder', 'status' => $property->propertyfinder_status],
                ] as $portal)
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-bold">{{ $portal['label'] }}</div>
                            <div class="text-muted small">
                                {{ __(\App\Models\Property::PORTAL_STATUSES[$portal['status']] ?? $portal['status']) }}
                                @if($portal['status'] === 'live')
                                    @php $urlField = $portal['key'] . '_url'; @endphp
                                    @if($property->{$urlField}) • <a href="{{ $property->{$urlField} }}" target="_blank" rel="noopener">{{ __('view listing') }}</a>@endif
                                @endif
                                @if($portal['key'] === 'bayut' && $property->bayut_location_label)
                                    • {{ $property->bayut_location_label }}
                                @endif
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            @include('inventory._portal-toggle', ['unit' => $property, 'portal' => $portal])
                            @if($portal['key'] === 'bayut' && $property->bayut_status !== 'live')
                            <form method="POST" action="{{ route('inventory.push', [$property, 'bayut']) }}" class="d-inline" onsubmit="return confirm('{{ __('Push this unit to Bayut now?') }}')">
                                @csrf
                                <input type="hidden" name="confirmed" value="1">
                                <button type="submit" class="btn btn-sm btn-danger btn-pill {{ $property->is_portal_ready ? '' : 'disabled' }}" {{ $property->is_portal_ready ? '' : 'disabled' }}>{{ __('Push Bayut') }}</button>
                            </form>
                            @endif
                            <a href="{{ route('inventory.show', $property) }}#portal-{{ $portal['key'] }}" class="btn btn-sm btn-outline-primary btn-pill">{{ __('Record') }}</a>
                        </div>
                    </div>
                    <div id="portal-{{ $portal['key'] }}">
                        <form method="POST" action="{{ route('inventory.portal-status', $property) }}" class="row g-2 mt-2">
                            @csrf
                            <input type="hidden" name="portal" value="{{ $portal['key'] }}">
                            <div class="col-12">
                                <select name="status" class="form-select form-select-sm">
                                    @foreach(\App\Models\Property::PORTAL_STATUSES as $key => $label)
                                        <option value="{{ $key }}" {{ $property->{$portal['key'] . '_status'} === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @php
                                $refField = $portal['key'] === 'bayut' ? 'bayut_listing_id' : ($portal['key'] . '_listing_reference');
                            @endphp
                            <div class="col-12">
                                <input type="text" name="listing_reference" class="form-control form-control-sm" placeholder="{{ __('Listing ID / reference') }}" value="{{ $property->{$refField} }}">
                            </div>
                            <div class="col-12">
                                <input type="url" name="url" class="form-control form-control-sm" placeholder="https://.../listing" value="{{ $property->{$portal['key'] . '_url'} }}">
                            </div>
                            <div class="col-12"><button type="submit" class="btn btn-sm btn-primary w-100">{{ __('Save status') }}</button></div>
                        </form>
                        @if($portal['key'] === 'bayut' && ($property->market_class ?? 'ready') === 'off_plan' && in_array($property->intent, ['rent', 'both'], true))
                        <div class="small text-warning mt-2">{{ __('Bayut only allows off-plan developments to be listed for Sale, not Rent. Set intent to Sale or mark the unit Ready once completed.') }}</div>
                        @endif
                        @if($portal['key'] === 'bayut')
                        <form method="POST" action="{{ route('inventory.portal-location', $property) }}" class="row g-2 mt-2 border-top pt-2">
                            @csrf
                            <div class="col-12">
                                <label class="form-label small">{{ __('Bayut location (push API)') }} — @if(($property->market_class ?? 'ready') === 'off_plan'){{ __('off-plan: sale only') }}@else{{ __('rent & sale') }}@endif</label>
                                <x-searchable-select
                                    name="location_id"
                                    label-name="location_label"
                                    :options="$bayut_location_options"
                                    :selected="(string) $property->bayut_location_id"
                                    :remote="$bayut_location_search_url"
                                    :placeholder="__('Choose / search a Bayut location…')"
                                    :search-placeholder="__('Type building, community or emirate… e.g. Taj')"
                                />
                            </div>
                            <div class="col-12 d-flex align-items-center gap-2">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">{{ __('Save Bayut location') }}</button>
                                <a href="{{ route('portal-integrations.index') }}" class="btn btn-sm btn-outline-secondary text-nowrap">{{ __('Settings') }}</a>
                            </div>
                            <div class="col-12">
                                <span class="small text-muted">{{ __('This searches Bayut\'s full location catalog live (21,000+ locations). No pre-sync needed.') }}</span>
                            </div>
                        </form>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const search = document.getElementById('bayut-location-search');
    const select = document.getElementById('bayut-location-select');
    if (!search || !select) return;
    const options = Array.prototype.slice.call(select.options);
    search.addEventListener('input', function () {
        const q = search.value.trim().toLowerCase();
        options.forEach(function (opt, i) {
            if (q === '') { opt.style.display = ''; return; }
            opt.style.display = (opt.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>
@endsection