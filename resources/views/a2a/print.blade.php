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
            line-height: 1.55;
            color: #1e293b;
            background: #f8fafc;
        }

        .print-wrapper { max-width: 820px; margin: 0 auto; padding: 24px; }
        .print-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; padding: 12px 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-toolbar .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 20px; font-size: 14px; font-weight: 600; color: #fff; background: #0054a6; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; }
        .print-toolbar .btn-outline { color: #475569; background: transparent; border: 1px solid #cbd5e1; }
        .print-toolbar .spacer { flex: 1; }

        .contract-page { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 48px 44px; }

        .contract-header { display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 2px solid #0054a6; padding-bottom: 16px; margin-bottom: 28px; }
        .contract-header h1 { font-size: 20px; font-weight: 700; color: #0054a6; }
        .contract-header .meta { text-align: right; font-size: 12px; color: #64748b; }
        .contract-header .meta .num { font-size: 14px; font-weight: 600; color: #1e293b; }

        h2 { font-size: 15px; font-weight: 700; color: #0054a6; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin: 24px 0 12px; }
        h2:first-of-type { margin-top: 0; }

        .party-block { display: flex; gap: 24px; }
        .party { flex: 1; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .party .label { font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .party .name { font-weight: 700; }

        p { margin-bottom: 12px; text-align: justify; }
        ul { margin: 0 0 12px 20px; }

        .terms-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; white-space: pre-line; }

        .signatures { display: flex; gap: 40px; margin-top: 48px; }
        .signature { flex: 1; }
        .signature .line { border-bottom: 1px solid #94a3b8; height: 34px; margin-bottom: 6px; }
        .signature .who { font-size: 11px; color: #64748b; }

        .foot { margin-top: 32px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; font-size: 11px; color: #94a3b8; }

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
    <div class="print-wrapper">
        <div class="print-toolbar">
            <button class="btn" onclick="window.print()">Print / Save as PDF</button>
            <a href="{{ route('a2a.show', $contract) }}" class="btn btn-outline">Back to contract</a>
            <span class="spacer"></span>
            <span style="color:#64748b; font-size:12px;">Use "Save as PDF" in the print dialog to export.</span>
        </div>

        <div class="contract-page">
            <div class="contract-header">
                <div>
                    <h1>{{ $tenant->name }}</h1>
                    <div style="font-size:12px; color:#475569;">{{ __('Real Estate Commission Sharing Agreement') }}</div>
                </div>
                <div class="meta">
                    <div class="num">{{ $contract->contract_number }}</div>
                    <div>{{ __('Date') }}: {{ $contract->created_at?->format('M d, Y') }}</div>
                </div>
            </div>

            <p>
                This Commission Sharing Agreement ("Agreement") is entered into by and between
                <strong>{{ $tenant->name }}</strong> (the "Company") and
                <strong>{{ $contract->counterparty_name }}</strong>
                @if($contract->counterparty_company)({{ $contract->counterparty_company }})@endif
                (the "Agent").
            </p>

            <h2>{{ __('1. Scope of Agreement') }}</h2>
            <p>
                This Agreement governs the sharing of commission earned on client transactions to which the Agent
                is introduced or provides services, pursuant to the terms set out below.
            </p>

            <h2>{{ __('2. Commission Share') }}</h2>
            <p>
                The Agent shall receive <strong>{{ rtrim(rtrim((string) $contract->share_pct, '0'), '.') }}%</strong> of the gross
                commission on each such transaction. This share shall be funded as follows:
            </p>
            <div class="terms-box">{{ $contract->funding_label }}.</div>

            <h2>{{ __('3. Terms & Conditions') }}</h2>
            @if($contract->terms)
            <div class="terms-box">{{ $contract->terms }}</div>
            @else
            <p>
                The Agent is entitled to the share described in Section 2 upon successful closing of the transaction
                and receipt of commission by the Company. The share is payable within a reasonable period after such
                receipt. Cancelled or refunded transactions may result in adjustment of the paid share.
            </p>
            @endif

            <h2>{{ __('4. Signatures') }}</h2>
            <div class="signatures">
                <div class="signature">
                    <div class="line"></div>
                    <div class="who">{{ __('For') }} {{ $tenant->name }}</div>
                </div>
                <div class="signature">
                    <div class="line"></div>
                    <div class="who">{{ $contract->counterparty_name }}</div>
                </div>
            </div>
        </div>

        <div class="foot">
            <span>{{ $tenant->name }} — {{ $contract->contract_number }}</span>
            <span>{{ __('Generated') }}: {{ now()->format('M d, Y \a\t g:i A') }}</span>
        </div>
    </div>
</body>
</html>