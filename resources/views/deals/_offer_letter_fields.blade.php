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
    $units = app(\App\Services\OfferLetterService::class)->viewedUnits($deal);
@endphp

<div class="row g-2">
    {{-- Choosing the unit fills the listed price, the three fees and the deposit
         from inventory. It is the one input that steers the whole letter, so it
         sits at the top; the server re-stamps it rather than trusting these
         figures. --}}
    <div class="col-12">
        <label class="form-label">{{ __('Offered Unit') }}</label>
        @if($units->isNotEmpty())
            <select name="unit_id" class="form-select form-select-sm" data-offer-unit
                    data-unit-defaults="{{ route('deal.offers.unitDefaults', $deal) }}">
                <option value="">{{ __('— choose a unit the client has viewed —') }}</option>
                @foreach($units as $unit)
                    <option value="{{ $unit->id }}"
                            data-rate="{{ $unit->admin_fee ?? 0 }}"
                            @selected((int) ($d['unit_id'] ?? $deal->property_id) === $unit->id)>
                        {{ $unit->optionLabel() }}
                    </option>
                @endforeach
            </select>
            <small class="form-hint">{{ __('Listed price, contract fee, admin fee and security deposit are filled in from the unit you pick.') }}</small>
        @else
            <p class="form-hint mb-0">
                {{ __('No units are linked to this client yet. Link the units they viewed on the lead, or type the figures in by hand.') }}
            </p>
        @endif
    </div>

    <div class="col-md-4">
        <label class="form-label">{{ __('Offer No.') }}</label>
        <input type="text" name="offer_no" class="form-control form-control-sm" value="{{ $d['offer_no'] }}">
    </div>
    {{-- Defaults to today because that is what it is when you are issuing the
         letter now. It stays editable so a letter written up after the fact can
         carry the date it was really issued. --}}
    <div class="col-md-4">
        <label class="form-label">{{ __('Offer Date') }}</label>
        <input type="date" name="issued_at" class="form-control form-control-sm"
               value="{{ optional($d['issued_at'] ?? null)->format('Y-m-d') ?: now()->format('Y-m-d') }}"
               max="{{ now()->format('Y-m-d') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Valid Until') }}</label>
        <input type="date" name="valid_until" class="form-control form-control-sm" value="{{ optional($d['valid_until'])->format('Y-m-d') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Start Date') }}</label>
        <input type="date" name="contract_start_date" class="form-control form-control-sm" data-offer-start value="{{ optional($d['contract_start_date'])->format('Y-m-d') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('Contract Period (Years)') }}</label>
        <input type="number" name="contract_years" class="form-control form-control-sm" data-offer-years
               min="1" max="20" step="1" value="{{ $d['contract_years'] ?? 1 }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">{{ __('End Date') }}</label>
        {{-- Filled from the start date and the term. Stays editable because a
             tenancy can be agreed to end part-way through the term. --}}
        <input type="date" name="contract_end_date" class="form-control form-control-sm" data-offer-end value="{{ optional($d['contract_end_date'])->format('Y-m-d') }}">
        <small class="form-hint">{{ __('Filled from the start date and the period. Adjust if the lease ends sooner.') }}</small>
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
        <label class="form-label">{{ __('No. of Payments') }}</label>
        <select name="payment_period" class="form-select form-select-sm">
            @for($n = 1; $n <= 12; $n++)
                <option value="{{ $n }}" @selected((string) ($d['payment_period'] ?? '1') === (string) $n)>
                    {{ $n }} {{ $n == 1 ? __('Payment') : __('Payments') }}
                </option>
            @endfor
        </select>
        <small class="form-hint">{{ __('How many payments the rent is split into.') }}</small>
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