<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="manifest" href="{{ url('/s/'.$tenant->slug.'/manifest.webmanifest') }}">
    <meta name="theme-color" content="#17212f">
    <meta name="application-name" content="{{ $tenant->name }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ $tenant->name }}">
    <link rel="apple-touch-icon" href="{{ asset('img/icon-192.png') }}">
    <title>@yield('title', $tenant->name)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler-vendors.min.css">
    <style>
        @font-face {
            font-family: "Dirham";
            src: url("{{ asset('fonts/dirham.woff2') }}?v=4") format("woff2"),
                 url("{{ asset('fonts/dirham.woff') }}?v=4") format("woff");
            font-weight: 400;
            font-style: normal;
            font-display: swap;
            unicode-range: U+20C3;
        }
        @font-face {
            font-family: "Dirham";
            src: url("{{ asset('fonts/dirham.woff2') }}?v=4") format("woff2"),
                 url("{{ asset('fonts/dirham.woff') }}?v=4") format("woff");
            font-weight: 500 700;
            font-style: normal;
            font-display: swap;
            unicode-range: U+20C3;
        }
        @font-face {
            font-family: "Dirham";
            src: url("{{ asset('fonts/dirham.woff2') }}?v=4") format("woff2"),
                 url("{{ asset('fonts/dirham.woff') }}?v=4") format("woff");
            font-weight: 800 900;
            font-style: normal;
            font-display: swap;
            unicode-range: U+20C3;
        }
        body {
            background: var(--tblr-bg-surface-secondary, #f0f2f7);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: var(--tblr-font-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif), "Dirham";
        }
        .share-brandbar {
            background: #17212f;
            box-shadow: inset 0 -1px 0 rgba(255, 255, 255, .06);
        }
        .share-brandbar .brand-tile {
            display: inline-block;
            background: #fff;
            border-radius: 8px;
            padding: 6px 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .4);
        }
        .share-brandbar .brand-tile img {
            display: block;
            max-height: 60px;
            max-width: 260px;
        }
        .share-subnav {
            background: #fff;
            border-bottom: 1px solid var(--tblr-border-color, #dce1e7);
        }
        .share-content { flex: 1; }
        .share-footer {
            background: #17212f;
            color: #aab4c2;
        }
        .unit-thumb-sm {
            width: 68px;
            height: 52px;
            background: var(--tblr-bg-surface-secondary, #f0f2f7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 1.25rem;
            overflow: hidden;
            border-radius: .5rem;
        }
        .unit-thumb-sm img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .share-unit-row {
            cursor: pointer;
            transition: background-color .12s ease;
        }
        .share-unit-row:hover {
            background-color: var(--tblr-bg-surface-secondary, #f0f2f7);
        }
        .unit-price {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--tblr-primary, #206bc4);
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="share-brandbar">
        <div class="container-xl d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3 py-3 text-center text-sm-start">
            <a href="{{ route('share.inventory', $tenant->slug) }}" class="brand-tile" aria-label="{{ config('app.name') }}">
                <img src="{{ asset('images/logo-white.png') . '?v=keystone' }}" alt="{{ config('app.name') }}">
            </a>
            <div class="text-white">
                <div class="h4 fw-bold mb-0">{{ $tenant->name }}</div>
                <div class="opacity-75">{{ __('Availability Portal') }}</div>
            </div>
        </div>
    </div>

    <main class="share-content container-xl py-4">
        @yield('content')
    </main>

    <footer class="share-footer py-4">
        <div class="container-xl text-center">
            <p class="mb-0">&copy; {{ date('Y') }} {{ $tenant->name }}. {{ __('All rights reserved.') }}</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
    {{-- Register the app service worker so the portal works like an installed
         app: visited share pages are served from cache when offline, and the
         tenant-scoped manifest makes the browser offer an install that opens
         straight at /s/{slug}. The worker sits at the origin root, so scope /
         is valid from here. --}}
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('{{ asset('service-worker.js') }}', {
                    scope: '{{ url('/') }}/',
                    updateViaCache: 'none'
                }).catch(function (error) {
                    console.log('SW registration skipped:', error.message);
                });
            });
        }
    </script>
    @stack('scripts')
</body>
</html>