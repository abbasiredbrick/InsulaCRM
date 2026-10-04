{{--
    Offer letter form fields — shared by the "new letter" and "edit letter"
    forms so the two can never drift apart.

    Expects:
      $d           array of defaults (OfferLetterService::buildDefaults / editDefaults)
      $canApprove  bool — true when the viewer may self-approve

    The three money tiers are kept visible because they are three different
    numbers the client is entitled to see: the advertised price, what was taken
    off it, and what is actually being contracted.

    VAT is NOT an input. It belongs to the tenant (Tenant::isVatRegistered) and is
    applied to our services only, so a form field here would let someone post a
    rate the company is not registered for. The banner states which applies.
--}}
@php
    $currency = strtoupper($deal->tenant?->currency ?? 'AED');
    $vatRate = (float) ($d['commission_vat_pct'] ?? 0);
    $vatRegistered = (float) $deal->tenant?->effectiveVatRate() > 0;
@endphp

<div class="row g-2">
    <div class="col-md-3">
        <label class="form-label">{{ __('Offer No.') }}</label>
        <input type="text" name="offer_no" class="form-control form-control-sm" value="{{ $d['offer_no'] }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Valid Until') }}</label>
        <input type="date" name="valid_until" class="form-control form-control-sm" value="{{ optional($d['valid_until'])->format('Y-m-d') }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Start Date') }}</label>
        <input type="date" name="contract_start_date" class="form-control form-control-sm" value="{{ optional($d['contract_start_date'])->format('Y-m-d') }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('End Date') }}</label>
        <input type="date" name="contract_end_date" class="form-control form-control-sm" value="{{ optional($d['contract_end_date'])->format('Y-m-d') }}">
    </div>

    <div class="col-12"><hr class="my-1"></div>

    <div class="col-md-4">
        <label class="form-label">{{ __('Unit Price (as listed)') }} ({{ $currency }})</label>
        <input type="number" name="original_amount" class="form-control form-control-sm" data-offer-listed step="0.01" min="0" value="{{ $d['original_amount'] }}" required>
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Discount Value') }} ({{ $currency }})</label>
        <input type="number" name="discount_amount" class="form-control form-control-sm" data-offer-discount step="0.01" min="0" value="{{ $d['discount_amount'] ?? 0 }}">
        @if(! $canApprove)
            <small class="form-hint">{{ __('Requires manager approval.') }}</small>
        @endif
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Contract Value') }} ({{ $currency }})</label>
        {{-- Computed, never posted: it is the listed price less the discount, and
             accepting it from the request would let a forged POST contradict the
             two fields above it. --}}
        <input type="text" class="form-control form-control-sm bg-body-secondary" value=""
               data-offer-contract-value readonly aria-label="{{ __('Contract Value') }}">
        <small class="form-hint">{{ __('Listed price less the discount. Not VATable.') }}</small>
    </div>

    <div class="col-12"><hr class="my-1"></div>

    <div class="col-md-4">
        <label class="form-label">{{ __('Commission Basis') }}</label>
        <div class="btn-group btn-group-sm w-100" role="group" aria-label="{{ __('Commission Basis') }}">
            <input type="radio" class="btn-check" name="commission_basis" id="offerBasisPct"
                   value="percentage" data-offer-basis
                   @checked(($d['commission_basis'] ?? 'percentage') !== 'value')>
            <label class="btn btn-outline-secondary" for="offerBasisPct">{{ __('Percentage %') }}</label>

            <input type="radio" class="btn-check" name="commission_basis" id="offerBasisValue"
                   value="value" data-offer-basis
                   @checked(($d['commission_basis'] ?? 'percentage') === 'value')>
            <label class="btn btn-outline-secondary" for="offerBasisValue">{{ __('Value') }}</label>
        </div>
    </div>
    <div class="col-md-4" data-offer-pct-field>
        <label class="form-label">{{ __('Commission Rate') }} %</label>
        <input type="number" name="commission_rate_pct" class="form-control form-control-sm" data-offer-rate
               step="0.01" min="0" max="100" value="{{ $d['commission_rate_pct'] }}">
    </div>
    <div class="col-md-4" data-offer-value-field hidden>
        <label class="form-label">{{ __('Commission Value') }} ({{ $currency }})</label>
        <input type="number" name="commission_amount" class="form-control form-control-sm" data-offer-commission-value
               step="0.01" min="0" value="{{ $d['commission_amount'] ?? 0 }}">
    </div>

    <div class="col-12">
        <div class="alert alert-{{ $vatRegistered ? 'info' : 'light' }} py-2 px-3 mb-0 small">
            @if($vatRegistered)
                {{ __('VAT') }} <strong>{{ rtrim(rtrim(number_format($vatRate, 2, '.', ''), '0'), '.') }}%</strong>
                {{ __('is applied to the agency commission, admin fee and contract fee, because this company is VAT registered. It is not applied to the lease or sale value.') }}
            @else
                {{ __('This company is not VAT registered, so no VAT is added to the commission, admin fee or contract fee. Turn this on in Settings → Commissions if the registration changes.') }}
            @endif
        </div>
    </div>

    <div class="col-12"><hr class="my-1"></div>

    <div class="col-md-3">
        <label class="form-label">{{ __('Contract Fee') }} ({{ $currency }})</label>
        <input type="number" name="contract_fee" class="form-control form-control-sm" data-offer-contract-fee step="0.01" min="0" value="{{ $d['contract_fee'] ?? '' }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Admin Fee') }} ({{ $currency }})</label>
        <input type="number" name="admin_fee" class="form-control form-control-sm" data-offer-admin-fee step="0.01" min="0" value="{{ $d['admin_fee'] ?? '' }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Security Deposit') }} ({{ $currency }})</label>
        <input type="number" name="security_deposit" class="form-control form-control-sm" step="0.01" min="0" value="{{ $d['security_deposit'] ?? '' }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">{{ __('Payment Period') }}</label>
        <input type="text" name="payment_period" class="form-control form-control-sm" value="{{ $d['payment_period'] ?? '1 Payment' }}">
    </div>

    <div class="col-md-6">
        <label class="form-label">{{ __('Documents Required') }}</label>
        <input type="text" name="documents_required" class="form-control form-control-sm" value="{{ $d['documents_required'] ?? '' }}">
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Notes') }}</label>
        <input type="text" name="notes" class="form-control form-control-sm" value="{{ $d['notes'] ?? '' }}">
    </div>
