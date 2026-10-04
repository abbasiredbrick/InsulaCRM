@extends('layouts.app')
@section('title', __('Pull Previous Leads from Portal API'))
@section('page-title', __('Pull Previous Leads from Portal API'))

@section('content')
@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Choose the portal history to inspect') }}</h3>
            </div>
            <div class="card-body">
                @if ($portals === [])
                    <div class="alert alert-warning">
                        {{ __('No active portal API with the required pull credentials is configured.') }}
                        <a href="{{ route('portal-integrations.index') }}" class="alert-link">{{ __('Open portal integrations') }}</a>
                    </div>
                @else
                    <form method="POST" action="{{ route('recycled.portal-import.store') }}" id="portalImportForm">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label" for="portalApiSource">{{ __('Portal') }} <span class="text-danger">*</span></label>
                            <select name="portal" id="portalApiSource" class="form-select" required>
                                @foreach ($portals as $key => $label)
                                    <option value="{{ $key }}" {{ old('portal', array_key_first($portals)) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="portalApiDateFrom">{{ __('Lead date from') }} <span class="text-danger">*</span></label>
                                <input type="date" name="date_from" id="portalApiDateFrom" class="form-control" value="{{ old('date_from', now()->subDays(30)->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="portalApiDateTo">{{ __('Lead date to') }} <span class="text-danger">*</span></label>
                                <input type="date" name="date_to" id="portalApiDateTo" class="form-control" value="{{ old('date_to', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                            </div>
                        </div>

                        <div class="alert alert-info small d-none" id="propertyFinderRangeNotice">
                            {{ __('Property Finder’s API only returns the last 89 days of leads. For anything older, export CSV files from your PF Expert dashboard — up to 180 days per export, with up to 3 years of history available — then upload them through the CSV importer.') }}
                            <a href="{{ route('recycled.create') }}" class="alert-link">{{ __('Open the CSV importer') }}</a>
                            <a href="https://support.propertyfinder.ae/hc/en-us/articles/36865116212754-Leads-Export" target="_blank" rel="noopener" class="alert-link">{{ __('PF export guide') }}</a>
                        </div>

                        <div class="bayut-options">
                            <div class="mb-3">
                                <label class="form-label">{{ __('Lead channels') }} <span class="text-danger">*</span></label>
                                <div class="row g-2">
                                    @foreach ($types as $key => $label)
                                        <div class="col-sm-6 col-lg-3">
                                            <label class="form-check form-check-border p-2">
                                                <input class="form-check-input" type="checkbox" name="types[]" value="{{ $key }}" {{ in_array($key, old('types', array_keys($types)), true) ? 'checked' : '' }}>
                                                <span class="form-check-label">{{ $label }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">{{ __('Page type') }} <span class="text-danger">*</span></label>
                                <div class="row g-2">
                                    @foreach ($targets as $key => $label)
                                        <div class="col-sm-6 col-lg-4">
                                            <label class="form-check form-check-border p-2">
                                                <input class="form-check-input" type="checkbox" name="targets[]" value="{{ $key }}" {{ in_array($key, old('targets', array_keys($targets)), true) ? 'checked' : '' }}>
                                                <span class="form-check-label">{{ $label }}</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="portalApiAgent">{{ __('Default assignee') }}</label>
                            <select name="agent_id" id="portalApiAgent" class="form-select">
                                <option value="">{{ __('Unassigned') }}</option>
                                @foreach ($agents as $id => $name)
                                    <option value="{{ $id }}" {{ (string) old('agent_id') === (string) $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="alert alert-primary small">
                            {{ __('The first step only previews the pull. It reads portal history but writes nothing to the recycled pool until you review and confirm the results.') }}
                        </div>

                        <button type="submit" class="btn btn-primary">
                            {{ __('Start Preview') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h3 class="card-title">{{ __('Before you start') }}</h3>
                <ul class="text-muted small mt-3 mb-0">
                    <li>{{ __('Bayut and Dubizzle expose email, WhatsApp, phone, and SMS enquiries for listing, agent, and agency pages.') }}</li>
                    <li>{{ __('Bayut and Dubizzle accept a start timestamp; the selected end date is enforced locally before anything is imported.') }}</li>
                    <li>{{ __('Property Finder’s API covers the last 89 days only. Older history (up to 3 years) comes from PF Expert CSV exports of up to 180 days each, uploaded through the CSV importer.') }}</li>
                    <li>{{ __('Contacts already in the live pipeline are linked and marked as already active instead of becoming duplicate active leads.') }}</li>
                    <li>{{ __('Property Finder history older than the API window arrives the same way as any portal report: through the CSV importer.') }}</li>
                    <li>{{ __('Repeated provider events and existing pool contacts are skipped.') }}</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('portalImportForm');
    if (!form) return;

    const source = document.getElementById('portalApiSource');
    const bayutOptions = form.querySelector('.bayut-options');
    const dateFrom = document.getElementById('portalApiDateFrom');
    const notice = document.getElementById('propertyFinderRangeNotice');
    const earliest = @json($propertyFinderEarliest);

    function updateSource() {
        const propertyFinder = source.value === 'property_finder';
        bayutOptions.classList.toggle('d-none', propertyFinder);
        notice.classList.toggle('d-none', !propertyFinder);
        dateFrom.min = propertyFinder ? earliest : '';
    }

    source.addEventListener('change', updateSource);
    updateSource();
});
</script>
@endpush
