@extends('layouts.app')

@section('title', __('Record Lease'))
@section('page-title', __('Record Lease'))

@section('content')
@php
    $fmt = \App\Helpers\TenantFormatHelper::class;
@endphp

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Record Lease') }}</h3>
        <div class="card-actions">
            <a href="{{ route('leases.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('Back to Leases') }}</a>
        </div>
    </div>

    <form method="POST" action="{{ route('leases.store') }}">
        @csrf
        <div class="card-body">
            @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Client & unit come from a rental lead --}}
            <h3 class="mb-3">{{ __('Client & Unit') }}</h3>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label">{{ __('Rental Lead') }}</label>
                    <select name="lead_id" id="leaseLead" class="form-select">
                        <option value="">— {{ __('Continue without a lead') }} —</option>
                        @foreach($rentLeads as $lead)
                            @php $leadProp = $lead->property ?? $lead->properties->first(); @endphp
                            <option value="{{ $lead->id }}"
                                data-agent="{{ $lead->agent_id ?? '' }}"
                                data-first="{{ $lead->first_name }}"
                                data-last="{{ $lead->last_name }}"
                                data-phone="{{ $lead->phone }}"
                                data-email="{{ $lead->email }}"
                                data-property="{{ $leadProp ? $leadProp->id : '' }}"
                                data-address="{{ $leadProp ? $leadProp->address . ', ' . $leadProp->city : '' }}"
                                data-community="{{ $leadProp->community ?? '' }}"
                                data-unit="{{ $leadProp->unit_no ?? '' }}"
                                data-rent="{{ $leadProp->rent_price ?? '' }}"
                                data-adminfee="{{ $leadProp->admin_fee ?? '' }}"
                                data-stage="{{ $lead->stage_label }}">
                                {{ $lead->full_name }}{{ $leadProp ? ' — ' . ($leadProp->display_name) : '' }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-hint">{{ __('Pick the signed rental lead to auto-fill the client and unit.') }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Current Stage') }}</label>
                    <input type="text" id="leaseStage" class="form-control" value="" placeholder="{{ __('Select a lead') }}" disabled>
                </div>
            </div>

            <div class="row g-3 mb-3" id="leadClientFields">
                <div class="col-md-4">
                    <label class="form-label">{{ __('First Name') }}</label>
                    <input type="text" name="first_name" id="clientFirst" class="form-control" value="{{ old('first_name') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Last Name') }}</label>
                    <input type="text" name="last_name" id="clientLast" class="form-control" value="{{ old('last_name') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Client') }}</label>
                    <input type="text" id="clientFull" class="form-control" value="" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Phone') }}</label>
                    <input type="text" name="phone" id="clientPhone" class="form-control" value="{{ old('phone') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ __('Email') }}</label>
                    <input type="email" name="email" id="clientEmail" class="form-control" value="{{ old('email') }}">
                </div>
            </div>

            <h3 class="mb-3 mt-4">{{ __('Unit Details') }}</h3>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Unit') }}</label>
                    <input type="text" name="unit_no" id="unitNo" class="form-control" value="{{ old('unit_no') }}" placeholder="{{ __('e.g. 1204, Tower A') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Community') }}</label>
                    <input type="text" name="community" id="unitCommunity" class="form-control" value="{{ old('community') }}" placeholder="{{ __('e.g. Dubai Marina') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Unit Address') }}</label>
                    <input type="text" name="unit_address" id="unitAddress" class="form-control" value="{{ old('unit_address') }}">
                </div>
                <input type="hidden" name="property_id" id="propertyId" value="{{ old('property_id') }}">
            </div>

            <h3 class="mb-3 mt-4">{{ __('Contract') }}</h3>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">{{ __('Contract Start Date') }}</label>
                    <input type="date" name="contract_start_date" class="form-control" value="{{ old('contract_start_date') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label required">{{ __('Contract Expiry Date') }}</label>
                    <input type="date" name="contract_end_date" class="form-control" required value="{{ old('contract_end_date') }}">
                    <div class="form-hint">{{ __('Agent and manager are reminded 45 days before this date.') }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Agent / Manager') }}</label>
                    <select name="agent_id" id="leaseAgent" class="form-select">
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" {{ old('agent_id', auth()->user()->id) == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Rent (:code / year)', ['code' => \App\Helpers\TenantFormatHelper::currencyCode()]) }}</label>
                    <input type="number" step="0.01" min="0" name="rent_price" id="unitRent" class="form-control" value="{{ old('rent_price') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ __('Admin Fee') }}</label>
                    <input type="number" step="0.01" min="0" name="admin_fee" id="unitAdminFee" class="form-control" value="{{ old('admin_fee') }}">
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">{{ __('Notes') }}</label>
                <textarea name="notes" class="form-control" rows="3">{{ old('notes') }}</textarea>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="{{ route('leases.index') }}" class="btn btn-link link-secondary">{{ __('Cancel') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Save Lease') }}</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
(function () {
    "use strict";
    var leadSelect = document.getElementById('leaseLead');
    if (!leadSelect) return;

    function fillFromLead() {
        var opt = leadSelect.selectedOptions[0];
        if (!opt || opt.value === '') {
            document.getElementById('clientFull').value = '';
            document.getElementById('leaseStage').value = '';
            document.getElementById('propertyId').value = '';
            return;
        }
        document.getElementById('clientFirst').value = opt.dataset.first || '';
        document.getElementById('clientLast').value = opt.dataset.last || '';
        document.getElementById('clientPhone').value = opt.dataset.phone || '';
        document.getElementById('clientEmail').value = opt.dataset.email || '';
        document.getElementById('clientFull').value = (opt.dataset.first + ' ' + opt.dataset.last).trim();
        document.getElementById('leaseStage').value = opt.dataset.stage || '';
        document.getElementById('propertyId').value = opt.dataset.property || '';
        document.getElementById('unitAddress').value = opt.dataset.address || '';
        document.getElementById('unitCommunity').value = opt.dataset.community || '';
        document.getElementById('unitNo').value = opt.dataset.unit || '';
        document.getElementById('unitRent').value = opt.dataset.rent || '';
        document.getElementById('unitAdminFee').value = opt.dataset.adminfee || '';
        if (opt.dataset.agent) document.getElementById('leaseAgent').value = opt.dataset.agent;
    }

    leadSelect.addEventListener('change', fillFromLead);
})();
</script>
@endsection