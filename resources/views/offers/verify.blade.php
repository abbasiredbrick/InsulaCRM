<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @include('offers._pwa_meta')
    <title>{{ __('Offer Letter Verification') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f3f5; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .card { border: 0; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .seal { width: 64px; height: 64px; }
        .mono { font-variant-numeric: tabular-nums; }
        /* viewport-fit=cover lets the page run edge to edge, so the notch and
           the home indicator now sit over the content. On the signing page that
           covered the submit button — the one control that has to be tappable. */
        body {
            padding-left: max(0.75rem, env(safe-area-inset-left));
            padding-right: max(0.75rem, env(safe-area-inset-right));
            padding-bottom: calc(1.5rem + env(safe-area-inset-bottom));
        }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 560px;">
    <div class="card">
        <div class="card-body p-4">

            @if(! empty($statusNote))
                {{-- Reached only for a withdrawn/declined letter: the signature on the
                     URL is valid, so this really was issued by us. --}}
                <div class="d-flex align-items-start mb-3">
                    <div class="seal rounded-circle bg-danger-subtle text-danger d-flex align-items-center justify-content-center fs-3 fw-bold me-3">!</div>
                    <div>
                        <h5 class="mb-1">{{ __('Letter verified, but no longer valid') }}</h5>
                        <p class="text-secondary mb-0 small">{{ $statusNote }}</p>
                    </div>
                </div>
            @else
                <div class="d-flex align-items-start mb-3">
                    <div class="seal rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center fs-3 fw-bold me-3">&check;</div>
                    <div>
                        <h5 class="mb-1">{{ __('This is a genuine offer letter') }}</h5>
                        <p class="text-secondary mb-0 small">{{ __('Issued by :company and verifiable through Keystone.', ['company' => $summary['company']]) }}</p>
                    </div>
                </div>
            @endif

            <table class="table table-sm mb-0">
                <tbody>
                    <tr><th class="text-secondary fw-normal">{{ __('Offer No.') }}</th><td class="mono">{{ $summary['offer_no'] }}</td></tr>
                    <tr><th class="text-secondary fw-normal">{{ __('Date') }}</th><td class="mono">{{ $summary['issued_at'] }}</td></tr>
                    <tr>
                        <th class="text-secondary fw-normal">{{ __('Type') }}</th>
                        <td>{{ $summary['deal_type'] === 'sale' ? __('Sale') : __('Rent') }}</td>
                    </tr>
                    <tr>
                        <th class="text-secondary fw-normal">{{ $summary['deal_type'] === 'sale' ? __('Sales Amount') : __('Contract Value') }}</th>
                        <td class="mono">{{ number_format($summary['approved_amount'], 2) }} {{ $summary['currency'] }}</td>
                    </tr>
                    <tr><th class="text-secondary fw-normal">{{ __('Status') }}</th><td>{{ \App\Models\OfferLetter::STATUSES[$summary['status']] ?? ucfirst($summary['status']) }}</td></tr>
                </tbody>
            </table>

            <p class="text-secondary small mt-3 mb-0">
                {{ __('Keystone confirms this letter was issued by the company named above. Personal details, occupant identity and bank information are deliberately not shown here.') }}
            </p>
        </div>
    </div>
</div>
</body>
</html>