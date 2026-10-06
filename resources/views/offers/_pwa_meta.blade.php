{{-- PWA head for the PUBLIC offer pages (signing, verification, confirmation).

     Deliberately not layouts/_pwa.blade.php: that partial registers the service
     worker and shows the install/update banners, which are for staff using the
     CRM. A client opening their offer letter should not be offered "Install
     Keystone", and should never see an update banner mid-signature.

     What they do get is the part that decides whether the page renders like an
     app on a phone: a theme colour, a manifest, and home-screen icons. Without
     these Android draws the address bar against no theme, and iOS offers to add
     a bookmark with no icon at all.

     Note the service worker is deliberately NOT registered here. Its navigation
     strategy caches responses, and a signed offer letter is a client's
     confidential document — it must not be written to a cache that survives on a
     shared handset. See the bypass in public/service-worker.js. --}}
<link rel="manifest" href="{{ asset('manifest.json') }}">
<meta name="theme-color" content="#17212f">
<meta name="application-name" content="{{ config('app.name') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<link rel="apple-touch-icon" href="{{ asset('img/icon-192.png') }}">
<link rel="icon" href="{{ asset('img/icon-192.png') }}">