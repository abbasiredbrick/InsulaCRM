@extends('layouts.share', ['tenant' => $tenant])

@section('title', $tenant->name.' – '.__('Available Units'))

@section('content')
    <div class="share-subnav rounded-top mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2">
            <span class="text-secondary small">
                {{ __('Hi') }}, <strong class="text-body">{{ $lead->first_name }}</strong>
                @if($lead->phone)
                    <span class="ms-1">({{ $lead->phone }})</span>
                @endif
            </span>
            <form action="{{ route('share.logout', $tenant->slug) }}" method="POST" class="d-inline mb-0">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-decoration-none text-secondary">{{ __('Not you? Switch') }}</button>
            </form>
        </div>
    </div>

    @if(session('interest'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ __('Thanks! We have saved your interest in this unit — our team will get back to you.') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(! empty($mapEmbed))
        <div class="card mb-4 overflow-hidden">
            <div class="card-body pb-0 d-flex justify-content-between align-items-center gap-2">
                <h6 class="mb-0">{{ __('Where the available units are') }}</h6>
                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($mapEmbed['query']) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary text-nowrap">
                    {{ __('Open in Google Maps') }}
                </a>
            </div>
            <div class="ratio ratio-16x9">
                <iframe src="{{ $mapEmbed['embed'] }}" title="{{ __('Map of :place', ['place' => $mapEmbed['query']]) }}" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
            </div>
            <div class="card-body small text-secondary">
                {{ __('Map approximated by :place on Google Maps.', ['place' => $mapEmbed['query']]) }}
            </div>
        </div>
    @endif

    <form method="GET" action="{{ route('share.inventory', $tenant->slug) }}" class="card mb-4">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">{{ __('Search') }}</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ data_get($filters, 'search') }}" placeholder="{{ __('Building, community, unit...') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">{{ __('Bedrooms') }}</label>
                    <select name="bedrooms" class="form-select form-select-sm">
                        <option value="">{{ __('Any') }}</option>
                        @for($i = 0; $i <= 6; $i++)
                            <option value="{{ $i }}" {{ isset($filters['bedrooms']) && (string) $filters['bedrooms'] === (string) $i ? 'selected' : '' }}>
                                {{ $i === 0 ? __('Studio') : $i }}
                            </option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">{{ __('Community') }}</label>
                    <select name="community" class="form-select form-select-sm">
                        <option value="">{{ __('Any') }}</option>
                        @foreach($communities as $community)
                            <option value="{{ $community }}" {{ isset($filters['community']) && $filters['community'] === $community ? 'selected' : '' }}>{{ $community }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">{{ __('Building') }}</label>
                    <select name="building" class="form-select form-select-sm">
                        <option value="">{{ __('Any') }}</option>
                        @foreach($buildings as $building)
                            <option value="{{ $building }}" {{ isset($filters['building']) && $filters['building'] === $building ? 'selected' : '' }}>{{ $building }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">{{ __('Max Rent (:currency)', ['currency' => $tenant->currency ?? 'USD']) }}</label>
                    <input type="number" name="max_rent" min="0" step="1000" class="form-control form-control-sm" value="{{ data_get($filters, 'max_rent') }}" placeholder="{{ __('Yearly') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">{{ __('Furnishing') }}</label>
                    <select name="furnishing" class="form-select form-select-sm">
                        <option value="">{{ __('Any') }}</option>
                        @foreach(\App\Models\Property::FURNISHING as $key => $label)
                            <option value="{{ $key }}" {{ isset($filters['furnishing']) && $filters['furnishing'] === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">{{ __('Type') }}</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">{{ __('Any') }}</option>
                        @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                            <option value="{{ $key }}" {{ isset($filters['category']) && $filters['category'] === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Search') }}</button>
                    <a href="{{ route('share.inventory', $tenant->slug) }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
                </div>
            </div>
        </div>
    </form>

    @if($units->count() > 0)
        <div class="row g-4">
            @foreach($units as $unit)
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 mb-0">
                        <div class="unit-thumb">
                            @php $photo = $unit->media->first(); @endphp
                            @if($photo)
                                <img src="{{ $photo->url() }}" alt="{{ $unit->display_name }}">
                            @else
                                <span>🏢</span>
                            @endif
                        </div>
                        <div class="card-body d-flex flex-column">
                            <button type="button" class="card-title mb-1 text-start p-0 border-0 bg-transparent" data-bs-toggle="modal" data-bs-target="#unitModal{{ $unit->id }}">
                                {{ $unit->display_name }}
                            </button>
                            <div class="text-secondary mb-2" style="font-size:0.8rem;">
                                {{ $unit->sub_community ?: $unit->community }}{{ $unit->sub_community && $unit->community ? ', ' . $unit->community : '' }}
                            </div>
                            <div class="unit-price mb-2">
                                @if($unit->rent_price)
                                    {{ \App\Helpers\TenantFormatHelper::currency($unit->rent_price) }}
                                    <span class="text-muted" style="font-size:0.8rem; font-weight:500;">/ {{ __(\App\Models\Property::RENT_PERIODS[$unit->rent_period] ?? $unit->rent_period) }}</span>
                                @else
                                    {{ __('Price on request') }}
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                @if($unit->bedroomLabel() !== '')
                                    <span class="badge bg-azure-lt">{{ $unit->bedroomLabel() }}</span>
                                @endif
                                @if($unit->bathrooms)
                                    <span class="badge bg-azure-lt">{{ $unit->bathrooms }} {{ __('Baths') }}</span>
                                @endif
                                @if($unit->square_footage)
                                    <span class="badge bg-azure-lt">{{ number_format($unit->square_footage) }} {{ __('sq ft') }}</span>
                                @endif
                                @if($unit->furnishing)
                                    <span class="badge bg-primary-lt">{{ __(\App\Models\Property::FURNISHING[$unit->furnishing] ?? $unit->furnishing) }}</span>
                                @endif
                            </div>
                            @php
                                $unitLocation = $unit->sub_community ? ($mapLocations[$unit->sub_community] ?? null) : null;
                            @endphp
                            @if($unitLocation && ($unitLocation->map_url || $unitLocation->map_query))
                                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                                    <a href="{{ $maps->urlFor($unitLocation) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 21a9 9 0 0 1 -9 -9c0 -4.97 4.03 -9 9 -9s9 4.03 9 9a9 9 0 0 1 -9 9z"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M12 3a17 17 0 0 1 0 18"/><path d="M12 3a17 17 0 0 0 0 18"/></svg>
                                        {{ __('View on map') }}
                                    </a>
                                    @if($unitLocation->map_query)
                                        <a href="{{ $maps->directionsUrl($unitLocation->map_query) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                                            {{ __('Directions') }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                            <div class="mt-auto">
                                @if(in_array($unit->id, $interested, true))
                                    <span class="btn btn-sm btn-success w-100 disabled">{{ __('Interest saved ✓') }}</span>
                                @else
                                    <button type="button" class="btn btn-sm btn-primary w-100" data-bs-toggle="modal" data-bs-target="#unitModal{{ $unit->id }}">{{ __('I\'m interested') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-5 text-secondary">
            <h5 class="mb-1">{{ __('No units match your filters') }}</h5>
            <p class="mb-0">{{ __('Try adjusting your search criteria, or reach out to us and we will find something for you.') }}</p>
        </div>
    @endif

    @if($units->count() > 0)
        @foreach($units as $unit)
            <div class="modal fade" id="unitModal{{ $unit->id }}" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header pb-0 border-0">
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                        </div>
                        <div class="modal-body pt-0">
                            <div class="ratio ratio-21x9 rounded overflow-hidden mb-3">
                                @php $photo = $unit->media->first(); @endphp
                                @if($photo)
                                    <img src="{{ $photo->url() }}" alt="{{ $unit->display_name }}" class="object-fit-cover">
                                @else
                                    <div class="d-flex align-items-center justify-content-center bg-secondary-subtle" style="font-size:3rem;">🏢</div>
                                @endif
                            </div>
                            <h5 class="mb-1">{{ $unit->display_name }}</h5>
                            <div class="text-secondary mb-2">
                                {{ $unit->sub_community ?: $unit->community }}{{ $unit->sub_community && $unit->community ? ', ' . $unit->community : '' }}
                            </div>
                            <div class="unit-price mb-3">
                                @if($unit->rent_price)
                                    {{ \App\Helpers\TenantFormatHelper::currency($unit->rent_price) }}
                                    <span class="text-muted" style="font-size:0.85rem; font-weight:500;">/ {{ __(\App\Models\Property::RENT_PERIODS[$unit->rent_period] ?? $unit->rent_period) }}</span>
                                @else
                                    {{ __('Price on request') }}
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                                @if($unit->bedroomLabel() !== '')
                                    <span class="badge bg-azure-lt">{{ $unit->bedroomLabel() }}</span>
                                @endif
                                @if($unit->bathrooms)
                                    <span class="badge bg-azure-lt">{{ $unit->bathrooms }} {{ __('Baths') }}</span>
                                @endif
                                @if($unit->square_footage)
                                    <span class="badge bg-azure-lt">{{ number_format($unit->square_footage) }} {{ __('sq ft') }}</span>
                                @endif
                                @if($unit->furnishing)
                                    <span class="badge bg-primary-lt">{{ __(\App\Models\Property::FURNISHING[$unit->furnishing] ?? $unit->furnishing) }}</span>
                                @endif
                                @if($unit->parking)
                                    <span class="badge bg-azure-lt">{{ $unit->parking }} {{ __('Parking') }}</span>
                                @endif
                            </div>
                            @if(in_array($unit->id, $interested, true))
                                <button type="button" class="btn btn-success w-100" disabled>{{ __('Interest saved ✓') }}</button>
                            @else
                                <form action="{{ route('share.interest', ['slug' => $tenant->slug, 'property' => $unit->id]) }}" method="POST" class="mb-0">
                                    @csrf
                                    <button type="submit" class="btn btn-primary w-100">{{ __('I\'m interested in this unit') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    @endif
@endsection