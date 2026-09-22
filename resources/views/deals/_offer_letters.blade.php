{{--
    Offer Letters panel (real estate mode) — shown on the deal detail page.

    Generate -> printable letter -> client signs -> upload signed copy. Only a
    signed offer unlocks closing the deal as Won.
--}}
<div class="card mb-3" id="offer-letters-panel">
    <div class="card-header">
        <h3 class="card-title">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11v6"/><path d="M9 14l3 -3l3 3"/></svg>
            {{ __('Offer Letters') }}
            <span class="badge bg-secondary-lt ms-2">{{ count($deal->offerLetters) }}</span>
        </h3>
        <div class="card-actions">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#offer-letter-form" aria-expanded="false" aria-controls="offer-letter-form">
                {{ __('New Offer Letter') }}
            </button>
        </div>
    </div>

    <div class="card-body">
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
            <form method="POST" action="{{ route('deal.offers.store', $deal) }}">
                @csrf
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

                    <div class="col-md-3">
                        <label class="form-label">{{ __('Contract Value / Annual Rent') }} ({{ strtoupper($deal->tenant?->currency ?? 'AED') }})</label>
                        <input type="number" name="original_amount" class="form-control form-control-sm" step="0.01" min="0" value="{{ $d['original_amount'] }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Discount') }} ({{ strtoupper($deal->tenant?->currency ?? 'AED') }})</label>
                        <input type="number" name="discount_amount" id="offer-discount" class="form-control form-control-sm" step="0.01" min="0" value="0">
                        @if(! $canApprove)
                            <small class="form-hint">{{ __('Requires manager approval.') }}</small>
                        @endif
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Commission Rate %') }}</label>
                        <input type="number" name="commission_rate_pct" class="form-control form-control-sm" step="0.01" min="0" max="100" value="{{ $d['commission_rate_pct'] }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('VAT %') }}</label>
                        <input type="number" name="commission_vat_pct" class="form-control form-control-sm" step="0.01" min="0" max="100" value="{{ $d['commission_vat_pct'] }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">{{ __('Tawtheeq Fee') }}</label>
                        <input type="number" name="tawtheeq_fee" class="form-control form-control-sm" step="0.01" min="0" value="{{ $d['tawtheeq_fee'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Admin Fee + VAT') }}</label>
                        <input type="number" name="admin_fee" class="form-control form-control-sm" step="0.01" min="0" value="{{ $d['admin_fee'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Security Deposit') }}</label>
                        <input type="number" name="security_deposit" class="form-control form-control-sm" step="0.01" min="0" value="{{ $d['security_deposit'] ?? '' }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Payment Period') }}</label>
                        <input type="text" name="payment_period" class="form-control form-control-sm" value="{{ $d['payment_period'] ?? '1 Payment' }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">{{ __('Documents Required') }}</label>
                        <input type="text" name="documents_required" class="form-control form-control-sm" value="{{ $d['documents_required'] }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Notes') }}</label>
                        <input type="text" name="notes" class="form-control form-control-sm">
                    </div>
                </div>

                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <div class="text-secondary small" id="offer-preview">
                        {{ __('Commission:') }} <strong id="offer-commission-net">{{ \App\Helpers\TenantFormatHelper::currency($d['commission_amount']) }}</strong>
                        + {{ __('VAT:') }} <strong id="offer-commission-vat">{{ \App\Helpers\TenantFormatHelper::currency($d['commission_vat']) }}</strong>
                        = <strong id="offer-commission-total">
                            {{ \App\Helpers\TenantFormatHelper::currency($d['commission_total']) }}
                        </strong>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('Generate Offer Letter') }}</button>
                </div>
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
                        $statusColors = ['draft' => 'bg-secondary-lt', 'pending_approval' => 'bg-yellow-lt', 'issued' => 'bg-azure-lt', 'signed' => 'bg-green-lt', 'declined' => 'bg-red-lt'];
                        $oc = $statusColors[$offer->status] ?? 'bg-secondary-lt';
                    @endphp
                    <tr>
                        <td class="fw-bold">{{ $offer->offer_no }}</td>
                        <td>
                            <span class="badge {{ $oc }}">{{ __(\App\Models\OfferLetter::STATUSES[$offer->status] ?? ucfirst($offer->status)) }}</span>
                            @if($offer->status === 'signed' && $offer->signed_at)
                                <div class="text-secondary small">{{ $offer->signed_at->diffForHumans() }}</div>
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
                            <a href="{{ route('deal.offers.print', $offer) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="{{ __('Print') }}">
                                {{ __('Print') }}
                            </a>
                            @if($offer->status === 'pending_approval' && auth()->user()->isAdmin())
                                <form method="POST" action="{{ route('deal.offers.approve', $offer) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="{{ __('Approve discount') }}">{{ __('Approve') }}</button>
                                </form>
                            @endif
                            @if(in_array($offer->status, ['draft', 'issued', 'pending_approval'], true))
                                <form method="POST" action="{{ route('deal.offers.uploadSigned', $offer) }}" enctype="multipart/form-data" class="d-inline-flex align-items-center gap-1 ms-1">
                                    @csrf
                                    <input type="file" name="signed_pdf" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required style="width:auto;">
                                    <button type="submit" class="btn btn-sm btn-success">{{ __('Upload Signed') }}</button>
                                </form>
                            @endif
                            @if($offer->status === 'signed' && $offer->signed_pdf_path)
                                <a href="{{ route('deal.offers.downloadSigned', $offer) }}" class="btn btn-sm btn-outline-secondary" title="{{ __('Signed copy') }}">
                                    {{ __('Signed PDF') }}
                                </a>
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
document.addEventListener('DOMContentLoaded', function () {
    var discountEl = document.getElementById('offer-discount');
    var rateEl = document.querySelector('input[name="commission_rate_pct"]');
    var vatEl = document.querySelector('input[name="commission_vat_pct"]');
    var grossEl = document.querySelector('input[name="original_amount"]');
    if (!discountEl || !rateEl) return;

    var netEl = document.getElementById('offer-commission-net');
    var vatOutEl = document.getElementById('offer-commission-vat');
    var totalEl = document.getElementById('offer-commission-total');

    function fmt(v) {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(v);
    }
    function recalc() {
        var gross = parseFloat(grossEl.value) || 0;
        var discount = parseFloat(discountEl.value) || 0;
        var approved = Math.max(0, gross - discount);
        var rate = parseFloat(rateEl.value) || 0;
        var vat = parseFloat(vatEl.value) || 0;
        var net = approved * rate / 100;
        var vatAmt = net * vat / 100;
        netEl.textContent = fmt(net);
        vatOutEl.textContent = fmt(vatAmt);
        totalEl.textContent = fmt(net + vatAmt);
    }
    discountEl.addEventListener('input', recalc);
    rateEl.addEventListener('input', recalc);
    vatEl.addEventListener('input', recalc);
    grossEl.addEventListener('input', recalc);
});
</script>
@endpush