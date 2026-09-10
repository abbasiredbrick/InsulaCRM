<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $tenant->name }} – {{ __('Available Units') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bp-primary: #0054a6;
            --bp-primary-dark: #003d7a;
            --bp-primary-light: #e8f0fe;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f8f9fa;
            color: #1e293b;
        }
        .bp-hero {
            background: linear-gradient(135deg, var(--bp-primary) 0%, var(--bp-primary-dark) 100%);
            color: #fff;
            padding: 2.5rem 0;
        }
        .bp-hero .bp-logo {
            max-height: 56px;
            max-width: 180px;
            margin-bottom: 0.75rem;
        }
        .bp-hero h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .bp-hero p {
            font-size: 1rem;
            opacity: 0.92;
            margin-bottom: 0;
        }
        .visitor-bar {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.6rem 0;
            font-size: 0.9rem;
        }
        .filter-bar {
            background: #fff;
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }
        .unit-card {
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            overflow: hidden;
            background: #fff;
            display: flex;
            flex-direction: column;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .unit-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .unit-thumb {
            height: 170px;
            background: var(--bp-primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 2rem;
            overflow: hidden;
        }
        .unit-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .unit-body {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .unit-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }
        .unit-loc {
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.75rem;
        }
        .unit-price {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--bp-primary);
            margin-bottom: 0.75rem;
        }
        .unit-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 1rem;
        }
        .unit-stats strong {
            color: #1e293b;
        }
        .furnishing-badge {
            display: inline-block;
            background-color: var(--bp-primary-light);
            color: var(--bp-primary);
            font-size: 0.7rem;
            font-weight: 600;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            text-transform: uppercase;
        }
        .btn-primary {
            background-color: var(--bp-primary);
            border-color: var(--bp-primary);
        }
        .btn-primary:hover {
            background-color: var(--bp-primary-dark);
            border-color: var(--bp-primary-dark);
        }
        .bp-empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #64748b;
        }
        .bp-footer {
            background: #1e293b;
            color: #94a3b8;
            padding: 1.5rem 0;
            margin-top: 3rem;
        }
    </style>
</head>
<body>
    <div class="bp-hero">
        <div class="container d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                @if($tenant->logo_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path) }}" alt="{{ $tenant->name }}" class="bp-logo">
                @endif
                <h1>{{ $tenant->name }}</h1>
                <p>{{ __('Available rental units') }}</p>
            </div>
        </div>
    </div>

    <div class="visitor-bar">
        <div class="container d-flex justify-content-between align-items-center">
            <span>
                {{ __('Hi') }}, <strong>{{ $lead->first_name }}</strong>
                @if($lead->phone)
                    <span class="text-muted ms-1">({{ $lead->phone }})</span>
                @endif
            </span>
            <form action="{{ route('share.logout', $tenant->slug) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-link btn-sm text-decoration-none">{{ __('Not you? Switch') }}</button>
            </form>
        </div>
    </div>

    <div class="container py-4">
        @if(session('interest'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ __('Thanks! We have saved your interest in this unit — our team will get back to you.') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="GET" action="{{ route('share.inventory', $tenant->slug) }}" class="filter-bar">
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
                    <label class="form-label small fw-semibold mb-1">{{ __('Max Rent (AED)') }}</label>
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
        </form>

        @if($units->count() > 0)
            <div class="row g-4">
                @foreach($units as $unit)
                    <div class="col-md-6 col-lg-4">
                        <div class="unit-card h-100">
                            <div class="unit-thumb">
                                @php $photo = $unit->media->first(); @endphp
                                @if($photo)
                                    <img src="{{ $photo->url() }}" alt="{{ $unit->display_name }}">
                                @else
                                    <span>🏢</span>
                                @endif
                            </div>
                            <div class="unit-body">
                                <div class="unit-title">{{ $unit->display_name }}</div>
                                <div class="unit-loc">
                                    {{ $unit->sub_community ?: $unit->community }}{{ $unit->sub_community && $unit->community ? ', ' . $unit->community : '' }}
                                    @if($unit->unit_no)
                                        • {{ __('Unit') }} {{ $unit->unit_no }}
                                    @endif
                                </div>
                                <div class="unit-price">
                                    @if($unit->rent_price)
                                        {{ \App\Helpers\TenantFormatHelper::currency($unit->rent_price) }}
                                        <span class="text-muted" style="font-size:0.8rem; font-weight:500;">/ {{ __(\App\Models\Property::RENT_PERIODS[$unit->rent_period] ?? $unit->rent_period) }}</span>
                                    @else
                                        {{ __('Price on request') }}
                                    @endif
                                </div>
                                <div class="unit-stats">
                                    @if($unit->bedrooms !== null)
                                        <span><strong>{{ $unit->bedrooms === 0 ? __('Studio') : $unit->bedrooms }}</strong> {{ $unit->bedrooms === 0 ? '' : 'BR' }}</span>
                                    @endif
                                    @if($unit->bathrooms)
                                        <span><strong>{{ $unit->bathrooms }}</strong> {{ __('Baths') }}</span>
                                    @endif
                                    @if($unit->square_footage)
                                        <span><strong>{{ number_format($unit->square_footage) }}</strong> {{ __('sq ft') }}</span>
                                    @endif
                                    @if($unit->furnishing)
                                        <span class="furnishing-badge">{{ __(\App\Models\Property::FURNISHING[$unit->furnishing] ?? $unit->furnishing) }}</span>
                                    @endif
                                </div>
                                <div class="mt-auto">
                                    @if(in_array($unit->id, $interested, true))
                                        <span class="btn btn-sm btn-success w-100 disabled">{{ __('Interest saved ✓') }}</span>
                                    @else
                                        <form action="{{ route('share.interest', ['slug' => $tenant->slug, 'property' => $unit->id]) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-primary w-100">{{ __('I\'m interested') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="bp-empty-state">
                <h5>{{ __('No units match your filters') }}</h5>
                <p>{{ __('Try adjusting your search criteria, or reach out to us and we will find something for you.') }}</p>
            </div>
        @endif
    </div>

    <footer class="bp-footer">
        <div class="container text-center">
            <p class="mb-0">&copy; {{ date('Y') }} {{ $tenant->name }}. {{ __('All rights reserved.') }}</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>