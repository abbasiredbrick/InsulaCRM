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
            line-height: 1.45;
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
            width: 210mm;
            max-width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            background: #fff;
            padding: 12mm;
            border: 1px solid #dee2e6;
            /* Flex column so .verify-anchor can sit at the foot of the sheet.
               Must be declared outside @media print too: the anchoring has to
               survive into the printed output, which is the whole point. */
            display: flex;
            flex-direction: column;
        }
        {{-- Screen-only separation between sheets. Pagination is owned entirely
             by @media print below; declaring a page break here as well would
             make the two rules fight and emit blank pages. --}}
        .page + .sheet, .sheet + .sheet { margin-top: 16px; }
        .verify { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #dee2e6; }
        /* On the sheet that is printed last, the block is pushed to the foot of
           the page so it reads as a colophon rather than trailing content. Only
           works while the sheet is a flex column, hence the print rule below. */
        .verify-anchor { margin-top: auto; padding-top: 12px; }
        /* Keeps the code and the footer in the same unbreakable unit. */
        .last-page-block { margin-top: auto; page-break-inside: avoid; break-inside: avoid; }
        .verify-qr { width: 76px; flex: 0 0 76px; }
        .verify-qr svg { width: 100%; height: auto; display: block; }
        .verify-text { font-size: 10.5px; color: #495057; }
        .verify-url { font-size: 8.5px; line-height: 1.3; color: #868e96; word-break: break-all; margin-top: 2px; }
        .bank-details { font-size: 12px; line-height: 1.8; margin-top: 10px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0b3954; padding-bottom: 6px; margin-bottom: 8px; }
        .head .offering { }
        .head .offering h1 { font-size: 20px; color: #0b3954; letter-spacing: 0.4px; }
        .head .offering p { color: #495057; font-size: 11px; }
        .head .meta { text-align: right; font-size: 11px; color: #495057; }
        .head .meta strong { display: block; font-size: 13px; color: #0b3954; }
        h2.subject { font-size: 14.5px; color: #0b3954; margin-bottom: 6px; }
        .kicker { font-size: 10.5px; text-transform: uppercase; letter-spacing: 1.2px; color: #6c757d; margin: 7px 0 2px; }
        table.terms { width: 100%; border-collapse: collapse; margin: 3px 0 4px; }
        table.terms td { border-bottom: 1px dotted #ced4da; padding: 2px 4px; vertical-align: top; }
        table.terms td.k { width: 42%; color: #343a40; font-weight: 600; }
        table.terms td.v { text-align: right; }
        table.payments { width: 100%; border-collapse: collapse; margin: 4px 0; }
        table.payments th { background: #0b3954; color: #fff; padding: 5px 8px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.6px; }
        table.payments td { border-bottom: 1px solid #dee2e6; padding: 3px 8px; }
        table.payments td.amt { text-align: right; white-space: nowrap; font-weight: 600; }
        table.payments td.payee { text-align: right; color: #495057; width: 30%; }
        .words { font-style: italic; color: #495057; font-size: 11.5px; margin-top: 2px; line-height: 1.4; }
        .notice { background: #fff8e1; border: 1px solid #f0d67b; border-radius: 4px; padding: 6px 10px; font-size: 11.5px; margin-top: 6px; }
        .docs { margin: 8px 0 0 2px; }
        .docs li { margin-left: 18px; line-height: 1.4; }
        .signatures { display: flex; gap: 40px; margin-top: 6px; }
        .signatures .sig { flex: 1; border-top: 1px solid #343a40; padding-top: 6px; font-size: 11px; }
        .footer { margin-top: 10px; font-size: 10px; color: #868e96; border-top: 1px solid #dee2e6; padding-top: 6px; text-align: center; }
        /* One printed page is one A4 sheet, edge to edge. Without an explicit
           @page size the browser falls back to its own default paper (often
           US Letter) and applies its own margin on top of .sheet's padding, so
           the content overflows by a few millimetres and the last lines of a
           block get pushed onto a page of their own — the uneven split. */
        @page { size: A4 portrait; margin: 0; }
        @media print {
            html, body { background: #fff; }
            .print-toolbar { display: none !important; }
            .sheet {
                /* Margins live on .sheet, not on @page, so screen and print
                   preview share one geometry and the two never drift. */
                margin: 0;
                border: none;
                box-shadow: none;
                padding: 12mm;
                max-width: none;
                width: auto;
                min-height: 0;
                page-break-after: always;
                break-after: page;
            }
            /* The trailing sheet must not leave a blank page behind it. The verification
               block carries .sheet too when it is the last thing printed, so
               :last-of-type covers both cases. */
.sheet:last-of-type { page-break-after: auto; break-after: auto; }
            /* Never split these: a signature rule or table header stranded
               at the top of a page is what makes a letter look broken. */
            .signatures, .verify, .head, .footer, table.terms, table.payments,
            .notice, .bank-details, tr, img {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            /* Keep a heading with the text it introduces. */
            h1, h2.subject, .kicker {
                page-break-after: avoid;
                break-after: avoid-page;
            }
            table.payments thead { display: table-header-group; }
            table.terms tr, table.payments tr { page-break-inside: avoid; }
            .words, p, li { orphans: 3; widows: 3; }
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
        @php
        // Which parts of the letterhead print is a tenant setting. A letter that
        // names no company is not acceptable, so 'logo' falls back to the name
        // when no logo has actually been uploaded — otherwise picking "logo
        // only" before uploading one would emit a blank letterhead.
        $letterhead = in_array($tenant->letterhead_display ?? 'both', ['logo', 'name', 'both'], true)
            ? $tenant->letterhead_display
            : 'both';
        $hasLogo = filled($tenant->logo_path ?? null);
        $showName = $letterhead !== 'logo' || ! $hasLogo;
        $showLogo = $hasLogo && $letterhead !== 'name';
        $logoUrl = $hasLogo ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path) : null;
    @endphp
        <div class="head">
            <div class="offering">
                @if($showLogo)
                    <img src="{{ $logoUrl }}" alt="{{ $tenant->name }}" style="max-height: 58px; max-width: 210px; margin-bottom: 6px;">
                @endif
                @if($showName)
                    <h1>{{ $tenant->name }}</h1>
                @endif
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
            <tr><td class="k">{{ __('Property Details') }}</td><td class="v">
                {{-- One line, most specific first: "Unit No. 2506, Burj Al Shams Tower,
                     Reem Island, Abu Dhabi". Each part is dropped when the inventory
                     row has no value, so a half-filled unit never prints "Unit No. ,". --}}
                {{ trim(collect([
                    filled($property->unit_no ?? null) ? __('Unit No.').' '.$property->unit_no : null,
                    $property->building_no ?? null,
                    $property->sub_community ?? null,
                    $property->community ?? null,
                    $property->city ?? null,
                    $property->state ?? null,
                ])->reject(fn ($v) => blank($v))->implode(', ')) ?: ($dealName ?: ($deal?->title ?? '-')) }}
            </td></tr>
            @if($property?->owner_name)
            <tr><td class="k">{{ __('Owner / Landlord') }}</td><td class="v">{{ $property->owner_name }}</td></tr>
            @endif
            {{-- The occupant is the signatory, so it is its own field on the letter
                 rather than read off the lead: the person signing is regularly a
                 spouse or nominee rather than the person who made the enquiry. --}}
            @if(filled($offer->occupant_name) || $lead)
            <tr><td class="k">{{ __('Occupant Name') }}</td><td class="v">{{ $offer->occupant_name ?: $lead?->full_name }}@if($lead?->custom_fields['nationality'] ?? null) ({{ $lead->custom_fields['nationality'] }})@endif</td></tr>
            @endif
            @if(filled($offer->emirates_id))
            <tr><td class="k">{{ __('Emirates ID No.') }}</td><td class="v">{{ $offer->emirates_id }}</td></tr>
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

        {{-- One table for every amount the tenant owes, each row naming who
             collects it. Rent, deposit, commission, admin fee and contract fee
             used to sit in two tables, which read as two separate obligations and
             left the payee hardcoded per table. Whether the landlord or the agency
             collects a given line changes between letters, so it is a column. --}}
        @php $payable = $offer->payableLines(); @endphp
        <table class="payments">
            <thead>
                <tr>
                    <th>{{ __('Item') }}</th>
                    <th style="text-align:right;">{{ __('Amount') }}</th>
                    @if($offer->chargesVat())<th style="text-align:right;">{{ __('VAT @ :rate%', ['rate' => $rate]) }}</th>@endif
                    <th style="text-align:right;">{{ __('Total') }}</th>
                    <th style="text-align:right;">{{ __('Payable To') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payable as $line)
                <tr>
                    <td>
                        {{ $line['label'] }}
                        @if(in_array($line['key'], ['rent', 'deposit'], true))
                            <div class="words">{{ __('(Amount in words):') }} {{ $words->amountInWords($line['total'], $cur) }} {{ __('Only') }}</div>
                        @endif
                        @if($line['key'] === 'rent' && $offer->hasDiscount())
                            <div class="words">{{ __('After discount of :amount on the listed price.', ['amount' => $money($offer->discount_amount)]) }}</div>
                        @endif
                    </td>
                    <td class="amt">{{ $money($line['net']) }}</td>
                    @if($offer->chargesVat())<td class="amt">{{ $line['vat_charges'] ? $money($line['vat']) : '—' }}</td>@endif
                    <td class="amt">{{ $money($line['total']) }}</td>
                    <td class="payee">{{ $line['payee'] }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                @php
                    $brokerTotal = array_sum(array_map(fn ($l) => $l['key'] !== 'rent' && $l['key'] !== 'deposit' ? $l['total'] : 0, $payable));
                    $rentDeposit = array_sum(array_map(fn ($l) => in_array($l['key'], ['rent', 'deposit'], true) ? $l['total'] : 0, $payable));
                @endphp
                <tr>
                    <td style="font-weight:700;">{{ __('Total Payable') }}</td>
                    <td class="amt" style="font-weight:700;">{{ $money($rentDeposit + $brokerTotal) }}</td>
                    @if($offer->chargesVat())<td class="amt" style="font-weight:700;">{{ $money($offer->totalVat()) }}</td>@endif
                    <td class="amt" style="font-weight:700;">{{ $money($rentDeposit + $brokerTotal) }}</td>
                    <td class="payee"></td>
                </tr>
            </tfoot>
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
                @if($signatureUrl)
                    {{-- The agency's authorised signature and seal, printed only once
                         the letter is approved. On a pending letter this would be a
                         mark of authority the manager has not granted yet. --}}
                    <img src="{{ $signatureUrl }}" alt="{{ __('Signature') }}" style="max-height:56px;max-width:170px;margin-bottom:4px;">
                    @if($stampUrl)
                        <img src="{{ $stampUrl }}" alt="{{ __('Stamp') }}" style="max-height:74px;max-width:74px;margin-top:6px;opacity:.92;">
                    @endif
                @endif
                <div style="font-weight:700;">{{ __('For') }} {{ $tenant->name }}</div>
                <div>{{ __('Managing Director') }}</div>
                <div style="margin-top:14px;color:#495057;">{{ __('Name / Signature / Date') }}</div>
            </div>
            <div class="sig">
                {{-- The occupant's captured mark. Printed in place of the blank
                     rule, so the letter that comes back out of the system is the
                     one they actually signed rather than an unsigned original. --}}
                @if(! empty($occupantSignatureUrl))
                    <img src="{{ $occupantSignatureUrl }}" alt="{{ __('Client signature') }}" style="max-height:56px;max-width:170px;margin-bottom:6px;">
                    <div style="font-size:10px;color:#495057;">
                        {{ __('Signed') }} {{ optional($offer->occupant_signed_at)->format('F j, Y H:i') }}
                        @if($offer->occupant_signer_name)
                            &middot; {{ $offer->occupant_signer_name }}
                        @endif
                    </div>
                @else
                    <div style="margin-top:14px;color:#495057;">{{ __('Name / Signature / Date') }}</div>
                @endif
                <div style="font-weight:700;">{{ $offer->occupant_name ?: ($lead?->full_name ?? __('Occupant')) }}</div>
                <div>{{ __('Tenant / Purchaser') }}</div>
            </div>
        </div>

        {{-- The verification block sits at the foot of the LAST printed page.
             It used to print on the first sheet, so on a two-page letter the
             reader had to flip back to find it, and a block that straddled a page
             boundary produced an unscannable, half-printed code.

             So it is emitted into whichever sheet turns out to be last: the bank
             page when there is one, otherwise the letter sheet itself. That
             decision is made once, here, rather than by printing the block twice
             and hiding one copy. --}}
        @if(! $bankDetails && ! $ibanLetterUrl)
        <div class="last-page-block">
            @include('offers._verify_block', ['qrSvg' => $qrSvg, 'verifyUrl' => $verifyUrl, 'tenant' => $tenant, 'anchor' => false])
            <div class="footer">{{ $tenant->name }} — {{ __('Offer No.') }} {{ $offer->offer_no }}</div>
        </div>
        @endif
        </div>{{-- end letter sheet --}}

        @if($bankDetails || $ibanLetterUrl)
        {{-- Its own A4 sheet, and a *sibling* of the letter sheet rather than a
             child of it. Nested inside, it inherited the outer sheet's 16mm
             padding and so printed with a double margin, indented from the
             first page. --}}
        <div class="sheet page">
            <div class="head">
                <div class="offering">
                    @if($showLogo)<img src="{{ $logoUrl }}" alt="{{ $tenant->name }}" style="max-height:58px;max-width:210px;margin-bottom:6px;">@endif
                    @if($showName)<h1>{{ $tenant->name }}</h1>@endif
                </div>
                <div class="meta">
                    <strong>{{ __('Offer No.') }} {{ $offer->offer_no }}</strong>
                    <div>{{ __('Date') }}: {{ optional($offer->issued_at)->format('F j, Y') ?: now()->format('F j, Y') }}</div>
                </div>
            </div>

            <div class="kicker">{{ __('Bank Details') }}</div>
            <p class="small">{{ __('Please make all payments using the details below and quote offer number') }} {{ $offer->offer_no }}.</p>

            @if($ibanLetterUrl)
                <img src="{{ $ibanLetterUrl }}" alt="{{ __('IBAN Letter') }}" style="display:block; margin:0 auto; max-width:100%; max-height:620px; width:auto; height:auto; object-fit:contain;">
            @endif

            @if($bankDetails)
                <div class="bank-details">{!! nl2br(e($bankDetails)) !!}</div>
            @endif

            <div class="last-page-block">
                @include('offers._verify_block', ['qrSvg' => $qrSvg, 'verifyUrl' => $verifyUrl, 'tenant' => $tenant, 'anchor' => false])
                <div class="footer">{{ $tenant->name }} — {{ __('Offer No.') }} {{ $offer->offer_no }}</div>
            </div>
        </div>
        @endif
</body>
</html>