@extends('layouts.app')

@section('title', __('Service Providers'))
@section('page-title', __('Service Providers'))

@section('page-actions')
    <a href="{{ route('service-providers.links') }}" class="btn btn-sm btn-outline-primary">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 13a5 5 0 0 0 7.54 .54l3 -3a5 5 0 0 0 -7.07 -7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0 -7.54 -.54l-3 3a5 5 0 0 0 7.07 7.07l1.71 -1.71"/></svg>
        {{ __('Registration links') }}
    </a>
    @if(auth()->user()->isAdmin())
        <a href="{{ route('service-providers.review') }}" class="btn btn-sm btn-outline-secondary">{{ __('Review queue') }}</a>
    @endif
@endsection

@section('content')
<div class="card">
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('service-providers.index') }}" id="service-providers-search-form" data-live-filter>
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label">{{ __('Search') }}</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Company, representative, phone, email, area...') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Category') }}</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">{{ __('All categories') }}</option>
                        @foreach($categories as $key => $label)
                            <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    @if(request()->hasAny(['search', 'category']))
                        <a href="{{ route('service-providers.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
                    @endif
                </div>
            </div>
        </form>
    </div>

    <div data-live-results>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ __('Company') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('Contact') }}</th>
                        <th>{{ __('Area') }}</th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($providers as $provider)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $provider->company_name }}</div>
                                <div class="text-muted small">{{ $provider->services_offered ? Str::limit($provider->services_offered, 60) : '' }}</div>
                            </td>
                            <td><span class="badge bg-blue-lt">{{ __($provider->categoryLabel()) }}</span></td>
                            <td>
                                <div>
                                    @if($provider->mobile)
                                        <a href="tel:{{ $provider->mobile }}">{{ $provider->mobile }}</a>
                                    @endif
                                </div>
                                <div class="text-muted small">{{ $provider->email }}</div>
                            </td>
                            <td class="text-muted">{{ $provider->city ?: '—' }}</td>
                            <td>
                                <a href="{{ route('service-providers.show', $provider) }}" class="btn btn-sm btn-outline-secondary">{{ __('View') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                {{ __('No approved service providers yet.') }}
                                @if(auth()->user()->isAdmin())
                                    <div class="mt-2"><a href="{{ route('service-providers.links') }}" class="btn btn-sm btn-primary">{{ __('Generate a registration link') }}</a></div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $providers->links() }}
    </div>
</div>
@endsection