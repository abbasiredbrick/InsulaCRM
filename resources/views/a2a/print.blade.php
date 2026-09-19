<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $contract->contract_number }} — {{ $tenant->name }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            font-size: 13px;
            line-height: 1.6;
            color: #1e293b;
            background: #f8fafc;
        }

        .print-wrapper { max-width: 820px; margin: 0 auto; padding: 24px; }
        .print-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-toolbar .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 20px; font-size: 14px; font-weight: 600; color: #fff; background: #0054a6; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; }
        .print-toolbar .btn-outline { color: #475569; background: transparent; border: 1px solid #cbd5e1; }
        .print-toolbar .spacer { flex: 1; }

        .contract-page { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 48px 44px; }

        .contract-header { text-align: center; border-bottom: 2px solid #0054a6; padding-bottom: 16px; margin-bottom: 24px; }
        .contract-header .brand-logo { max-height: 64px; max-width: 220px; margin-bottom: 8px; }
        .contract-header .brand-company { font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .contract-header h1 { font-size: 17px; font-weight: 700; color: #0054a6; text-transform: uppercase; letter-spacing: 0.5px; }
        .contract-header .num { font-size: 13px; font-weight: 600; color: #1e293b; margin-top: 4px; }

        p { margin-bottom: 12px; text-align: justify; }
        ul { margin: 0 0 12px 20px; }

        h2 { font-size: 13px; font-weight: 700; color: #111827; text-transform: uppercase; letter-spacing: 0.3px; margin: 22px 0 8px; }
        h2:first-of-type { margin-top: 20px; }

        .party-block { display: flex; gap: 24px; }
        .party { flex: 1; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; }
        .party .label { font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .party .name { font-weight: 700; }
        .party .detail { color: #475569; }

        .whereas { font-style: italic; color: #334155; }

        .signatures { display: flex; gap: 40px; margin-top: 44px; }
        .signature { flex: 1; }
        .signature .line { border-bottom: 1px solid #94a3b8; height: 34px; margin-bottom: 6px; }
        .signature .who { font-size: 11px; color: #475569; }
        .signature .who strong { display: block; color: #1e293b; font-size: 12px; }

        .foot { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8; text-align: center; }

        @media print {
            body { background: #fff; font-size: 11px; }
            .print-toolbar { display: none !important; }
            .print-wrapper { max-width: none; padding: 0; }
            .contract-page { border: none; border-radius: 0; padding: 0; box-shadow: none; }
            @page { size: A4; margin: 15mm 14mm; }
        }
    </style>
</head>
<body>
    @php
        $branding = $tenant->custom_options['a2a_branding'] ?? [];

        $docCompanyName = ($branding['company_name'] ?? '') ?: $tenant->name;
        $docLogo = $branding['logo_path'] ?? $tenant->logo_path;
        $docLogoUrl = $docLogo ? \Illuminate\Support\Facades\Storage::disk('public')->url($docLogo) : null;

        $brokerAddress = $branding['address'] ?? '';
        $brokerPhone = $branding['phone'] ?? '';
        $brokerWebsite = $branding['website'] ?? '';
        $brokerEmail = $branding['email'] ?? '';

        $footerParts = array_values(array_filter([$brokerAddress, $brokerPhone ? 'Tel: '.$brokerPhone : '', $brokerWebsite, $brokerEmail]));
        $footerLine = implode(' &nbsp;|&nbsp; ', $footerParts);

        $agent1 = $contract->agent?->name ?: $tenant->name;
        $agent2 = $contract->counterparty_name;
        $company2 = $contract->counterparty_company;
        $address2 = $contract->counterparty_address;
        $propertyName = $contract->property?->display_name
            ?? $contract->lead?->property?->display_name
            ?? ($contract->property?->address)
            ?? __('the subject property');
        $txLabel = strtolower((string) $contract->transaction_label);
        $txWord = match ($contract->transaction_type) {
            'sale' => __('sale'),
            default => __('lease'),
        };
        $signDate = $contract->signed_at?->format('d/m/Y') ?? now()->format('d/m/Y');
    @endphp

    <div class="print-wrapper">
        <div class="print-toolbar">
            <button class="btn" onclick="window.print()">Print / Save as PDF</button>
            <a href="{{ route('a2a.show', $contract) }}" class="btn btn-outline">Back to contract</a>
            <span class="spacer"></span>
            <span style="color:#64748b; font-size:12px;">Use "Save as PDF" in the print dialog to export.</span>
        </div>

        <div class="contract-page">
            <div class="contract-header">
                @if($docLogoUrl)
                    <img src="{{ $docLogoUrl }}" alt="{{ $docCompanyName }}" class="brand-logo">
                @else
                    <div class="brand-company">{{ $docCompanyName }}</div>
                @endif
                <h1>{{ __('Agent to Agent Commission Sharing Agreement') }}</h1>
                <div class="num">{{ $contract->contract_number }}</div>
            </div>

            <p>
                This Agent-to-Agent Commission Sharing Agreement ("Agreement") is entered into on
                <strong>{{ $contract->signed_at?->format('d/m/Y') ?? now()->format('d/m/Y') }}</strong>, by and between:
            </p>

            <div class="party-block">
                <div class="party">
                    <div class="label">{{ __('Agent 1') }}</div>
                    <div class="name">{{ $agent1 }}</div>
                    <div class="detail">{{ $contract->agent?->role?->display_name ?? 'Licensed real estate agent' }}</div>
                    <div class="detail">{{ $docCompanyName }}</div>
                    <div class="detail">{{ $brokerAddress }}</div>
                </div>
                <div class="party">
                    <div class="label">{{ __('Agent 2') }}</div>
                    <div class="name">{{ $agent2 }}</div>
                    <div class="detail">{{ $company2 }}</div>
                    <div class="detail">{{ $address2 }}</div>
                </div>
            </div>

            <p class="whereas">
                WHEREAS, Agent 1 and Agent 2 are licensed real estate agents working under their respective
                brokers, and both parties have the knowledge and consent of their brokers to enter into this
                commission-sharing agreement;
            </p>
            <p class="whereas">
                WHEREAS, the parties desire to collaborate on the {{ $txWord }} of the property located at
                <strong>{{ $propertyName }}</strong> ("Property") and agree to share the commission earned from the transaction;
            </p>
            <p class="whereas">
                NOW, THEREFORE, in consideration of the mutual promises and covenants contained herein, the parties
                agree as follows:
            </p>

            <h2>1. {{ __('Property and Transaction') }}</h2>
            <p>
                The Property subject to this Agreement is located at <strong>{{ $propertyName }}</strong>.
                This Agreement pertains to the <strong>{{ $txWord }}</strong> of the Property.
            </p>

            <h2>2. {{ __('Roles and Responsibilities') }}</h2>
            <ul>
                <li>{{ $agent1 }} shall represent <strong>{{ $docCompanyName }}</strong> and shall be responsible for coordinating viewings and negotiating offers.</li>
                <li>{{ $agent2 }} shall act as the counterparty agent {{ $company2 ? 'of '.$company2 : '' }} and shall be responsible for sourcing the client, facilitating negotiations, and coordinating contracts.</li>
                <li>Both agents shall perform their duties in accordance with applicable UAE laws, regulations (including AADREC guidelines), and their respective brokerage agreements.</li>
            </ul>

            <h2>3. {{ __('Commission Sharing') }}</h2>
            <p>
                The commission for the transaction, as agreed with the client, shall be collected by the respective
                brokerage of each agent. Upon closing of the transaction and receipt of the commission by the
                brokers, the commission shall be shared in accordance with this Agreement.
            </p>
            <p>
                For transactions under this Agreement, {{ $agent2 }} shall receive
                <strong>{{ rtrim(rtrim((string) $contract->share_pct, '0'), '.') }}%</strong> of the gross commission earned
                on the transaction. This share shall be funded as follows:
                <strong>{{ $contract->funding_label }}</strong>.
            </p>
            <p>
                Each agent's entitlement is subject to their individual agreements with their respective brokers
                and any applicable brokerage splits or fees.
            </p>

            <h2>4. {{ __('Payment Terms') }}</h2>
            <ul>
                <li>The commission shall be paid through the respective brokers of Agent 1 and Agent 2, in accordance with their brokerage agreements.</li>
                <li>Neither agent shall accept direct compensation from the client or any third party, and all payments shall be processed through the brokers.</li>
                <li>Payment shall be made shortly after the transaction closes and after receipt of the commission by the brokers.</li>
            </ul>

            <h2>5. {{ __('Broker Consent') }}</h2>
            <ul>
                <li>Both Agent 1 and Agent 2 represent that they have obtained the knowledge and consent of their respective brokers to enter into this Agreement.</li>
                <li>A written acknowledgement from each broker may be attached to this Agreement to confirm their consent.</li>
            </ul>

            <h2>6. {{ __('Term and Termination') }}</h2>
            <ul>
                <li>This Agreement shall commence on the date of signing and continue until the transaction for the Property is closed or terminated.</li>
                <li>Either party may terminate this Agreement with written notice to the other party if the transaction is abandoned or if either party breaches their obligations under this Agreement.</li>
                <li>In the event of termination, any commissions earned prior to termination shall be split as outlined in Section 3, provided the transaction closes.</li>
            </ul>

            <h2>7. {{ __('Dispute Resolution') }}</h2>
            <p>
                Any disputes arising under this Agreement shall be resolved through mediation or arbitration in Abu Dhabi,
                United Arab Emirates, in accordance with the rules of the Abu Dhabi Real Estate Centre (ADREC). Each party
                shall bear their own costs unless otherwise determined by the mediator or arbitrator.
            </p>

            <h2>8. {{ __('Confidentiality') }}</h2>
            <p>
                Both parties agree to maintain the confidentiality of all client information and transaction details,
                except as required by law or with the client's consent, in compliance with UAE data protection regulations.
            </p>

            <h2>9. {{ __('Governing Law') }}</h2>
            <p>
                This Agreement shall be governed by and construed in accordance with the laws of the United Arab Emirates,
                as applicable in the Emirate of Abu Dhabi.
            </p>

            <h2>10. {{ __('Entire Agreement') }}</h2>
            <ul>
                <li>This Agreement constitutes the entire understanding between the parties and supersedes all prior oral or written agreements regarding the subject matter.</li>
                <li>Any amendments to this Agreement must be in writing and signed by both parties.</li>
            </ul>

            <p style="margin-top:20px;">
                IN WITNESS WHEREOF, the parties have executed this Agreement as of the date first written above.
            </p>

            <div class="signatures">
                <div class="signature">
                    <div class="line"></div>
                    <div class="who"><strong>{{ $agent1 }}</strong>{{ $docCompanyName }}</div>
                </div>
                <div class="signature">
                    <div class="line"></div>
                    <div class="who"><strong>{{ $agent2 }}</strong>{{ $company2 }}</div>
                </div>
            </div>
            <div class="signatures" style="margin-top:8px;">
                <div class="who">Date: {{ $signDate }}</div>
                <div class="who">Date: {{ $signDate }}</div>
            </div>
        </div>

        <div class="foot">
            @if($brokerAddress || $brokerPhone || $brokerWebsite || $brokerEmail)
                {{ $footerLine }}
            @endif
        </div>
    </div>
</body>
</html>