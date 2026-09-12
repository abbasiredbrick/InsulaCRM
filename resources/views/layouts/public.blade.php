<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta http-equiv="X-UA-Compatible" content="ie=edge"/>
    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="{{ config('app.name') }} — the CRM built for property brokers.">
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css">
    <style>
        .landing-hero { padding: 6rem 0 4rem; }
        .landing-badge {
            display: inline-block; padding: .35rem .9rem; border-radius: 100px;
            background: rgba(94, 114, 228, .12); color: var(--tblr-primary);
            font-weight: 600; font-size: .85rem; letter-spacing: .04em; margin-bottom: 1rem;
        }
        .landing-title { font-size: clamp(2rem, 5vw, 3.25rem); font-weight: 800; line-height: 1.08; }
        .text-gradient {
            background: linear-gradient(120deg, #5e72e4, #11cdef);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .landing-subtitle { font-size: 1.15rem; color: var(--tblr-secondary); max-width: 34rem; }
        .landing-logo { max-height: 120px; max-width: 100%; }
        .landing-section { padding: 4rem 0; }
        .landing-h2 { font-weight: 700; font-size: 2rem; }
        .landing-card-title { font-size: 1.1rem; font-weight: 600; }
        .landing-cta { background: #17212f; border-radius: 1rem; margin: 0 1rem 4rem; padding: 4rem 1rem; }
        .landing-cta .text-secondary { color: #aab4c2 !important; }
    </style>
    @stack('styles')
</head>
<body class="d-flex flex-column">
    <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js" defer></script>
    @yield('content')
    @stack('scripts')
</body>
</html>