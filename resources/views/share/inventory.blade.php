@extends('layouts.share', ['tenant' => $tenant])

@section('title', $tenant->name.' – '.__('Available Units'))

@push('styles')
    <link href="/vendor/leaflet/leaflet.min.css" rel="stylesheet">
    <style>
        .insulacrm-pin {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            width: auto;
            height: 30px;
            padding: 0 5px;
            margin: 7px auto 0;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
            line-height: 1;
            background: #206bc4;
            border: 2px solid #fff;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            box-shadow: 0 2px 6px rgba(32, 107, 196, .45);
        }
        .insulacrm-pin span {
            transform: rotate(45deg);
        }
    </style>
@endpush

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

    @if(count($mapPins) > 0)
        <div class="card mb-4 overflow-hidden share-map-card">
            <div class="card-body pb-0 d-flex justify-content-between align-items-center gap-2">
                <h6 class="mb-0">{{ __('Available units on the map') }}</h6>
            </div>
            <div id="share-map" style="height:400px; background:#eef1f4;"></div>
            <div class="card-body small text-secondary">
                {{ __('Hover a pin to see how many units are available there. Click a pin to start driving directions in Google Maps or Waze.') }}
            </div>
        </div>
    @elseif(! empty($mapEmbed))
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
                    <label class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" name="maids_room" value="1" {{ ! empty($filters['maids_room']) ? 'checked' : '' }}>
                        <span class="form-check-label">{{ __("Maid's room") }}</span>
                    </label>
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
        <div class="card">
            <div class="list-group list-group-flush">
                @foreach($units as $unit)
                    @php $photo = $unit->media->first(); @endphp
                    <div class="list-group-item share-unit-row px-3 py-2"
                         data-bs-toggle="modal" data-bs-target="#unitModal{{ $unit->id }}">
                        <div class="d-flex align-items-center gap-3">
                            <div class="unit-thumb-sm flex-shrink-0">
                                @if($photo)
                                    <img src="{{ $photo->url() }}" alt="{{ $unit->display_name }}">
                                @else
                                    <span>🏢</span>
                                @endif
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <button type="button" class="fw-semibold text-truncate text-start p-0 border-0 bg-transparent w-100"
                                        data-bs-toggle="modal" data-bs-target="#unitModal{{ $unit->id }}">
                                    {{ $unit->display_name }}
                                </button>
                                <div class="text-secondary small text-truncate">
                                    {{ $unit->sub_community ?: $unit->community }}{{ $unit->sub_community && $unit->community ? ', ' . $unit->community : '' }}
                                </div>
                                <div class="d-flex flex-wrap gap-2 align-items-center mt-1">
                                    @if($unit->bedroomLabel() !== '')
                                        <span class="badge bg-azure-lt">{{ $unit->bedroomLabel() }}</span>
                                    @endif
                                    @if($unit->maids_room)
                                        <span class="badge bg-pink-lt">{{ __("Maid's room") }}</span>
                                    @endif
                                    @if($unit->bathrooms)
                                        <span class="badge bg-azure-lt">{{ $unit->bathrooms }} {{ __('Baths') }}</span>
                                    @endif
                                    @if($unit->square_footage)
                                        <span class="badge bg-azure-lt">{{ number_format($unit->square_footage) }} {{ __('sq ft') }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="unit-price">
                                    @if($unit->rent_price)
                                        {{ \App\Helpers\TenantFormatHelper::currency($unit->rent_price) }}
                                        <span class="text-muted" style="font-size:0.75rem; font-weight:500;">/ {{ __(\App\Models\Property::RENT_PERIODS[$unit->rent_period] ?? $unit->rent_period) }}</span>
                                    @else
                                        {{ __('Price on request') }}
                                    @endif
                                </div>
                                @if(in_array($unit->id, $interested, true))
                                    <span class="badge bg-success">{{ __('Interest saved ✓') }}</span>
                                @else
                                    <span class="small fw-semibold text-primary">{{ __('View details') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
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
                                @if($unit->maids_room)
                                    <span class="badge bg-pink-lt">{{ __("Maid's room") }}</span>
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
                            @php
                                $unitLocation = $unit->sub_community ? ($mapLocations[$unit->sub_community] ?? null) : null;
                            @endphp
                            @if($unitLocation && ($unitLocation->map_url || $unitLocation->map_query))
                                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
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

@push('scripts')
    <script src="/vendor/leaflet/leaflet.min.js"></script>
    <script>
        (function () {
            const pins = @json($mapPins);
            const holder = document.getElementById('share-map');
            const card = holder ? holder.closest('.share-map-card') : null;

            if (!pins || pins.length === 0 || !holder) {
                if (card) {
                    card.remove();
                }
                return;
            }

            // Leaflet failed to load (blocked file / extension)? Keep a map on
            // the page: fall back to the keyless Google embed instead of an
            // empty box, so visitors still see where the units are.
            if (typeof L === 'undefined') {
                const q = pins[0] && pins[0].query ? encodeURIComponent(pins[0].query) : '';
                holder.innerHTML = '<iframe src="https://maps.google.com/maps?z=16&q=' + q + '&output=embed" style="width:100%;height:100%;border:0;" allowfullscreen loading="lazy"></iframe>';
                return;
            }

            const map = L.map(holder);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
            }).addTo(map);

            function esc(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            function pinIcon(count) {
                return L.divIcon({
                    className: '',
                    iconSize: [30, 44],
                    iconAnchor: [15, 44],
                    popupAnchor: [0, -42],
                    html: '<div class="insulacrm-pin"><span>' + count + '</span></div>'
                });
            }

            const bounds = [];
            pins.forEach(function (p) {
                const marker = L.marker([p.lat, p.lng], { icon: pinIcon(p.count) }).addTo(map);
                bounds.push([p.lat, p.lng]);

                const unitWord = p.count === 1 ? 'unit' : 'units';
                marker.bindTooltip(
                    '<strong>' + esc(p.name) + '</strong><br>' + p.count + ' ' + unitWord + ' available',
                    { direction: 'top', opacity: 0.95 }
                );

                const html =
                    '<strong>' + esc(p.name) + '</strong><br>' +
                    '<span style="font-size:0.8rem;color:#667382;">' + esc(p.query) + '</span>' +
                    '<div class="mt-1 mb-2" style="font-size:0.85rem;">' + p.count + ' ' + unitWord + ' available</div>' +
                    '<div class="d-flex gap-2">' +
                    (p.google ? '<a class="btn btn-sm btn-primary" href="' + esc(p.google) + '" target="_blank" rel="noopener">Google Maps</a>' : '') +
                    (p.waze ? '<a class="btn btn-sm btn-outline-secondary" href="' + esc(p.waze) + '" target="_blank" rel="noopener">Waze</a>' : '') +
                    '</div>';
                marker.bindPopup(html);
            });

            // A lone far-away pin (e.g. a building in the Western Region) must
            // not zoom the map out to country level; centre the city cluster.
            const lats = pins.map(function (p) { return p.lat; });
            const lngs = pins.map(function (p) { return p.lng; });
            const span = (Math.max.apply(null, lats) - Math.min.apply(null, lats)) +
                (Math.max.apply(null, lngs) - Math.min.apply(null, lngs));

            if (span > 1) {
                const cLat = lats.reduce(function (a, b) { return a + b; }, 0) / lats.length;
                const cLng = lngs.reduce(function (a, b) { return a + b; }, 0) / lngs.length;
                map.setView([cLat, cLng], 12);
            } else {
                map.fitBounds(bounds, { padding: [32, 32], maxZoom: 15 });
            }

            // Re-measure once the layout has settled so tiles/markers render
            // even if the map was initialised with a partial container size.
            requestAnimationFrame(function () {
                setTimeout(function () {
                    map.invalidateSize();
                    map.setView(map.getCenter(), map.getZoom(), { animate: false });
                }, 60);
            });
        })();
    </script>
@endpush