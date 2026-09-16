{{-- PWA Meta Tags & Service Worker Registration --}}
<link rel="manifest" href="{{ asset('manifest.json') . '?v=2' }}">
<meta name="theme-color" content="#0054a6">
<meta name="application-name" content="{{ config('app.name') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<link rel="apple-touch-icon" href="{{ asset('img/icon-192.png') }}">
<link rel="apple-touch-icon" sizes="192x192" href="{{ asset('img/icon-192.png') }}">
<link rel="apple-touch-icon" sizes="512x512" href="{{ asset('img/icon-512.png') }}">
<meta name="mobile-web-app-capable" content="yes">

<script>
// Deployed app version (mirrors VERSION + service-worker.js APP_ASSET_VERSION)
window.CRM_APP_VERSION = '{{ config('app.version') }}';

// Manual update check: proactively calls registration.update() (network-refreshes
// the service worker script, bypassing caches) and races install/activate of a
// changed worker against a timeout so we can report "up to date".
window.AppUpdater = {
    check: function(done) {
        if (!('serviceWorker' in navigator)) {
            if (done) done({ state: 'unsupported' });
            return;
        }
        navigator.serviceWorker.getRegistration().then(function(reg) {
            if (!reg || !reg.active) {
                if (done) done({ state: 'unsupported' });
                return;
            }
            var settled = false;
            var timeout = null;
            var finish = function(result) {
                if (settled) return;
                settled = true;
                if (timeout) clearTimeout(timeout);
                if (done) done(result);
            };
            // If no changed worker installs within 4s we assume we are current.
            timeout = setTimeout(function() {
                finish({ state: 'up-to-date', version: window.CRM_APP_VERSION });
            }, 4000);
            reg.addEventListener('updatefound', function() {
                var newWorker = reg.installing;
                if (!newWorker) return;
                newWorker.addEventListener('statechange', function() {
                    if (newWorker.state === 'activated') {
                        finish({ state: 'available' });
                    }
                });
            });
            reg.update().catch(function() {
                finish({ state: 'offline' });
            });
        }).catch(function() {
            if (done) done({ state: 'offline' });
        });
    }
};

function checkForAppUpdate() {
    var statusEl = document.getElementById('mobile-update-status');
    if (statusEl) {
        statusEl.hidden = false;
        statusEl.textContent = 'Checking for updates\u2026';
    }
    if (!window.AppUpdater) {
        if (statusEl) statusEl.textContent = 'Updates are not available in this browser.';
        return;
    }
    window.AppUpdater.check(function(result) {
        if (statusEl) {
            if (result.state === 'available') {
                statusEl.textContent = 'Update available \u2014 reload to apply.';
            } else if (result.state === 'up-to-date') {
                statusEl.textContent = 'You are up to date on v' + result.version + '.';
            } else if (result.state === 'offline') {
                statusEl.textContent = 'Offline \u2014 could not check for updates.';
            } else {
                statusEl.textContent = 'PWA updates are not supported in this browser.';
            }
        }
        // Offer the one-tap refresh banner whenever a new version landed.
        if (result.state === 'available') {
            showUpdateBanner();
        }
    });
}

// Service Worker Registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('{{ asset("service-worker.js") }}', {
            scope: '{{ url("/") }}/',
            updateViaCache: 'none'
        })
            .then(function(registration) {
                // Check for updates periodically
                registration.addEventListener('updatefound', function() {
                    var newWorker = registration.installing;
                    if (newWorker) {
                        newWorker.addEventListener('statechange', function() {
                            if (newWorker.state === 'activated' && navigator.serviceWorker.controller) {
                                // New version available - show a subtle update banner
                                showUpdateBanner();
                            }
                        });
                    }
                });
            })
            .catch(function(error) {
                // Service worker registration is non-critical
                console.log('SW registration skipped:', error.message);
            });
    });
}

// PWA Install Prompt Handler
var deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', function(e) {
    // Prevent the default mini-infobar
    e.preventDefault();
    deferredInstallPrompt = e;

    // Check if user has previously dismissed the prompt
    if (localStorage.getItem('pwa-install-dismissed')) return;

    showInstallBanner();
});

function showInstallBanner() {
    // Don't show if already installed or already showing
    if (window.matchMedia('(display-mode: standalone)').matches) return;
    if (document.getElementById('pwa-install-banner')) return;

    var banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.style.cssText = 'position:fixed;bottom:1rem;left:50%;transform:translateX(-50%);z-index:9999;' +
        'background:#0054a6;color:#fff;padding:0.75rem 1.25rem;border-radius:0.5rem;' +
        'box-shadow:0 4px 16px rgba(0,0,0,0.15);display:flex;align-items:center;gap:1rem;' +
        'font-size:0.875rem;max-width:480px;width:calc(100% - 2rem);animation:slideUp 0.3s ease;';

    banner.innerHTML = '<div style="flex:1;">' +
        '<strong>Install ' + '{{ config('app.name') }}' + '</strong><br>' +
        '<span style="opacity:0.85;font-size:0.8rem;">Add to your home screen for quick access</span>' +
        '</div>' +
        '<button id="pwa-install-btn" style="background:#fff;color:#0054a6;border:none;padding:0.375rem 1rem;' +
        'border-radius:0.25rem;font-weight:600;font-size:0.8rem;cursor:pointer;white-space:nowrap;">Install</button>' +
        '<button id="pwa-dismiss-btn" style="background:transparent;border:none;color:#fff;opacity:0.7;' +
        'cursor:pointer;padding:0.25rem;font-size:1.1rem;line-height:1;" title="Dismiss">&times;</button>';

    document.body.appendChild(banner);

    document.getElementById('pwa-install-btn').addEventListener('click', function() {
        if (deferredInstallPrompt) {
            deferredInstallPrompt.prompt();
            deferredInstallPrompt.userChoice.then(function(result) {
                deferredInstallPrompt = null;
                banner.remove();
            });
        }
    });

    document.getElementById('pwa-dismiss-btn').addEventListener('click', function() {
        banner.remove();
        localStorage.setItem('pwa-install-dismissed', '1');
    });
}

function showUpdateBanner() {
    if (document.getElementById('pwa-update-banner')) return;

    var banner = document.createElement('div');
    banner.id = 'pwa-update-banner';
    banner.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:9999;' +
        'background:#1e293b;color:#fff;padding:0.75rem 1.25rem;border-radius:0.5rem;' +
        'box-shadow:0 4px 16px rgba(0,0,0,0.15);display:flex;align-items:center;gap:1rem;' +
        'font-size:0.875rem;animation:slideUp 0.3s ease;';

    banner.innerHTML = '<span>A new version is available.</span>' +
        '<button onclick="window.location.reload()" style="background:#0054a6;color:#fff;border:none;' +
        'padding:0.25rem 0.75rem;border-radius:0.25rem;font-size:0.8rem;cursor:pointer;">Refresh</button>' +
        '<button onclick="this.parentElement.remove()" style="background:transparent;border:none;color:#fff;' +
        'opacity:0.7;cursor:pointer;">&times;</button>';

    document.body.appendChild(banner);
}
</script>
<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateX(-50%) translateY(20px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
}
#pwa-update-banner {
    animation: slideUpRight 0.3s ease !important;
}
@keyframes slideUpRight {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
