<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @include('offers._pwa_meta')
    <title>{{ __('Offer Letter Signed') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f1f3f5; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .card { border:0; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .seal { width:64px; height:64px; }
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
<div class="container" style="max-width:520px;">
    <div class="card">
        <div class="card-body p-4 text-center">
            <div class="seal rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center fs-3 fw-bold mx-auto mb-3">&check;</div>
            <h5 class="mb-2">{{ __('Thank you — your signature is recorded') }}</h5>
            <p class="text-secondary small mb-3">
                {{ __('Your signed offer letter :no has been received by :company.', [
                    'no' => $offer->offer_no,
                    'company' => $offer->tenant->name,
                ]) }}
            </p>

            @if($signedAt)
                <p class="small mb-1">{{ __('Signed') }} {{ $signedAt->format('F j, Y \a\t H:i') }}</p>
                @if($signerName)
                    <p class="small text-secondary mb-0">{{ __('Signed by') }} {{ $signerName }}</p>
                @endif
            @endif

            <hr class="my-3">
            <p class="small text-secondary mb-0">
                {{ __('You will receive a copy by email shortly. If you need anything changed, please reply to that email.') }}
            </p>
        </div>
    </div>
</div>
</body>
</html>