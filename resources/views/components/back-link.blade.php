@props(['fallback' => null, 'force' => false])

@php
    $fallbackUrl = $fallback;
    if (empty($fallbackUrl)) {
        $fallbackUrl = route('dashboard');
    }
    // Ensure it's a string URL
    if (!is_string($fallbackUrl)) {
        $fallbackUrl = route('dashboard');
    }
@endphp

<button
    type="button"
    x-data="{
        canGoBack: false,
        force: {{ $force ? 'true' : 'false' }},
        fallbackUrl: @js($fallbackUrl),
        init() {
            this.update();
            window.addEventListener('pageshow', () => this.update());
            window.addEventListener('popstate', () => this.update());
        },
        update() {
            try {
                this.canGoBack = this.force || window.history.length > 1;
            } catch (e) {
                this.canGoBack = this.force;
            }
        },
        goBack() {
            if (!this.force && window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = this.fallbackUrl;
            }
        }
    }"
    x-show="canGoBack"
    x-on:click="goBack()"
    class="btn btn-ghost-secondary btn-icon d-print-none me-1"
    aria-label="{{ __('Go back') }}"
    title="{{ __('Go back') }}"
>
    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M15 6l-6 6l6 6"/>
    </svg>
</button>