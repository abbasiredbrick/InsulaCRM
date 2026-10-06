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
        @php
            $readiness = app(\App\Services\OfferLetterReadiness::class);
            $offerBlockers = $readiness->blockers($deal);
            $offerWarnings = $readiness->warnings($deal);
        @endphp
        <div class="card-actions">
        {{-- Disabled, not hidden: the reason it is disabled is printed directly
             underneath. A letter that cannot be produced properly must not be
             creatable, and OfferLetterService::createFromValidated() refuses it
             server-side regardless of this button. --}}
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#offer-letter-form" aria-expanded="false" aria-controls="offer-letter-form"
                @disabled(count($offerBlockers) > 0)
                @if(count($offerBlockers) > 0) title="{{ __('Complete the items below before an offer letter can be created.') }}" @endif>
            {{ __('New Offer Letter') }}
            </button>
        </div>
    </div>

    <div class="card-body">
        @php $canApprove = auth()->user()->isAdmin() || auth()->user()->isManager(); @endphp
    @php $signed = $deal->offerLetters->first(fn ($l) => $l->status === 'signed'); @endphp>

    @if(count($offerBlockers) > 0)
        <div class="alert alert-danger py-2" id="offer-letter-readiness">
            <div class="fw-bold mb-1"><small>{{ __('An offer letter cannot be created yet') }}</small></div>
            <ul class="mb-0 ps-3">
                @foreach($offerBlockers as $blocker)
                    <li class="small">
                        {{ $blocker['label'] }}
                        @if($blocker['remedy'] === \App\Services\OfferLetterReadiness::REMEDY_COMPANY)
                            — <a href="{{ route('settings.index') }}">{{ __('Company settings') }}</a>
                        @elseif($blocker['remedy'] === \App\Services\OfferLetterReadiness::REMEDY_CLIENT && $deal->lead)
                            — <a href="{{ route('leads.show', $deal->lead) }}">{{ __('Client record') }}</a>
                        @elseif($blocker['remedy'] === \App\Services\OfferLetterReadiness::REMEDY_DEAL)
                            — <a href="{{ route('deals.show', $deal) }}">{{ __('this deal') }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @elseif(count($offerWarnings) > 0)
        <div class="alert alert-secondary py-2">
            <ul class="mb-0 ps-3">
                @foreach($offerWarnings as $warning)
                    <li class="small">{{ $warning['label'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif
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
                        // Computed once for the whole row: both the actions cell
                        // and the request form below ask the same question.
                        $pendingChange = $offer->isApproved() && ! $offer->isSigned()
                            ? $offer->changeRequests()->where('status', 'pending')->latest('id')->first()
                            : null;
                        // One answer for the whole row, because the Edit button and
                        // the form it collapses must never disagree: asking the
                        // service keeps the rendered button in step with what
                        // updateFromValidated() will actually allow. Deriving them
                        // separately is what hid the button on an approved letter.
                        $canEdit = app(\App\Services\OfferLetterService::class)->canEditTerms($offer, auth()->user());
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
                            @if($canEdit)
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#offer-edit-{{ $offer->id }}" aria-expanded="false" aria-controls="offer-edit-{{ $offer->id }}" title="{{ __('Edit offer letter') }}">{{ __('Edit') }}</button>
                            @endif
                            {{-- Sending to the client is the normal route; uploading a
                                 scanned copy stays for the wet-ink-and-postage cases. --}}
                            @if($offer->canRequestSignature())
                                <button type="button" class="btn btn-sm btn-outline-success ms-1" data-bs-toggle="collapse"
                                        data-bs-target="#sign-{{ $offer->id }}" aria-expanded="false" aria-controls="sign-{{ $offer->id }}"
                                        title="{{ __('Send to the client to sign on their phone') }}">
                                    {{ $offer->signatureRequestIsSent() ? __('Resend Link') : __('Send for Signature') }}
                                </button>
                            @endif

                            @if($offer->signatureRequestIsOpen())
                                <form method="POST" action="{{ route('deal.offers.revokeSignature', $offer) }}" class="d-inline ms-1"
                                      onsubmit="return confirm('{{ __('Close the signing link? The client will no longer be able to sign.') }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="{{ __('Close the signing link') }}">
                                        {{ __('Close Link') }}
                                    </button>
                                </form>
                            @endif

                            @if($offer->signatureRequestIsSent() && ! $offer->isSigned())
                                <span class="badge bg-{{ $offer->signatureRequestIsExpired() ? 'secondary' : 'info' }}-subtle text-{{ $offer->signatureRequestIsExpired() ? 'secondary' : 'dark' }} ms-1">
                                    {{ $offer->signatureRequestIsExpired()
                                        ? __('Link expired')
                                        : __('Awaiting signature — until :time', ['time' => $offer->signature_request_expires_at->format('M j, H:i')]) }}
                                </span>
                            @endif

                            @if($offer->isApproved() && ! $offer->wasSignedByOccupant())
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

                            @if($offer->wasSignedByOccupant())
                                <span class="badge bg-success-subtle text-success ms-1" title="{{ __('Signed by the client on their phone') }}">
                                    {{ __('Signed by client') }}
                                    @if($offer->occupant_signed_at)
                                        {{ $offer->occupant_signed_at->format('M j, H:i') }}
                                    @endif
                                </span>
                            @endif
                            @if($offer->canWithdraw())
                                <form method="POST" action="{{ route('deal.offers.withdraw', $offer) }}" class="d-inline ms-1"
                                      onsubmit="return confirm('{{ __('Withdraw this offer letter? It can no longer be signed.') }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="{{ __('Withdraw') }}">{{ __('Withdraw') }}</button>
                                </form>
                            @endif
                            @if($offer->canDelete() || auth()->user()->isOwner())
                                <form method="POST" action="{{ route('deal.offers.destroy', $offer) }}" class="d-inline ms-1"
                                      onsubmit="return confirm('{{ $offer->canDelete() ? __('Permanently delete this offer letter?') : __('Permanently delete this offer letter and remove it from the activity log?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    @if(! $offer->canDelete())
                                        <span class="text-secondary" title="{{ __('Owner deletion removes the letter and its activity log.') }}">
                                    @endif
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="{{ __('Delete') }}">{{ __('Delete') }}</button>
                                    @if(! $offer->canDelete())
                                        </span>
                                    @endif
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

                            {{-- Change request: the only route to editing terms
                                 that an approver has locked. --}}
                            @if($offer->isApproved() && ! $offer->isSigned() && ! $canApprove)
                                @if($pendingChange)
                                    <span class="badge bg-yellow-lt text-yellow ms-1" title="{{ $pendingChange->reason }}">
                                        {{ __('Change requested') }}
                                    </span>
                                @else
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="collapse"
                                            data-bs-target="#change-req-{{ $offer->id }}" aria-expanded="false" aria-controls="change-req-{{ $offer->id }}">
                                        {{ __('Request Change') }}
                                    </button>
                                @endif
                            @endif
                        </td>
                    </tr>
{{-- An approved letter locks its price and terms.
                                 An approver may still edit it outright; anyone
                                 else has to ask. The button is only rendered for
                                 the case that actually applies, so nobody is
                                 offered an edit that will be refused. --}}
                            @if($canEdit)
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

                    {{-- Approver's inbox for a pending request. Approving only
                         unlocks the edit — the letter goes back to
                         'pending_approval' and must be approved again. --}}
                    @php $openChange = $offer->isApproved() && ! $offer->isSigned()
                            ? $offer->changeRequests()->where('status', 'pending')->latest('id')->first()
                            : null; @endphp
                    @if($openChange && $canApprove)
                    <tr class="offer-change-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="p-3 bg-body-tertiary">
                                <p class="mb-2 small">
                                    <strong>{{ __('Change requested by :name', ['name' => $openChange->requester->name ?? __('an agent')]) }}</strong>
                                    — {{ $openChange->reason }}
                                </p>
                                <form method="POST" action="{{ route('deal.offers.reviewChange', $openChange) }}" class="d-flex flex-wrap gap-2 align-items-center">
                                    @csrf
                                    <input type="text" name="note" class="form-control form-control-sm" style="max-width: 22rem"
                                           placeholder="{{ __('Note (required to reject)') }}"
                                           value="{{ old('note') }}">
                                    <button type="submit" name="decision" value="approve" class="btn btn-sm btn-primary">{{ __('Approve Change') }}</button>
                                    <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger">{{ __('Reject') }}</button>
                                </form>
                                @error('note')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                            </div>
                        </td>
                    </tr>
                    @endif

                    {{-- The request form itself, for the agent who cannot edit. --}}
                    @if($offer->isApproved() && ! $offer->isSigned() && ! $canApprove && ! $pendingChange)
                    <tr class="offer-change-form-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collapse" id="change-req-{{ $offer->id }}">
                                <div class="p-3 bg-body-tertiary">
                                    <form method="POST" action="{{ route('deal.offers.requestChange', $offer) }}">
                                        @csrf
                                        <label class="form-label">{{ __('What needs to change, and why?') }}</label>
                                        <textarea name="reason" rows="2" class="form-control form-control-sm" required
                                                  placeholder="{{ __('e.g. Landlord reduced the rent after the view — please approve the new figure.') }}">{{ old('reason') }}</textarea>
                                        @error('change_request')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                                        <button type="submit" class="btn btn-sm btn-primary mt-2">{{ __('Send Request') }}</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @endif

                    {{-- Send-for-signature panel. Sits on its own row because the
                         button that opens it lives in the actions cell above. --}}
                    @if($offer->canRequestSignature())
                    <tr class="offer-sign-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collapse" id="sign-{{ $offer->id }}">
                                <div class="p-3 bg-body-tertiary">
                                    <form method="POST" action="{{ route('deal.offers.requestSignature', $offer) }}">
                                        @csrf
                                        <div class="row g-3 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label">{{ __('Send the link to') }}</label>
                                                <input type="email" name="signature_email" class="form-control"
                                                       value="{{ old('signature_email', $offer->signature_request_email ?: $offer->deal?->lead?->email) }}"
                                                       placeholder="{{ __('Client email') }}">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">{{ __('Link valid for') }}</label>
                                                <select name="signature_expires_hours" class="form-select">
                                                    <option value="24">{{ __('24 hours') }}</option>
                                                    <option value="72" selected>{{ __('3 days') }}</option>
                                                    <option value="168">{{ __('7 days') }}</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">{{ __('Message (optional)') }}</label>
                                                <input type="text" name="signature_message" class="form-control"
                                                       value="{{ old('signature_message') }}"
                                                       placeholder="{{ __('e.g. Please review and sign by Friday') }}">
                                            </div>
                                            <div class="col-md-2">
                                                <button type="submit" class="btn btn-success w-100">
                                                    {{ $offer->signatureRequestIsSent() ? __('Send New Link') : __('Send Link') }}
                                                </button>
                                            </div>
                                        </div>

                                        @if($offer->signatureRequestIsSent())
                                            <div class="form-text mt-2">
                                                {{ __('A link was sent :when. Sending a new one closes the old link, so only the newest email works.', [
                                                    'when' => optional($offer->signature_requested_at)->diffForHumans(),
                                                ]) }}
                                            </div>
                                        @else
                                            <div class="form-text mt-2">
                                                {{ __('The client opens the link on their phone and signs with their finger. You can also paste the link into WhatsApp if they have no email.') }}
                                            </div>
                                        @endif
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

        // Security deposit is five per cent of the contract value, so it has to
        // move when a discount changes it — otherwise the form asks for a deposit
        // that no longer matches the rent the client just agreed to. The server
        // does the same in resolveSecurityDeposit(); this is only the preview.
        var depositMode = form.querySelector('[data-offer-deposit-mode]');
        var deposit = q('deposit');
        if (deposit && (!depositMode || depositMode.value === 'percent_5')) {
            deposit.value = contractValue > 0 ? fmt(contractValue * 0.05) : '';
        }

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
        if (e.target.matches('[data-offer-deposit-mode]')) syncDepositMode(form);
        if (e.target.matches(FIELDS)) recalc(form);
    });

    // Switching the deposit to a manual figure hands the box back to the agent;
    // switching to 5% takes it back under the server's rule.
    function syncDepositMode(form) {
        var mode = form.querySelector('[data-offer-deposit-mode]');
        var deposit = form.querySelector('[data-offer-deposit]');
        if (!mode || !deposit) return;
        var manual = mode.value === 'custom';
        deposit.readOnly = !manual;
        if (manual) {
            deposit.removeAttribute('aria-label');
        } else {
            deposit.setAttribute('aria-label', 'Security Deposit');
        }
        recalc(form);
    }

    function init() {
        eachForm(function (form) {
            syncBasis(form);
            syncEndDate(form);
            syncDepositMode(form);
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