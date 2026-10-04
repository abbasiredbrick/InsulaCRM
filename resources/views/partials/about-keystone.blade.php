@php
    $isRE = ($businessMode ?? 'wholesale') === 'realestate';
@endphp
<div class="text-center mb-3">
    <span class="avatar avatar-lg {{ $isRE ? 'bg-teal' : 'bg-blue' }} text-white" style="width: 48px; height: 48px; font-size: 1rem;">
        @if($isRE)
        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M5 21v-14l8 -4v18"/><path d="M19 21v-10l-6 -4"/><path d="M9 9l0 .01"/><path d="M9 12l0 .01"/><path d="M9 15l0 .01"/><path d="M9 18l0 .01"/></svg>
        @else
        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M9 8l1 0"/><path d="M9 12l1 0"/><path d="M9 16l1 0"/><path d="M14 8l1 0"/><path d="M14 12l1 0"/><path d="M14 16l1 0"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/></svg>
        @endif
    </span>
    <h3 class="mt-2 mb-0">{{ __('Keystone') }}</h3>
    <div class="text-muted small">v{{ \App\Support\AppVersion::current() }}</div>
</div>
<div class="mb-3">
    <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom: 1px solid var(--tblr-border-color, #e6e7e9);">
        <span class="text-muted">{{ __('Application Mode') }}</span>
        <span class="fw-semibold">{{ $isRE ? __('Real Estate Agent') : __('Wholesale') }}</span>
    </div>
    <div class="d-flex align-items-center justify-content-between py-2">
        <span class="text-muted">{{ __('Version') }}</span>
        <span class="fw-semibold">{{ \App\Support\AppVersion::current() }}</span>
    </div>
</div>
<div class="text-center">
    <p class="text-muted small mb-2">{{ __('If pages or data look out of date, reset the app to load the latest version.') }}</p>
    <button type="button" class="btn btn-primary w-100" onclick="window.hardResetApp();">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: text-bottom;"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/><path d="M5 12l14 0"/></svg>
        {{ __('Reset to Latest Version') }}
    </button>
    <div class="keystone-reset-status small text-muted mt-2"></div>
</div>
