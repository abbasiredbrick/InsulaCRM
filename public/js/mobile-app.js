/**
 * Keystone Native Mobile App & PWA Controller
 * Handles native bottom sheets, drawers, tactile interactions, live search, and iOS install guidance.
 */

(function(window, document) {
    'use strict';

    var backdrop = null;
    var actionSheet = null;
    var moreDrawer = null;
    var searchSheet = null;
    var searchInput = null;
    var searchClearBtn = null;
    var searchList = null;
    var searchHint = null;
    var searchForm = null;
    var searchTimer = null;

    var MobileApp = {
        init: function() {
            backdrop = document.getElementById('mobile-sheet-backdrop');
            actionSheet = document.getElementById('mobile-action-sheet');
            moreDrawer = document.getElementById('mobile-more-drawer');
            searchSheet = document.getElementById('mobile-search-sheet');
            searchInput = document.getElementById('mobile-search-input');
            searchClearBtn = document.querySelector('.mobile-search-clear');
            searchList = document.getElementById('mobile-search-list');
            searchHint = document.getElementById('mobile-search-hint');
            searchForm = document.querySelector('.mobile-search-form');

            this.setupTouchGestures();
            this.setupSearch();
            this.setupActionBars();
            this.syncNotifications();
            this.checkIosInstallGuide();
        },

        /**
         * iOS-style bottom action bar.
         * Promotes the action strip (primary submit buttons + their
         * Cancel/Back peers) of the page's main POST form into a fixed bar
         * pinned above the bottom nav, so every form gets a consistent
         * native action footer. GET forms (searches/filters) are never
         * promoted. The originals stay in the DOM (hidden on mobile only).
         */
        setupActionBars: function() {
            var bar = document.getElementById('mobile-action-bar');
            if (!bar || bar.dataset.promoted) return;

            if (!window.matchMedia || !window.matchMedia('(max-width: 991.98px)').matches) return;

            var submits = Array.prototype.slice.call(document.querySelectorAll('form[method="POST"] button[type="submit"]'));
            for (var i = 0; i < submits.length; i++) {
                if (this.promoteAction(submits[i], bar)) return;
            }
        },

        /**
         * Attempt to promote a single submit button's action strip into the
         * bottom action bar. Returns true when promoted.
         */
        promoteAction: function(submit, bar) {
            var form = submit.closest('form');
            if (!form) return false;
            if (submit.closest('.modal, .mobile-bottom-sheet, .mobile-drawer, .mobile-search-sheet, nav, header, aside, #quick-add-fab, .page-header')) return false;
            if (form.hasAttribute('data-mobile-actions-off')) return false;
            // Only visible, interactive buttons (in a real browser display:none = 0x0)
            if (!this.isVisible(submit)) return false;

            var strip = this.findActionStrip(submit, form);
            if (!strip) return false;

            var items = Array.prototype.slice.call(strip.querySelectorAll('button, a.btn'));
            if (!items.some(function(el) { return el.getAttribute('type') === 'submit'; })) return false;

            var self = this;
            // iOS order: secondary actions first, primary submit last (right).
            items
                .sort(function(a) { return a.getAttribute('type') === 'submit' ? 1 : -1; })
                .forEach(function(original) {
                    var clone = original.cloneNode(true);
                    original.classList.add('mobile-duplicate');
                    if (original.getAttribute('type') === 'submit') {
                        clone.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            // Click the original submit button — keeps HTML5
                            // validation and the button's name/value payload.
                            original.click();
                        });
                    }
                    bar.appendChild(clone);
                });

            bar.hidden = false;
            bar.dataset.promoted = '1';
            document.body.classList.add('has-mobile-action-bar');
            return true;
        },

        /**
         * Find the action strip that directly wraps this submit button:
         * walk up (max a few levels, never past the form) until we find a
         * container holding buttons/links only (no inputs/selects).
         */
        findActionStrip: function(submit, form) {
            var node = submit.parentElement;
            var depth = 0;
            while (node && node !== form && depth < 3 && node.querySelector('input, select, textarea')) {
                node = node.parentElement;
                depth++;
            }
            if (!node || node === form || !node.querySelector('button[type="submit"]')) return null;
            if (node.querySelector('input, select, textarea')) return null;
            return node;
        },

        isVisible: function(el) {
            if (!el || el.getBoundingClientRect) {
                var r = el.getBoundingClientRect();
                if (r.width > 0 && r.height > 0) {
                    return typeof getComputedStyle !== 'undefined'
                        ? getComputedStyle(el).display !== 'none'
                        : true;
                }
            }
            return false;
        },

        vibrate: function(ms) {
            if (navigator && typeof navigator.vibrate === 'function') {
                try {
                    navigator.vibrate(ms || 10);
                } catch (e) {}
            }
        },

        openActions: function() {
            this.vibrate(12);
            if (backdrop) backdrop.classList.add('show');
            if (actionSheet) {
                actionSheet.classList.add('show');
                actionSheet.setAttribute('aria-hidden', 'false');
            }
            document.body.style.overflow = 'hidden';
        },

        closeActions: function() {
            if (actionSheet) {
                actionSheet.classList.remove('show');
                actionSheet.setAttribute('aria-hidden', 'true');
            }
            if (!moreDrawer || !moreDrawer.classList.contains('show')) {
                if (backdrop) backdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        },

        openDrawer: function() {
            this.vibrate(10);
            if (backdrop) backdrop.classList.add('show');
            if (moreDrawer) {
                moreDrawer.classList.add('show');
                moreDrawer.setAttribute('aria-hidden', 'false');
            }
            document.body.style.overflow = 'hidden';
        },

        closeDrawer: function() {
            if (moreDrawer) {
                moreDrawer.classList.remove('show');
                moreDrawer.setAttribute('aria-hidden', 'true');
            }
            if (!actionSheet || !actionSheet.classList.contains('show')) {
                if (backdrop) backdrop.classList.remove('show');
                document.body.style.overflow = '';
            }
        },

        closeAllSheets: function() {
            this.closeActions();
            this.closeDrawer();
            if (backdrop) backdrop.classList.remove('show');
            document.body.style.overflow = '';
        },

        openSearch: function() {
            this.vibrate(8);
            if (searchSheet) {
                searchSheet.classList.add('show');
                searchSheet.setAttribute('aria-hidden', 'false');
                setTimeout(function() {
                    if (searchInput) searchInput.focus();
                }, 100);
            }
            document.body.style.overflow = 'hidden';
        },

        closeSearch: function() {
            if (searchSheet) {
                searchSheet.classList.remove('show');
                searchSheet.setAttribute('aria-hidden', 'true');
            }
            if (searchInput) {
                searchInput.blur();
            }
            document.body.style.overflow = '';
        },

        clearSearch: function() {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            if (searchClearBtn) searchClearBtn.style.display = 'none';
            if (searchList) searchList.innerHTML = '';
            if (searchHint) searchHint.style.display = 'block';
        },

        onSearchSubmit: function(e) {
            if (!searchInput || !searchInput.value.trim()) {
                e.preventDefault();
                return;
            }
            if (searchForm) {
                searchForm.setAttribute('action', (window.location.origin || '') + '/search?scope=' + this.searchScope());
            }
        },

        /**
         * The current page's search domain. Search is page-specific so it
         * finds units (inventory), leads, deals or buyers instantly without
         * a submit click. Overridable with data-search-scope on <body>.
         */
        searchScope: function() {
            var explicit = document.body && document.body.getAttribute('data-search-scope');
            if (explicit) return explicit;
            var path = window.location.pathname || '';
            if (/^\/leads(\/|$)/.test(path)) return 'leads';
            if (/^\/inventory(\/|$)/.test(path) || /^\/listings(\/|$)/.test(path)) return 'inventory';
            if (/^\/buyers(\/|$)/.test(path)) return 'buyers';
            if (/^\/deals(\/|$)/.test(path) || /^\/pipeline(\/|$)/.test(path)) return 'deals';
            return 'all';
        },

        searchScopeLabel: function() {
            var labels = { 'inventory': 'units', 'leads': 'leads', 'deals': 'deals', 'buyers': 'buyers' };
            return labels[this.searchScope()] || 'all records';
        },

        setupSearch: function() {
            var self = this;
            if (!searchInput) return;
            if (searchForm) {
                searchForm.setAttribute('action', (window.location.origin || '') + '/search?scope=' + this.searchScope());
            }
            if (searchHint) {
                searchHint.textContent = 'Search ' + this.searchScopeLabel() + ' — results appear as you type.';
            }

            searchInput.addEventListener('input', function() {
                var query = searchInput.value.trim();
                if (searchClearBtn) {
                    searchClearBtn.style.display = query.length > 0 ? 'block' : 'none';
                }

                if (query.length < 2) {
                    if (searchList) searchList.innerHTML = '';
                    if (searchHint) searchHint.style.display = 'block';
                    return;
                }

                clearTimeout(searchTimer);
                searchTimer = setTimeout(function() {
                    self.performLiveSearch(query);
                }, 280);
            });
        },

        performLiveSearch: function(query) {
            var urlBase = (window.location.origin || '') + '/search?q=' + encodeURIComponent(query) + '&scope=' + this.searchScope();
            var fullUrl = urlBase.replace('&scope=', '&scope=');
            if (searchHint) searchHint.style.display = 'none';
            if (searchList) {
                searchList.innerHTML = '<div class="text-center py-4 text-muted small"><div class="spinner-border spinner-border-sm me-2"></div>Searching...</div>';
            }

            var colors = { 'lead': 'blue', 'deal': 'purple', 'buyer': 'green', 'property': 'orange' };
            var self = this;

            fetch(urlBase, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!searchList) return;
                var items = (data && data.results) || [];

                var live = query === (searchInput.value || '').trim();
                if (!live) return;

                if (items.length === 0) {
                    searchList.innerHTML = '<div class="text-center py-4 text-muted small">No results for "' +
                        MobileApp.escapeHtml(query) + '" in ' + self.searchScopeLabel() + '.<br>' +
                        '<span class="text-secondary">Try a community, unit number, building or client name.</span></div>';
                    return;
                }

                var output = '<div class="p-2">' +
                    '<div class="mb-2 d-flex justify-content-between align-items-center px-1">' +
                    '<span class="small fw-bold text-muted">' + items.length + ' in ' + self.searchScopeLabel() + ' &middot; "' + MobileApp.escapeHtml(query) + '"</span>' +
                    '<a href="' + fullUrl + '" class="small text-primary fw-bold">View all &rarr;</a>' +
                    '</div>';

                items.forEach(function(r) {
                    if (!r || !r.url) return;
                    var type = r.type || 'property';
                    var label = type === 'property'
                        ? (self.searchScope() === 'inventory' ? 'Unit' : 'Property')
                        : (type.charAt(0).toUpperCase() + type.slice(1));
                    var color = colors[type] || 'secondary';
                    output += '<a href="' + r.url + '" class="d-flex align-items-center gap-3 p-3 mb-2 rounded-3 bg-body-tertiary text-decoration-none text-reset border">' +
                        '<span class="badge text-uppercase bg-' + color + '-lt text-' + color + '" style="font-size:10px;flex:0 0 auto;">' + MobileApp.escapeHtml(label) + '</span>' +
                        '<div class="flex-fill" style="min-width:0;">' +
                        '<strong class="d-block text-truncate">' + MobileApp.escapeHtml(r.title) + '</strong>' +
                        (r.subtitle ? '<span class="small text-muted text-truncate d-block">' + MobileApp.escapeHtml(r.subtitle) + '</span>' : '') +
                        '</div>' +
                        '<span class="text-muted">&rsaquo;</span></a>';
                });

                output += '</div>';
                searchList.innerHTML = output;
            })
            .catch(function() {
                if (!searchList) return;
                searchList.innerHTML = '<div class="text-center py-4 text-muted small">Search preview unavailable. ' +
                    '<a href="' + fullUrl + '">Open search results</a></div>';
            });
        },

        setupTouchGestures: function() {
            var self = this;
            if (!actionSheet) return;

            var startY = 0;
            var currentY = 0;
            var handle = actionSheet.querySelector('.sheet-handle-bar') || actionSheet.querySelector('.sheet-header');

            if (handle) {
                handle.addEventListener('touchstart', function(e) {
                    startY = e.touches[0].clientY;
                }, { passive: true });

                handle.addEventListener('touchmove', function(e) {
                    currentY = e.touches[0].clientY;
                    var diff = currentY - startY;
                    if (diff > 0) {
                        actionSheet.style.transform = 'translateY(' + diff + 'px)';
                    }
                }, { passive: true });

                handle.addEventListener('touchend', function(e) {
                    var diff = currentY - startY;
                    actionSheet.style.transform = '';
                    if (diff > 80) {
                        self.closeActions();
                    }
                });
            }
        },

        syncNotifications: function() {
            var notifDot = document.getElementById('mobile-notif-dot');
            var desktopBadge = document.getElementById('notif-badge');

            function sync() {
                var hasUnread = desktopBadge.style.display !== 'none' && (parseInt(desktopBadge.textContent) || 0) > 0;
                notifDot.style.display = hasUnread ? 'block' : 'none';
            }

            if (notifDot && desktopBadge) {
                sync();
                var observer = new MutationObserver(sync);
                observer.observe(desktopBadge, { attributes: true, childList: true, characterData: true });
            }
        },

        checkIosInstallGuide: function() {
            // Check if iOS Safari and not standalone
            var isIos = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            var isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

            if (isIos && !isStandalone) {
                var dismissed = localStorage.getItem('ios-pwa-guide-dismissed');
                if (!dismissed) {
                    // Show subtle hint after 4 seconds of first visit
                    setTimeout(function() {
                        MobileApp.showIosBanner();
                    }, 4000);
                }
            }
        },

        showIosBanner: function() {
            if (document.getElementById('ios-install-banner')) return;

            var banner = document.createElement('div');
            banner.id = 'ios-install-banner';
            banner.style.cssText = 'position:fixed;bottom:calc(70px + env(safe-area-inset-bottom,0px));left:1rem;right:1rem;z-index:1039;' +
                'background:#1e293b;color:#fff;padding:0.85rem 1rem;border-radius:14px;box-shadow:0 10px 25px rgba(0,0,0,0.3);' +
                'display:flex;align-items:center;gap:0.75rem;font-size:0.82rem;animation:slideUp 0.3s ease;';

            banner.innerHTML = '<div style="flex:1;">' +
                '<strong>Install as App</strong><br>' +
                '<span style="opacity:0.85;">Tap <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> Share then <strong>"Add to Home Screen"</strong> for full-screen native experience.</span>' +
                '</div>' +
                '<button id="ios-dismiss-btn" style="background:transparent;border:none;color:#94a3b8;font-size:1.3rem;padding:0 4px;cursor:pointer;">&times;</button>';

            document.body.appendChild(banner);

            var dismissBtn = document.getElementById('ios-dismiss-btn');
            if (dismissBtn) {
                dismissBtn.addEventListener('click', function() {
                    banner.remove();
                    localStorage.setItem('ios-pwa-guide-dismissed', Date.now());
                });
            }
        },

        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    };

    window.MobileApp = MobileApp;

    document.addEventListener('DOMContentLoaded', function() {
        MobileApp.init();
    });

})(window, document);
