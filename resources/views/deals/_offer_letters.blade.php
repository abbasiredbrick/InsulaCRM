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
            <form method="POST" class="offer-letter-form" action="{{ route('deal.offers.store', $deal) }}">
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
                                    <form method="POST" class="offer-letter-form" action="{{ route('deal.offers.update', $offer) }}">
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
    function fmt(v) {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 }).format(v);
    }

    // Delegated so both the "new letter" and "edit letter" forms recalculate
    // independently, and so it survives the live-filter results swap.
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.matches('[data-offer-discount], [data-offer-rate], [data-offer-vat-pct], [data-offer-gross]')) return;

        var form = el.closest('.offer-letter-form');
        if (!form) return;

        var pick = function (name) { return form.querySelector('[data-offer-' + name + ']'); };
        var grossEl = pick('gross'), discountEl = pick('discount');
        var rateEl = pick('rate'), vatEl = pick('vat-pct');
        var netEl = form.querySelector('[data-offer-commission-net]');
        var vatOutEl = form.querySelector('[data-offer-commission-vat]');
        var totalEl = form.querySelector('[data-offer-commission-total]');
        if (!netEl || !vatOutEl || !totalEl || !grossEl || !discountEl) return;

        var gross = parseFloat(grossEl.value) || 0;
        var discount = parseFloat(discountEl.value) || 0;
        var approved = Math.max(0, gross - discount);
        var rate = parseFloat(rateEl ? rateEl.value : 0) || 0;
        var vat = parseFloat(vatEl ? vatEl.value : 0) || 0;

        var net = approved * rate / 100;
        var vatAmt = net * vat / 100;
        netEl.textContent = fmt(net);
        vatOutEl.textContent = fmt(vatAmt);
        totalEl.textContent = fmt(net + vatAmt);
    });
})();
</script>
@endpush