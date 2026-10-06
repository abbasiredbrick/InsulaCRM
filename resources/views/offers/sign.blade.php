<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    @include('offers._pwa_meta')
    <title>{{ __('Sign Your Offer Letter') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --ink:#0b3954; }
        body { background:#f1f3f5; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .card { border:0; border-radius:14px; box-shadow:0 2px 14px rgba(0,0,0,.08); }
        .pad-wrap { position:relative; }
        /* touch-action:none stops the browser scrolling the page out from under a
           finger mid-stroke, which is what made drawing feel broken on phones. */
        #pad { width:100%; height:190px; touch-action:none; display:block; border:2px dashed #adb5bd; border-radius:10px; background:#fff; }
        #pad.signed { border-style:solid; border-color:#198754; }
        .hint { font-size:.8rem; color:#6c757d; }
        .letter-frame { border:1px solid #dee2e6; border-radius:10px; background:#fff; }
        .letter-frame iframe { width:100%; height:560px; border:0; border-radius:10px; }
        details > summary { cursor:pointer; }
        @media (min-width: 992px) { .sticky-sign { position:sticky; top:1rem; } }
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
<body class="py-4">
<div class="container" style="max-width:1080px;">

    <div class="d-flex align-items-center gap-2 mb-3">
        <h5 class="mb-0 fw-bold" style="color:var(--ink)">{{ $offer->tenant->name }}</h5>
        <span class="badge text-bg-light border">{{ $offer->offer_no }}</span>
    </div>

    @if($errors->any())
        <div class="alert alert-danger">
            @foreach($errors->all() as $message)<div>{{ $message }}</div>@endforeach
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7 order-lg-2">
            <details open class="mb-3">
                <summary class="fw-semibold mb-2">{{ __('1. Read your offer letter') }}</summary>
                <div class="letter-frame">
                    {{-- The exact letter being signed, rendered server-side in an
                         iframe. It is the same HTML that would print, so what is
                         agreed to here is what is on file. --}}
                    <iframe id="letterFrame" title="{{ __('Offer letter') }}" srcdoc="{{ $letterHtml }}"></iframe>
                </div>
            </details>
        </div>

        <div class="col-lg-5 order-lg-1">
            <div class="card sticky-sign">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3">{{ __('2. Sign') }}</h6>

                    <form method="POST" action="{{ $submitUrl }}"
                          enctype="multipart/form-data" id="signForm">
                        @csrf
                        <input type="hidden" name="signature_data" id="signatureField">

                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">{{ __('Sign with your finger') }}</label>
                                <button type="button" class="btn btn-sm btn-link p-0" id="clearPad">{{ __('Clear') }}</button>
                            </div>
                            <div class="pad-wrap">
                                <canvas id="pad"></canvas>
                            </div>
                            <div class="hint mt-1">{{ __('Draw your normal signature above.') }}</div>
                        </div>

                        <div class="form-check mt-3 mb-2">
                            <input class="form-check-input" type="checkbox" name="consent" value="1" id="consent">
                            <label class="form-check-label small" for="consent">
                                {{ __('I have read this offer letter and I agree to sign it.') }}
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">{{ __('Type your full name') }}</label>
                            <input type="text" name="signer_name" class="form-control" required
                                   value="{{ old('signer_name', $signerName) }}" autocomplete="name">
                        </div>

                        <details class="mb-3">
                            <summary class="small">{{ __('Already signed a printed copy? Upload it instead') }}</summary>
                            <div class="mt-2">
                                <input type="file" name="signed_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                                <div class="hint mt-1">{{ __('Upload the signed letter — a photo is fine.') }}</div>
                            </div>
                        </details>

                        <button type="submit" class="btn btn-success w-100" id="submitBtn">
                            {{ __('Submit') }}
                        </button>

                        <div class="hint mt-2 text-center">
                            {{ __('This link works until') }} {{ $expiresAt->format('F j, Y \a\t H:i') }}.
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const pad = document.getElementById('pad');
    const field = document.getElementById('signatureField');
    const consent = document.getElementById('consent');
    const form = document.getElementById('signForm');
    const fileInput = form.querySelector('input[name="signed_file"]');

    // Whether the canvas actually holds a stroke.
    //
    // Deliberately NOT read back from the hidden field: that field is only
    // populated at submit time, so asking it "did they sign?" answered no
    // every time — the drawn signature was thrown away on the way to the
    // server and the client was told to sign a letter they had just signed.
    let hasInk = false;
    let inkDataUrl = '';
    const hasDrawn = () => hasInk;

    // Draw at device resolution, not CSS pixels. Scaled to 1x, a signature drawn
    // on a modern phone is a blurry smear that nobody can be recognised by.
    function sizeCanvas() {
        const dpr = window.devicePixelRatio || 1;
        const rect = pad.getBoundingClientRect();
        pad.width = Math.round(rect.width * dpr);
        pad.height = Math.round(rect.height * dpr);
        const ctx = pad.getContext('2d');
        ctx.scale(dpr, dpr);
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111';
        return { ctx, rect };
    }

    let drawing = false;
    let last = null;
    let ctx = pad.getContext('2d');

    function point(ev) {
        const rect = pad.getBoundingClientRect();
        // touches[] is used because TouchEvent has no clientX of its own.
        const src = ev.touches && ev.touches.length ? ev.touches[0] : ev;
        return { x: src.clientX - rect.left, y: src.clientY - rect.top };
    }

    function start(ev) {
        // The two paths are exclusive, so switching mode clears the other.
        if (fileInput.files.length) {
            fileInput.value = '';
        }
        ev.preventDefault();
        drawing = true;
        last = point(ev);
    }

    function move(ev) {
        if (!drawing) return;
        ev.preventDefault();
        const p = point(ev);
        ctx.beginPath();
        ctx.moveTo(last.x, last.y);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        last = p;
        hasInk = true;
    }

    function end() {
        if (!drawing) return;
        drawing = false;
        if (hasInk) {
            pad.classList.add('signed');
            // Snapshot now, because resizing the canvas clears it and the rotate
            // handler can only restore from this.
            inkDataUrl = pad.toDataURL('image/png');
            // Drawing is signing: consent is implicit in the act, but the
            // checkbox is still required so a stray mark cannot be submitted
            // with no evidence the client looked at the letter.
        }
    }

    // Pointer events cover mouse, touch and stylus in one path. Fall back to
    // touch events only if the browser has no PointerEvent support.
    if (window.PointerEvent) {
        pad.addEventListener('pointerdown', start);
        pad.addEventListener('pointermove', move);
        pad.addEventListener('pointerup', end);
        pad.addEventListener('pointerleave', end);
        pad.addEventListener('pointercancel', end);
    } else {
        pad.addEventListener('touchstart', start, { passive: false });
        pad.addEventListener('touchmove', move, { passive: false });
        pad.addEventListener('touchend', end);
        pad.addEventListener('mousedown', start);
        pad.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
    }

    document.getElementById('clearPad').addEventListener('click', function () {
        ctx.clearRect(0, 0, pad.width, pad.height);
        hasInk = false;
        inkDataUrl = '';
        field.value = '';
        pad.classList.remove('signed');
    });

    fileInput.addEventListener('change', function () {
        if (fileInput.files.length) {
            // Uploading a signed copy supersedes anything drawn.
            ctx.clearRect(0, 0, pad.width, pad.height);
            hasInk = false;
            inkDataUrl = '';
            field.value = '';
            pad.classList.remove('signed');
        }
    });

    window.addEventListener('resize', function () {
        // Redraw from the stored capture so a rotate or a keyboard appearing
        // does not blank the signature the client already made.
        ctx = sizeCanvas().ctx;
        if (hasInk) {
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, pad.width, pad.height); };
            img.src = inkDataUrl;
            pad.classList.add('signed');
        }
    });

    sizeCanvas();

    form.addEventListener('submit', function (ev) {
        if (!hasDrawn() && !fileInput.files.length) {
            ev.preventDefault();
            alert('{{ __('Please sign the letter, or upload a signed copy.')|e('js') }}');
            return;
        }
        if (!consent.checked) {
            ev.preventDefault();
            consent.focus();
            alert('{{ __('Please confirm that you agree to sign this letter.')|e('js') }}');
            return;
        }
        if (hasDrawn()) {
            ev.preventDefault();
            // A drawn pad is not valid form data, so the PNG is posted as a data
            // URL. Submitting by hand keeps validation and error redirects on
            // the normal round trip instead of a silent fetch that would lose
            // the server-side messages.
            field.value = pad.toDataURL('image/png');
            form.submit();
            return;
        }
        document.getElementById('submitBtn').disabled = true;
    });
})();
</script>
</body>
</html>