</div>

<div class="mt-3 d-flex justify-content-between align-items-start">
    <div class="text-secondary small">
        <div>
            {{ __('Contract value:') }} <strong data-offer-contract-value-out>{{ \App\Helpers\TenantFormatHelper::currency($d['approved_amount'] ?? 0) }}</strong>
            <span class="text-muted">{{ __('(no VAT on this figure)') }}</span>
        </div>
        <div class="mt-1">
            {{ __('Services payable to us:') }}
            <strong data-offer-services-net>{{ \App\Helpers\TenantFormatHelper::currency(
                ($d['commission_amount'] ?? 0) + ($d['admin_fee'] ?? 0) + ($d['contract_fee'] ?? 0)
            ) }}</strong>
            @if($vatRegistered)
                + {{ __('VAT') }} <strong data-offer-services-vat>{{ \App\Helpers\TenantFormatHelper::currency(($d['commission_vat'] ?? 0) + ($d['admin_fee_vat'] ?? 0) + ($d['contract_fee_vat'] ?? 0)) }}</strong>
            @endif
            = <strong data-offer-services-total>{{ \App\Helpers\TenantFormatHelper::currency(
                ($d['commission_total'] ?? 0) + ($d['admin_fee_total'] ?? $d['admin_fee'] ?? 0) + ($d['contract_fee_total'] ?? $d['contract_fee'] ?? 0)
            ) }}</strong>
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">{{ $submitLabel }}</button>
</div>