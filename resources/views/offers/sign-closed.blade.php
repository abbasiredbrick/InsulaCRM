<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @include('offers._pwa_meta')
    <title>{{ __('Signing Link Closed') }}</title>
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
        <div class="card-body p-4">
            <div class="d-flex align-items-start mb-3">
                <div class="seal rounded-circle bg-secondary-subtle text-secondary d-flex align-items-center justify-content-center fs-3 fw-bold me-3">&times;</div>
                <div>
                    <h5 class="mb-1">{{ __('This signing link is closed') }}</h5>
                    <p class="text-secondary mb-0 small">
                        @if($reason === 'expired')
                            {{ __('It ran out of time. Ask the agency to send you a new link — it only takes them a moment.') }}
                        @elseif($reason === 'revoked')
                            {{ __('The agency has withdrawn this request. This may be because the letter was changed or withdrawn.') }}
                        @else
                            {{ __('We cannot find an offer letter for this link.') }}
                        @endif
                    </p>
                </div>
            </div>

            <p class="small text-secondary mb-0">{{ __('Nothing has been signed, and you have not agreed to anything. If you were expecting a letter, please reply to the email you received and we will help.') }}</p>
        </div>
    </div>
</div>
</body>
</html>