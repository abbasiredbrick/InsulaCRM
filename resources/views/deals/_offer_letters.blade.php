{{--
    Offer Letters panel (real estate mode) — shown on the deal detail page.

    Generate -> printable letter -> client signs -> upload signed copy. Only a
    signed offer unlocks closing the deal as Won.
--}}
<div class="card mb-3" id="offer-letters-panel">
    <div class="card-header">
        <h3 class="card-title">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11v6"/><path d="M9 14l3 -3l3 3"/></svg>
            {{ __('Leasing Offer Letters') }}
            <span class="badge bg-secondary-lt ms-2">{{ count($deal->offerLetters) }}</span>
        </h3>
        <div class="card-actions">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#offer-letter-form" aria-expanded="false" aria-controls="offer-letter-form">
                {{ __('New Offer Letter') }}
            </button>
        </div>
    </div>

    <div class="card-body">
        @php $canApprove = auth()->user()->isAdmin() || auth()->user()->isManager(); @endphp
        @php $signed = $deal->offerLetters->first(fn ($l) => $l->status === 'signed'); @endphp
        @if(! $signed)
            <div class="alert alert-warning py-2">
                <small>{{ __('A signed offer letter is required before this transaction can be closed as Won.') }}</small>
            </div>
        @else
            <div class="alert alert-success py-2">
                <small>{{ __('Signed offer :no recorded — this transaction may be closed as Won.', ['no' => $signed->offer_no]) }}</small>
            </div>
        @endif

        {{-- New offer letter form --}}
        <div class="collapse mb-3" id="offer-letter-form">
            @php
                $d = app(\App\Services\OfferLetterService::class)->buildDefaults($deal);
                $canApprove = auth()->user()->isAdmin() || auth()->user()->isManager();
            @endphp
            <form method="POST" class="offer-letter-form"
                  data-offer-vat-rate="{{ (float) $deal->tenant->effectiveVatRate() }}"
                  action="{{ route('deal.offers.store', $deal) }}">
                @csrf
                @include('deals._offer_letter_fields', [
                    'd' => $d,
                    'canApprove' => $canApprove,
                    'submitLabel' => __('Generate Offer Letter'),
                ])
            </form>
        </div>

        @if($deal->offerLetters->count())
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ __('No.') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th>{{ __('Value') }}</th>
                        <th>{{ __('Commission') }}</th>
                        <th>{{ __('Issued') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($deal->offerLetters as $offer)
                    @php
                        $statusColors = ['draft' => 'bg-secondary-lt', 'pending_approval' => 'bg-yellow-lt', 'issued' => 'bg-azure-lt', 'signed' => 'bg-green-lt', 'declined' => 'bg-red-lt', 'withdrawn' => 'bg-orange-lt'];
                        $oc = $statusColors[$offer->status] ?? 'bg-secondary-lt';
                    @endphp
                    <tr>
                        <td class="fw-bold">{{ $offer->offer_no }}</td>
                        <td>
                            <span class="badge {{ $oc }}">{{ __(\App\Models\OfferLetter::STATUSES[$offer->status] ?? ucfirst($offer->status)) }}</span>
                            @if($offer->status === 'pending_approval')
                                <div class="text-secondary small">{{ __('Waiting for manager approval.') }}</div>
                            @endif
                            @if($offer->isApproved() && $offer->approver)
                                <div class="text-secondary small">{{ __('Approved by :name', ['name' => $offer->approver->name]) }}</div>
                            @endif
                            @if($offer->status === 'signed' && $offer->signed_at)
                                <div class="text-secondary small">{{ $offer->signed_at->diffForHumans() }}</div>
                            @endif
                            @if($offer->status === 'withdrawn' && $offer->withdrawn_at)
                                <div class="text-secondary small">{{ $offer->withdrawn_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td>{{ \App\Helpers\TenantFormatHelper::currency($offer->approved_amount) }}
                            @if($offer->discount_amount > 0)
                                <div class="text-secondary small"><s>{{ \App\Helpers\TenantFormatHelper::currency($offer->original_amount) }}</s> {{ __('discount granted') }}</div>
                            @endif
                        </td>
                        <td>{{ \App\Helpers\TenantFormatHelper::currency($offer->commission_total) }}</td>
                        <td>{{ optional($offer->issued_at)->format('M d, Y') ?: '-' }}</td>
                        <td class="text-end">
                            @if($offer->isApproved() && $offer->status !== 'pending_approval')
                                <a href="{{ route('deal.offers.print', $offer) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="{{ __('Print') }}">
                                    {{ __('Print') }}
                                </a>
                            @elseif($canApprove)
                                <a href="{{ route('deal.offers.print', $offer) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="{{ __('Print') }}">
                                    {{ __('Print (preview)') }}
                                </a>
                            @endif
                            @if($offer->status === 'pending_approval' && $canApprove)
                                <form method="POST" action="{{ route('deal.offers.approve', $offer) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="{{ __('Approve offer letter') }}">{{ __('Approve') }}</button>
                                </form>
                            @endif
                            @if($offer->isEditable())
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#offer-edit-{{ $offer->id }}" aria-expanded="false" aria-controls="offer-edit-{{ $offer->id }}" title="{{ __('Edit offer letter') }}">{{ __('Edit') }}</button>
                            @endif
                            @if($offer->isApproved())
                                <form method="POST" action="{{ route('deal.offers.uploadSigned', $offer) }}" enctype="multipart/form-data" class="d-inline-flex align-items-center gap-1 ms-1">
                                    @csrf
                                    <input type="file" name="signed_pdf" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required style="width:auto;">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        {{ $offer->isSigned() ? __('Replace Signed Copy') : __('Upload Signed') }}
                                    </button>
                                </form>
                            @endif
                            @if($offer->status === 'signed' && $offer->signed_pdf_path)
                                <a href="{{ route('deal.offers.downloadSigned', $offer) }}" class="btn btn-sm btn-outline-secondary" title="{{ __('Signed copy') }}">
                                    {{ __('Signed PDF') }}
                                </a>
                            @endif
                            @if($offer->canWithdraw())
                                <form method="POST" action="{{ route('deal.offers.withdraw', $offer) }}" class="d-inline ms-1"
                                      onsubmit="return confirm('{{ __('Withdraw this offer letter? It can no longer be signed.') }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="{{ __('Withdraw') }}">{{ __('Withdraw') }}</button>
                                </form>
                            @endif
                            @if($offer->canDelete())
                                <form method="POST" action="{{ route('deal.offers.destroy', $offer) }}" class="d-inline ms-1"
                                      onsubmit="return confirm('{{ __('Permanently delete this offer letter?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Delete') }}">{{ __('Delete') }}</button>
                                </form>
                            @endif
                            @if(in_array($offer->status, ['draft', 'issued', 'pending_approval'], true))
                                <form method="POST" action="{{ route('deal.offers.status', $offer) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="declined">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Decline') }}">{{ __('Decline') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @if($offer->isEditable())
                    <tr class="offer-edit-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collapse" id="offer-edit-{{ $offer->id }}">
                                <div class="p-3 bg-body-tertiary">
                                    <form method="POST" class="offer-letter-form"
                                          data-offer-vat-rate="{{ $offer->vatRate() }}"
                                          action="{{ route('deal.offers.update', $offer) }}">
                                        @csrf
                                        @method('PATCH')
                                        @include('deals._offer_letter_fields', [
                                            'd' => app(\App\Services\OfferLetterService::class)->editDefaults($offer),
                                            'canApprove' => $canApprove,
                                            'submitLabel' => __('Save Changes'),
                                        ])
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <p class="text-secondary mb-0">{{ __('No offer letters yet. Generate one to send to the client for signature.') }}</p>
        @endif
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Mirrors OfferLetterService::buildAmounts so the form shows what will be
    // saved. It is a preview only: the server recomputes from the posted listed
    // price and discount, and ignores any contract value the form sends.
    //
    // VAT rate is read from a data attribute written by the server from
    // Tenant::effectiveVatRate(), never from a form input, because VAT is a fact
    // about the company and only applies to the service lines.
    var FIELDS = [
        '[data-offer-listed]', '[data-offer-discount]', '[data-offer-rate]',
        '[data-offer-commission-value]', '[data-offer-contract-fee]', '[data-offer-admin-fee]'
    ].join(', ');

    function fmt(v) {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(Math.round(v * 100) / 100);
    }

    function recalc(form) {
        var q = function (name) { return form.querySelector('[data-offer-' + name + ']'); };
        var num = function (el) { return el ? (parseFloat(el.value) || 0) : 0; };

        var listed = num(q('listed'));
        var discount = Math.min(Math.max(0, num(q('discount'))), listed);
        var contractValue = Math.max(0, listed - discount);

        var vatRate = parseFloat(form.getAttribute('data-offer-vat-rate') || '0') || 0;

        var basis = form.querySelector('[data-offer-basis]:checked');
        var onValue = basis && basis.value === 'value';
        var commissionNet = onValue
            ? Math.max(0, num(q('commission-value')))
            : contractValue * (num(q('rate')) / 100);

        var contractFee = num(q('contract-fee'));
        var adminFee = num(q('admin-fee'));
        var servicesNet = commissionNet + contractFee + adminFee;

        // Rounded per line, exactly as OfferLetterService does server-side, so the
        // preview is not a few halalas off the figure that actually gets stored.
        var vatOn = function (amount) {
            return Math.round(amount * (vatRate / 100) * 100) / 100;
        };
        var servicesVat = vatOn(commissionNet) + vatOn(contractFee) + vatOn(adminFee);

        // Contract value is repeated in the field and the footer. The field is an
        // <input> and the footer is a <strong>, so writing textContent to the
        // input would silently leave the visible box empty.
        form.querySelectorAll('[data-offer-contract-value], [data-offer-contract-value-out]').forEach(function (el) {
            if (el.tagName === 'INPUT') {
                el.value = fmt(contractValue);
            } else {
                el.textContent = fmt(contractValue);
            }
        });

        var servicesNetEl = form.querySelector('[data-offer-services-net]');
        if (servicesNetEl) servicesNetEl.textContent = fmt(servicesNet);

        var servicesVatEl = form.querySelector('[data-offer-services-vat]');
        if (servicesVatEl) servicesVatEl.textContent = fmt(servicesVat);

        var servicesTotalEl = form.querySelector('[data-offer-services-total]');
        if (servicesTotalEl) servicesTotalEl.textContent = fmt(servicesNet + servicesVat);
    }

    // Percentage and value are two ways of stating one figure; only the chosen
    // one is visible so the form cannot show two disagreeing commissions.
    function syncBasis(form) {
        var basis = form.querySelector('[data-offer-basis]:checked');
        var onValue = basis && basis.value === 'value';
        var pct = form.querySelector('[data-offer-pct-field]');
        var val = form.querySelector('[data-offer-value-field]');
        if (pct) pct.hidden = onValue;
        if (val) val.hidden = !onValue;
    }

    function eachForm(fn) {
        document.querySelectorAll('.offer-letter-form').forEach(fn);
    }

    // End date = start + term - 1 day, mirroring
    // OfferLetterService::resolveContractDates(). The -1 day is what makes a
    // tenancy starting 1 March end on 28 February instead of a full year later.
    function syncEndDate(form) {
        var start = form.querySelector('[data-offer-start]');
        var years = form.querySelector('[data-offer-years]');
        var end = form.querySelector('[data-offer-end]');
        if (!start || !end) return;

        var n = parseInt(years ? years.value : '1', 10);
        if (!isFinite(n) || n < 1) return;
        if (!start.value) return;

        // Build in UTC noon so a DST shift can never walk the date a day.
        var d = new Date(start.value + 'T12:00:00Z');
        if (isNaN(d.getTime())) return;
        d.setUTCFullYear(d.getUTCFullYear() + n);
        d.setUTCDate(d.getUTCDate() - 1);
        end.value = d.toISOString().slice(0, 10);
    }

    // Choosing a unit replaces the inventory-derived figures. Discount and
    // commission are left alone on purpose: they belong to the negotiation, and
    // carrying them across would silently re-price an offer already agreed.
    function applyUnitDefaults(form, data) {
        var put = function (name, value) {
            var el = form.querySelector('[name="' + name + '"]');
            if (!el) return;
            if (el.type === 'hidden') return;
            el.value = (value === null || value === undefined) ? '' : value;
        };

        put('original_amount', data.original_amount);
        put('contract_fee', data.contract_fee);
        put('admin_fee', data.admin_fee);
        put('security_deposit', data.security_deposit);

        var rate = form.querySelector('[data-offer-rate]');
        if (rate && data.commission_rate_pct !== null && data.commission_rate_pct !== undefined) {
            rate.value = data.commission_rate_pct;
        }

        recalc(form);
    }

    function loadUnit(form, unitId) {
        var sel = form.querySelector('[data-offer-unit]');
        if (!sel) return;
        if (!unitId) { recalc(form); return; }

        var url = sel.getAttribute('data-unit-defaults') + '?unit_id=' + encodeURIComponent(unitId);
        fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data) applyUnitDefaults(form, data); })
            .catch(function () { /* leave the typed figures alone on a failed lookup */ });
    }

    // Delegated so both the "new letter" and "edit letter" forms recalculate
    // independently, and so it survives the live-filter results swap.
    document.addEventListener('input', function (e) {
        if (!e.target.matches(FIELDS)) return;
        var form = e.target.closest('.offer-letter-form');
        if (form) recalc(form);
    });

    document.addEventListener('change', function (e) {
        var form = e.target.closest('.offer-letter-form');
        if (!form) return;
        if (e.target.matches('[data-offer-basis]')) syncBasis(form);
        if (e.target.matches('[data-offer-unit]')) loadUnit(form, e.target.value);
        if (e.target.matches('[data-offer-start], [data-offer-years]')) syncEndDate(form);
        if (e.target.matches(FIELDS)) recalc(form);
    });

    function init() {
        eachForm(function (form) {
            syncBasis(form);
            syncEndDate(form);
            recalc(form);
        });
    }

    // Covers both a normal load and a region swapped in by live-filter.
    if (document.readyState !== 'loading') init();
    else document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('insulacrm:live-updated', init);
})();
</script>
@endpush