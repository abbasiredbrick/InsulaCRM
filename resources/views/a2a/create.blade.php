@extends('layouts.app')

@section('title', __('New A2A Contract'))
@section('page-title', __('New A2A Contract'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('a2a.index') }}">{{ __('A2A Contracts') }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('New') }}</li>
@endsection

@section('content')
<form method="POST" action="{{ route('a2a.store') }}">
    @csrf
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('Contract scope') }}</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="scope_type" id="scope-lead" value="lead"
                                    @checked(old('scope_type', $scopeType) === 'lead')>
                                <label class="form-check-label" for="scope-lead">
                                    {{ __('Lead-specific') }}
                                    <span class="text-secondary d-block small">{{ __('An external agent collaborates on one of our leads.') }}</span>
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="scope_type" id="scope-property" value="property"
                                    @checked(old('scope_type', $scopeType) === 'property')>
                                <label class="form-check-label" for="scope-property">
                                    {{ __('Property-specific') }}
                                    <span class="text-secondary d-block small">{{ __('We share a unit with another agency agent and their client.') }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="col-md-8" id="scope-lead-block">
                            <label class="form-label">{{ __('Lead') }}</label>
                            <x-searchable-select
                                name="lead_id"
                                placeholder="{{ __('Choose lead...') }}"
                                remote="{{ route('a2a.leads-search') }}"
                                :options="$selectedOptions"
                                :selected="(string) ($scopeType === 'lead' ? old('lead_id', $selectedItem) : '')"
                                invalid="{{ $errors->first('lead_id') ? '1' : '' }}" />
                            @error('lead_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-8" id="scope-property-block">
                            <label class="form-label">{{ __('Property / Unit') }}</label>
                            <x-searchable-select
                                name="property_id"
                                placeholder="{{ __('Choose unit...') }}"
                                remote="{{ route('a2a.properties-search') }}"
                                :options="$selectedOptions"
                                :selected="(string) ($scopeType === 'property' ? old('property_id', $selectedItem) : '')"
                                invalid="{{ $errors->first('property_id') ? '1' : '' }}" />
                            @error('property_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">{{ __('Transaction type') }}</label>
                            <select name="transaction_type" class="form-select" required>
                                <option value="lease" @selected(old('transaction_type', $transactionType) === 'lease')>{{ __('Lease') }}</option>
                                <option value="sale" @selected(old('transaction_type', $transactionType) === 'sale')>{{ __('Sale') }}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('External agent') }}</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">{{ __('Full name') }}</label>
                            <input type="text" name="counterparty_name" class="form-control @error('counterparty_name') is-invalid @enderror" value="{{ old('counterparty_name') }}" required autocomplete="off">
                            @error('counterparty_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">{{ __('Company / Agency') }}</label>
                            <input type="text" name="counterparty_company" class="form-control @error('counterparty_company') is-invalid @enderror" value="{{ old('counterparty_company') }}" required autocomplete="off">
                            @error('counterparty_company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Email') }}</label>
                            <input type="email" name="counterparty_email" class="form-control @error('counterparty_email') is-invalid @enderror" value="{{ old('counterparty_email') }}">
                            @error('counterparty_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">{{ __('Address') }}</label>
                            <input type="text" name="counterparty_address" class="form-control" value="{{ old('counterparty_address') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('Commission Share') }}</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required">{{ __('Share (%)') }}</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0" max="100" name="share_pct" class="form-control @error('share_pct') is-invalid @enderror" value="{{ old('share_pct', 10) }}" required>
                                <span class="input-group-text">%</span>
                            </div>
                            @error('share_pct')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label required">{{ __('Funded by') }}</label>
                            <select name="funding_source" class="form-select" required>
                                <option value="from_agent" @selected(old('funding_source', 'from_agent') === 'from_agent')>{{ __('Entirely from the main agent') }}</option>
                                <option value="from_company" @selected(old('funding_source') === 'from_company')>{{ __('Entirely from the company') }}</option>
                                <option value="from_both" @selected(old('funding_source') === 'from_both')>{{ __('Half company / half main agent') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-hint mt-2">
                        {{ __('This share is charged to the agent or company when the linked lead’s commission is calculated.') }}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h3 class="card-title">{{ __('Terms') }}</h3></div>
                <div class="card-body">
                    <label class="form-label">{{ __('Special terms') }}</label>
                    <textarea name="terms" rows="8" class="form-control" placeholder="{{ __('Payment milestones, refund conditions, cancellation…)') }}">{{ old('terms') }}</textarea>
                </div>
                <div class="card-footer text-end">
                    <a href="{{ route('a2a.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('Create contract') }}</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const leadRadio = document.getElementById('scope-lead');
    const propertyRadio = document.getElementById('scope-property');
    const leadBlock = document.getElementById('scope-lead-block');
    const propertyBlock = document.getElementById('scope-property-block');
    const leadField = document.querySelector('[data-ss][name="lead_id"]');
    const propertyField = document.querySelector('[data-ss][name="property_id"]');

    function sync() {
        const lead = leadRadio && leadRadio.checked;
        if (leadBlock) { leadBlock.style.display = lead ? '' : 'none'; }
        if (propertyBlock) { propertyBlock.style.display = lead ? 'none' : ''; }
        if (leadField) { leadField.querySelector('[data-ss-select]').required = lead; }
        if (propertyField) { propertyField.querySelector('[data-ss-select]').required = !lead; }
    }

    if (leadRadio) { leadRadio.addEventListener('change', sync); }
    if (propertyRadio) { propertyRadio.addEventListener('change', sync); }
    sync();

    // When a lead is picked, prefill the transaction type from its deal type
    // via a hidden attribute on the option list is not possible with remote
    // options, so just keep the user-controlled selector.
})();
</script>
@endpush