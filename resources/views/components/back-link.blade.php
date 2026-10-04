@props(['fallback' => null, 'force' => false])

@php
    $fallbackUrl = is_string($fallback) && $fallback !== '' ? $fallback : route('dashboard');
@endphp

<button
    type="button"
    class="btn btn-ghost-secondary btn-icon d-print-none me-1 js-back-link"
    data-fallback="{{ $fallbackUrl }}"
    data-force="{{ $force ? '1' : '0' }}"
    aria-label="{{ __('Go back') }}"
    title="{{ __('Go back') }}"
>
    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M15 6l-6 6l6 6"/>
    </svg>
</button>

@once
<script>
    (function () {
        if (window.__backLinkBooted) { return; }
        window.__backLinkBooted = true;

        var FORCED = '1';

        document.addEventListener('click', function (e) {
            var button = e.target.closest ? e.target.closest('.js-back-link') : null;
            if (!button) { return; }

            e.preventDefault();

            if (button.dataset.force === FORCED || window.history.length <= 1) {
                window.location.href = button.dataset.fallback || '/';
                return;
            }

            window.history.back();
        });
    })();
</script>
@endonce
