/**
 * InsulaCRM Native Mobile App & PWA Controller
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

            this.setupTouchGestures();
            this.setupSearch();
            this.syncNotifications();
            this.checkIosInstallGuide();
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
            }
        },

        setupSearch: function() {
            var self = this;
            if (!searchInput) return;

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
            var searchUrl = (window.location.origin || '') + '/search?q=' + encodeURIComponent(query);
            if (searchHint) searchHint.style.display = 'none';
            if (searchList) {
                searchList.innerHTML = '<div class="text-center py-4 text-muted small"><div class="spinner-border spinner-border-sm me-2"></div>Searching...</div>';
            }

            fetch(searchUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html, application/xhtml+xml'
                }
            })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, 'text/html');
                var resultsContainer = doc.querySelector('.container-xl') || doc.body;

                // Extract grouped search cards or list items
                var cards = resultsContainer.querySelectorAll('.card, .list-group-item, table tr');
                if (cards.length > 0 && searchList) {
                    var output = '<div class="p-2">';
                    // If full search results found, provide link to complete results
                    output += '<div class="mb-3 d-flex justify-content-between align-items-center">' +
                              '<span class="small fw-bold text-muted">RESULTS FOR "' + MobileApp.escapeHtml(query) + '"</span>' +
                              '<a href="' + searchUrl + '" class="small text-primary fw-bold">View all &rarr;</a>' +
                              '</div>';

                    // Parse individual links
                    var links = resultsContainer.querySelectorAll('a[href*="/leads/"], a[href*="/deals/"], a[href*="/pipeline/"], a[href*="/properties/"], a[href*="/inventory/"], a[href*="/buyers/"]');
                    var seen = {};
                    var count = 0;

                    links.forEach(function(link) {
                        var href = link.getAttribute('href');
                        var text = (link.textContent || '').trim();
                        if (href && text && !seen[href] && count < 8) {
                            seen[href] = true;
                            count++;
                            output += '<a href="' + href + '" class="d-flex align-items-center gap-3 p-3 mb-2 rounded-3 bg-body-tertiary text-decoration-none text-reset border">' +
                                      '<div class="flex-fill"><strong class="d-block">' + MobileApp.escapeHtml(text) + '</strong>' +
                                      '<span class="small text-muted">' + MobileApp.escapeHtml(href.split('/')[1] || '') + '</span></div>' +
                                      '<span class="text-muted">&rsaquo;</span></a>';
                        }
                    });

                    if (count === 0) {
                        output += '<div class="text-center py-4 text-muted small">No direct records found. <a href="' + searchUrl + '">Open full search</a></div>';
                    }

                    output += '</div>';
                    searchList.innerHTML = output;
                } else if (searchList) {
                    searchList.innerHTML = '<div class="text-center py-4 text-muted small">No matches found for "' + MobileApp.escapeHtml(query) + '"</div>';
                }
            })
            .catch(function(err) {
                if (searchList) {
                    searchList.innerHTML = '<div class="text-center py-4 text-muted small">Search preview unavailable. <a href="' + searchUrl + '">Search full page</a></div>';
                }
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
