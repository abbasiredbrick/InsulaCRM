{{-- Offer letters card, used by the Documents & Agreements hub. --}}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Offer Letters') }}</h3>
        <div class="card-actions">
            <span class="text-secondary small">{{ __('Created from the transaction page') }}</span>
        </div>
    </div>
    <div class="card-body">
        @if($offerLetters->isEmpty())
            <p class="text-secondary mb-0">{{ __('No offer letters yet. Issue one from a transaction page to capture the final agreed value.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-vcenter">
                <thead>
                    <tr>
                        <th>{{ __('Offer No') }}</th>
                        <th>{{ __('Client') }}</th>
                        <th>{{ __('Deal') }}</th>
                        <th>{{ __('Amount') }}</th>
                        <th>{{ __('Valid Until') }}</th>
                        <th>{{ __('Status') }}</th>
                        <th class="text-end">{{ __('') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($offerLetters as $offer)
                    @php
                        $finalAmount = $offer->approved_amount ?: $offer->original_amount;
                        $statusDate = $offer->signed_at ?? $offer->declined_at ?? $offer->issued_at ?? $offer->created_at;
                        $statusBadge = [
                            'draft' => 'bg-secondary-lt',
                            'pending_approval' => 'bg-yellow-lt',
                            'issued' => 'bg-azure-lt',
                            'signed' => 'bg-green',
                            'declined' => 'bg-red-lt',
                        ];
                    @endphp
                    <tr>
                        <td>
                            <span class="fw-semibold text-reset">{{ $offer->offer_no ?: __('—') }}</span>
                        </td>
                        <td>{{ $offer->lead?->full_name ?: $offer->deal?->lead?->full_name ?: '—' }}</td>
                        <td>
                            @if($offer->deal)
                                <a href="{{ route('deals.show', $offer->deal) }}" class="fw-semibold text-reset">{{ $offer->deal->title }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            {{ \Fmt::currency($finalAmount) }}
                            @if($offer->hasDiscount())
                                <div class="text-secondary small">
                                    <s>{{ \Fmt::currency($offer->original_amount) }}</s>
                                    @if($offer->approved_amount)
                                        {{ __('approved') }}
                                    @else
                                        {{ __('discount :pct%', ['pct' => rtrim(rtrim((string) $offer->discount_amount, '0'), '.')]) }}
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-secondary small">{{ $offer->valid_until?->format('M d, Y') ?: '—' }}</td>
                        <td>
                            <span class="badge {{ $statusBadge[$offer->status] ?? 'bg-secondary-lt' }}">
                                {{ \App\Models\OfferLetter::STATUSES[$offer->status] ?? $offer->status }}
                            </span>
                            <div class="small text-secondary mt-1">{{ $statusDate?->format('M d, Y') ?: '' }}</div>
                        </td>
                        <td class="text-end">
                            <div class="btn-list justify-content-end">
                                <a href="{{ route('deal.offers.print', $offer) }}" target="_blank" class="btn btn-sm btn-outline">{{ __('Print') }}</a>
                                @if($offer->signed_pdf_path)
                                    <a href="{{ route('deal.offers.downloadSigned', $offer) }}" class="btn btn-sm btn-outline-secondary">{{ __('Signed') }}</a>
                                @endif
                                @if($offer->status === 'pending_approval' && auth()->user()->isAdmin())
                                    <form method="POST" action="{{ route('deal.offers.approve', $offer) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Approve') }}</button>
                                    </form>
                                @endif
                                @if($offer->deal)
                                    <a href="{{ route('deals.show', $offer->deal) }}" class="btn btn-sm btn-outline-primary">{{ __('Deal') }}</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>