/**
 * Keystone Service Worker
 * Provides offline support and caching for the PWA experience.
 *
 * Cache strategies:
 *   - App shell (CSS, JS, fonts): cache-first
 *   - API/AJAX calls: network-first with timeout
 *   - HTML pages: network-first, offline fallback with app shell
 *   - Static assets (images): cache-first
 *
 * Bump CACHE_VERSION to invalidate all caches on deploy.
 */

var APP_ASSET_VERSION = '1.2.2';
var CACHE_VERSION = 'v' + APP_ASSET_VERSION;
var STATIC_CACHE = 'keystone-static-' + CACHE_VERSION;
var DYNAMIC_CACHE = 'keystone-dynamic-' + CACHE_VERSION;
var APP_SHELL_CACHE = 'keystone-app-shell-' + CACHE_VERSION;

// Derive base path from service worker location (supports subdirectory installs)
var BASE_PATH = self.location.pathname.replace(/\/service-worker\.js$/, '') + '/';

// App shell resources to cache on install (HTML, CSS, JS)
// Versioned URLs MUST match the ?v= querystrings emitted in layouts/app.blade.php
// so cache-first serves the fresh files after a deploy.
var APP_SHELL = [
    BASE_PATH + 'offline',
    BASE_PATH + 'css/mobile.css?v=' + APP_ASSET_VERSION,
    BASE_PATH + 'css/mobile-app.css?v=' + APP_ASSET_VERSION,
    BASE_PATH + 'js/mobile-app.js?v=' + APP_ASSET_VERSION,
    'https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css',
    'https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler-vendors.min.css',
    'https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js',
];

// Patterns for static assets (cache-first)
var STATIC_PATTERNS = [
    /\.(?:css|js|woff2?|ttf|eot|otf)(\?.*)?$/,
    /\/img\//,
    /\/images\//,
    /\/fonts\//,
    /cdn\.jsdelivr\.net/,
    /cdnjs\.cloudflare\.com/,
];

// Patterns for API/AJAX calls (network-first)
var API_PATTERNS = [
    /\/api\//,
    /\/ai\//,
    /\/search/,
    /\/notifications\/recent/,
    /\/calendar\/events/,
    /\/dashboard-data/,
];

/**
 * Install event: cache the app shell.
 */
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(APP_SHELL_CACHE).then(function(cache) {
            return cache.addAll(APP_SHELL).catch(function(error) {
                // Non-critical: some CDN resources may fail on first install
                console.log('Service worker: some app shell resources failed to cache', error);
            });
        }).then(function() {
            return caches.open(STATIC_CACHE).then(function(cache) {
                return cache.addAll(STATIC_PATTERNS.map(function(pattern) {
                    // This is a simplified approach - in practice you'd need to know the actual URLs
                    return null;
                }).filter(function(item) { return item; }));
            });
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

/**
 * Activate event: clean up old caches.
 */
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(cacheNames) {
            return Promise.all(
                cacheNames.filter(function(name) {
                    return (name.startsWith('insulacrm-') || name.startsWith('keystone-')) && name !== STATIC_CACHE && name !== DYNAMIC_CACHE && name !== APP_SHELL_CACHE;
                }).map(function(name) {
                    return caches.delete(name);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

/**
 * Fetch event: apply appropriate caching strategy.
 */
self.addEventListener('fetch', function(event) {
    var request = event.request;

    // Only handle GET requests
    if (request.method !== 'GET') return;

    // Skip chrome-extension and non-http(s) requests
    if (!request.url.startsWith('http')) return;

    // Determine strategy based on URL patterns
    if (isStaticAsset(request.url)) {
        // Cache-first for static assets
        event.respondWith(cacheFirst(request));
    } else if (isApiRequest(request.url)) {
        // Network-first for API calls (no offline fallback for JSON)
        event.respondWith(networkFirst(request));
    } else if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
        // Network-first for HTML pages with offline fallback and app shell
        event.respondWith(networkFirstWithAppShellFallback(request));
    }
});

/**
 * Cache-first strategy: try cache, fall back to network.
 */
function cacheFirst(request) {
    return caches.match(request).then(function(cached) {
        if (cached) return cached;

        return fetch(request).then(function(response) {
            if (response && response.status === 200) {
                var responseClone = response.clone();
                caches.open(STATIC_CACHE).then(function(cache) {
                    cache.put(request, responseClone);
                });
            }
            return response;
        }).catch(function() {
            // Static asset unavailable offline - return nothing
            return new Response('', { status: 408, statusText: 'Offline' });
        });
    });
}

/**
 * Network-first strategy: try network, fall back to cache.
 */
function networkFirst(request) {
    return fetchWithTimeout(request, 5000).then(function(response) {
        if (response && response.status === 200) {
            var responseClone = response.clone();
            caches.open(DYNAMIC_CACHE).then(function(cache) {
                cache.put(request, responseClone);
            });
        }
        return response;
    }).catch(function() {
        return caches.match(request);
    });
}

/**
 * Network-first with offline page fallback and app shell for HTML navigation.
 * This makes the PWA feel like a mobile app by caching the app shell and
 * serving it when offline, while fetching new content when online.
 */
function networkFirstWithAppShellFallback(request) {
    return fetchWithTimeout(request, 8000).then(function(response) {
        if (response && response.status === 200) {
            var responseClone = response.clone();
            caches.open(DYNAMIC_CACHE).then(function(cache) {
                cache.put(request, responseClone);
            });
        }
        return response;
    }).catch(function() {
        // Fall back to app shell when offline
        return caches.match(APP_SHELL_CACHE).then(function(cache) {
            if (cache) {
                return cache.match(BASE_PATH + 'offline');
            }
            // Last resort: return the app shell HTML
            return caches.match(BASE_PATH + 'offline').then(function(fallback) {
                if (fallback) return fallback;
                // Return minimal app shell
                return new Response('<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#0054a6"><link rel="manifest" href="/manifest.json"></head><body class="p-4"><h1>Keystone</h1><p>Working offline</p></body></html>', {
                    status: 200,
                    headers: { 'Content-Type': 'text/html', 'Content-Encoding': 'gzip' }
                });
            });
        });
    });
}

/**
 * Fetch with a timeout to avoid hanging on slow connections.
 */
function fetchWithTimeout(request, timeout) {
    return new Promise(function(resolve, reject) {
        var timer = setTimeout(function() {
            reject(new Error('Request timeout'));
        }, timeout);

        fetch(request).then(function(response) {
            clearTimeout(timer);
            resolve(response);
        }).catch(function(error) {
            clearTimeout(timer);
            reject(error);
        });
    });
}

/**
 * Check if a URL matches static asset patterns.
 */
function isStaticAsset(url) {
    return STATIC_PATTERNS.some(function(pattern) {
        return pattern.test(url);
    });
}

/**
 * Check if a URL matches API request patterns.
 */
function isApiRequest(url) {
    return API_PATTERNS.some(function(pattern) {
        return pattern.test(url);
    });
}