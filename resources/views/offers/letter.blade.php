{{--
    Printable offer letter (A4). Mirrors the reference letter: tenant as the
    offering party, final value, fees, agency commission with VAT, documents
    required, validity and signature blocks.

    Variables: $offer, $deal, $lead, $property, $tenant
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('Offer Letter') }} {{ $offer->offer_no }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 12.5px;
            line-height: 1.55;
            color: #1a1a1a;
            background: #f1f3f5;
        }
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 210mm;
            margin: 0 auto 12px;
            padding: 12px 16px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .print-toolbar .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            background: #0b3954;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
        }
        .print-toolbar .btn:hover { background: #07293c; }
        .print-toolbar .btn-outline {
            color: #475569;
            background: transparent;
            border: 1px solid #cbd5e1;
        }
        .print-toolbar .btn-outline:hover { background: #f1f3f5; }
        .print-toolbar .spacer { flex: 1; }
        .sheet {
            max-width: 210mm;
            min-height: 296mm;
            margin: 16px auto;
            background: #fff;
            padding: 18mm 16mm;
            border: 1px solid #dee2e6;
        }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0b3954; padding-bottom: 10px; margin-bottom: 14px; }
        .head .offering { }
        .head .offering h1 { font-size: 20px; color: #0b3954; letter-spacing: 0.4px; }
        .head .offering p { color: #495057; font-size: 11px; }
        .head .meta { text-align: right; font-size: 11px; color: #495057; }
        .head .meta strong { display: block; font-size: 13px; color: #0b3954; }
        h2.subject { font-size: 14.5px; color: #0b3954; margin-bottom: 10px; }
        .kicker { font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.2px; color: #6c757d; margin: 14px 0 4px; }
        table.terms { width: 100%; border-collapse: collapse; margin: 4px 0 6px; }
        table.terms td { border-bottom: 1px dotted #ced4da; padding: 4.5px 4px; vertical-align: top; }
        table.terms td.k { width: 42%; color: #343a40; font-weight: 600; }
        table.terms td.v { text-align: right; }
        table.payments { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.payments th { background: #0b3954; color: #fff; padding: 6px 8px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; }
        table.payments td { border-bottom: 1px solid #dee2e6; padding: 7px 8px; }
        table.payments td.amt { text-align: right; white-space: nowrap; font-weight: 600; }
        table.payments td.payee { text-align: right; color: #495057; width: 30%; }
        .words { font-style: italic; color: #495057; font-size: 11.5px; margin-top: 2px; }
        .notice { background: #fff8e1; border: 1px solid #f0d67b; border-radius: 4px; padding: 8px 10px; font-size: 11.5px; margin-top: 12px; }
        .docs { margin: 8px 0 0 2px; }
        .docs li { margin-left: 18px; }
        .signatures { display: flex; gap: 40px; margin-top: 34px; }
        .signatures .sig { flex: 1; border-top: 1px solid #343a40; padding-top: 6px; font-size: 11px; }
        .footer { margin-top: 26px; font-size: 10px; color: #868e96; border-top: 1px solid #dee2e6; padding-top: 8px; text-align: center; }
        @media print {
            body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet { margin: 0; border: none; padding: 12mm; max-width: none; min-height: auto; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <button class="btn" onclick="window.print()">Print / Save as PDF</button>
        <a href="{{ $deal ? route('deals.show', $deal) : '#' }}" class="btn btn-outline">Back to Deal</a>
        <span class="spacer"></span>
        <span style="color:#64748b; font-size:12px;">Use "Save as PDF" in the print dialog to export.</span>
    </div>
    <div class="sheet">
        <div class="head">
            <div class="offering">
                <h1>{{ $tenant->name }}</h1>
                @if($tenant->address)<p>{{ $tenant->address }}</p>@endif
                <p>{{ trim(collect([$tenant->phone, $tenant->email, $tenant->website])->reject(fn ($v) => blank($v))->implode('  •  ')) }}</p>
            </div>
            <div class="meta">
                <strong>{{ __('Offer No.') }} {{ $offer->offer_no }}</strong>
                <div>{{ __('Date') }}: <span id="offer-date">{{ optional($offer->issued_at)->format('F j, Y') ?: now()->format('F j, Y') }}</span></div>
                <div>{{ $deal ? \App\Models\Deal::stageLabel($deal->stage) : '' }}</div>
            </div>
        </div>

        <h2 class="subject">{{ $property?->address ?? $deal?->title }}</h2>

        <div class="kicker">{{ __('Tenancy / Sale Terms') }}</div>
        @php $dealName = ($property?->display_name ?? null) ?: ($deal?->title ?? '-'); @endphp
        <table class="terms">
            <tr><td class="k">{{ __('Property Details') }}</td><td class="v">{{ $dealName ?: ($deal?->title ?? '-') }}</td></tr>
            @if($property?->unit_no)
            <tr><td class="k">{{ __('Unit No.') }}</td><td class="v">{{ $property->unit_no }}</td></tr>
            @endif
            @if($property?->owner_name)
            <tr><td class="k">{{ __('Owner / Landlord') }}</td><td class="v">{{ $property->owner_name }}</td></tr>
            @endif
            @if($lead)
            <tr><td class="k">{{ __('Occupant Name') }}</td><td class="v">{{ $lead->full_name }}@if($lead->custom_fields['nationality'] ?? null) ({{ $lead->custom_fields['nationality'] }})@endif</td></tr>
            @endif
            @if($deal?->dealType() === 'rent')
            @php $years = max(1, (int) ($offer->contract_years ?? 1)); @endphp
            <tr><td class="k">{{ __('Tenure of Tenancy') }}</td>
                <td class="v">
                    @if($offer->contract_start_date && $offer->contract_end_date)
                        {{ $offer->contract_start_date->format('M d, Y') }} {{ __('to') }} {{ $offer->contract_end_date->format('M d, Y') }}
                        ({{ $years }} {{ $years == 1 ? __('Year') : __('Years') }})
                    @else{{ $years == 1 ? __('One (1) Year') : $years.' '.__('Years') }}@endif
                </td>
            </tr>
            @endif
        </table>

        <div class="kicker">{{ __('Payments') }}</div>
        @php
            $words = app(\App\Services\OfferLetterService::class);
            $cur = strtoupper($tenant->currency ?? 'AED');
            $money = fn ($v) => \App\Helpers\TenantFormatHelper::currency($v);
            $rate = rtrim(rtrim(number_format((float) $offer->commission_vat_pct, 2, '.', ''), '0'), '.');
        @endphp

        {{-- The advertised price and the discount are printed above the contract
             value rather than folded into a notice at the bottom, because all
             three are figures the client is entitled to see and the contract
             value is only explicable next to them. --}}
        <table class="terms">
            <tr>
                <td class="k">{{ __('Unit Price (as listed)') }}</td>
                <td class="v">{{ $money($offer->original_amount) }}</td>
            </tr>
            @if($offer->hasDiscount())
            <tr>
                <td class="k">{{ __('Discount Value') }}</td>
                <td class="v">− {{ $money($offer->discount_amount) }}</td>
            </tr>
            @endif
            <tr>
                <td class="k" style="font-weight:700;">{{ __('Contract Value') }}</td>
                <td class="v" style="font-weight:700;">{{ $money($offer->approved_amount) }}</td>
            </tr>
        </table>

        <table class="payments">
            <thead>
                <tr><th>{{ __('Item') }}</th><th class="amt" style="text-align:right;">{{ __('Amount') }}</th><th style="text-align:right;">{{ __('Payable To') }}</th></tr>
            </thead>
            <tbody>
                {{-- Contract value. No VAT on this line: residential lease and
                     residential sale consideration are not VATable in the UAE. --}}
                <tr>
                    <td>
                        {{ $deal?->dealType() === 'rent' ? __('Rental Amount') : __('Sales Amount') }}
                        @if($deal?->dealType() === 'rent' && $offer->paymentPeriodLabel()) — {{ $offer->paymentPeriodLabel() }}@endif
                        <div class="words">{{ __('(Amount in words):') }} {{ $words->amountInWords($offer->approved_amount, $cur) }} {{ __('Only') }}</div>
                        @if($offer->hasDiscount())
                        <div class="words">{{ __('After discount of :amount on the listed price.', ['amount' => $money($offer->discount_amount)]) }}</div>
                        @endif
                    </td>
                    <td class="amt">{{ $money($offer->approved_amount) }}</td>
                    <td class="payee">{{ $property?->owner_name ?? ($deal?->dealType() === 'rent' ? __('Landlord') : __('Seller')) }}</td>
                </tr>
                <tr>
                    <td>{{ __('Security Deposit') }}@if($deal?->dealType() === 'rent' && $offer->approved_amount > 0) (5% {{ __('of annual rent') }})@endif<div class="words">{{ $words->amountInWords($offer->security_deposit, $cur) }} {{ __('Only') }}</div></td>
                    <td class="amt">{{ $money($offer->security_deposit) }}</td>
                    <td class="payee">{{ $tenant->name }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Everything below this is a service we charge, and these are the only
             lines that carry VAT. --}}
        <div class="kicker">{{ __('Agency Services') }}</div>
        <table class="payments">
            <thead>
                <tr>
                    <th>{{ __('Service') }}</th>
                    <th style="text-align:right;">{{ __('Amount') }}</th>
                    @if($offer->chargesVat())<th style="text-align:right;">{{ __('VAT @ :rate%', ['rate' => $rate]) }}</th>@endif
                    <th style="text-align:right;">{{ __('Total') }}</th>
                    <th style="text-align:right;">{{ __('Payable To') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($offer->serviceCharges() as $charge)
                <tr>
                    <td>{{ $charge['label'] }}</td>
                    <td class="amt">{{ $money($charge['net']) }}</td>
                    @if($offer->chargesVat())<td class="amt">{{ $money($charge['vat']) }}</td>@endif
                    <td class="amt">{{ $money($charge['total']) }}</td>
                    <td class="payee">{{ $tenant->name }}</td>
                </tr>
                @endforeach
            </tbody>
            @if($offer->chargesVat())
            <tfoot>
                <tr>
                    <td style="font-weight:700;">{{ __('Total Payable to Agency') }}</td>
                    <td class="amt" style="font-weight:700;">{{ $money(array_sum(array_column($offer->serviceCharges(), 'net'))) }}</td>
                    <td class="amt" style="font-weight:700;">{{ $money($offer->totalVat()) }}</td>
                    <td class="amt" style="font-weight:700;">{{ $money(array_sum(array_column($offer->serviceCharges(), 'total'))) }}</td>
                    <td class="payee"></td>
                </tr>
            </tfoot>
            @endif
        </table>

        @if($offer->hasDiscount() && ! $offer->discount_approved_by)
        <div class="notice">{{ __('The discount above is pending manager approval and is not binding until it is approved.') }}</div>
        @endif

        <div class="notice">
            <strong>{{ __('Non-Refundable:') }}</strong>
            {{ __('The deposit and commission are strictly non-refundable and shall be forfeited in the event the occupant fails to proceed with the tenancy / purchase after signing this offer letter and once the contract is initiated with the relevant authorities (ADREC / RERA).') }}
        </div>

        <div class="kicker">{{ __('Documents Required') }}</div>
        <ul class="docs">
            @php $docs = $offer->documents_required ? explode("\n", $offer->documents_required) : [__('Passport'), __('Residency Visa & Emirates ID Copy')]; @endphp
            @forelse($docs as $doc)
                <li>{{ trim($doc) }}</li>
            @empty
                <li>{{ __('Passport') }}</li>
                <li>{{ __('Residency Visa & Emirates ID Copy') }}</li>
            @endforelse
        </ul>

        <div class="kicker">{{ __('Offer Validity') }}</div>
        <p>{{ __('This offer is valid until') }} <strong>{{ optional($offer->valid_until)->format('F j, Y') ?: __('not specified') }}</strong> {{ __('and is subject to the terms above.') }}</p>

        @if($offer->notes)
        <div class="kicker">{{ __('Notes') }}</div>
        <p>{{ $offer->notes }}</p>
        @endif

        <div class="signatures">
            <div class="sig">
                <div style="font-weight:700;">{{ __('For') }} {{ $tenant->name }}</div>
                <div>{{ __('Managing Director') }}</div>
                <div style="margin-top:14px;color:#495057;">{{ __('Name / Signature / Date') }}</div>
            </div>
            <div class="sig">
                <div style="font-weight:700;">{{ $lead?->full_name ?? __('Occupant') }}</div>
                <div>{{ __('Tenant / Purchaser') }}</div>
                <div style="margin-top:14px;color:#495057;">{{ __('Name / Signature / Date') }}</div>
            </div>
        </div>

        <div class="footer">{{ $tenant->name }} — {{ __('Offer No.') }} {{ $offer->offer_no }}</div>
    </div>
</body>
</html>