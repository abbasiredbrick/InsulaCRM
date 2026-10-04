/**
 * App-wide "processing" overlay.
 *
 * Long operations in Keystone (importing a PM availability sheet, pulling portal
 * leads, syncing a listing, sending a message) are real server work, but the UI
 * gave no feedback at all — the user could click Save twice and queue duplicate
 * work with nothing on screen to say "this is running".
 *
 * This shows a blocking overlay with the Keystone mark while a task is in
 * flight, and swallows every further click until the task finishes, so a task
 * can only ever be started once.
 *
 * ── What counts as a task ────────────────────────────────────────────────────
 *   1. A form submission that is not a live-filter form (causes a navigation).
 *   2. Any non-GET fetch() / XMLHttpRequest — a write. Reads (GET) are treated
 *      as background work and never block: the live-filter list, global search
 *      and notification polling all fetch on every keystroke or on a timer, and
 *      blocking on those would freeze the whole app on every character typed.
 *   3. Any element explicitly marked data-task / data-task-busy.
 *
 * Opt out with data-no-processing on a form or a container.
 *
 * ── It can never lock the app ────────────────────────────────────────────────
 * A stuck overlay would be far worse than a missing one, so the overlay always
 * releases itself: on completion, on an aborted request, when the page is
 * hidden/unloaded, when the tab is restored from bfcache, and unconditionally
 * after SAFETY_TIMEOUT_MS in case a response never arrives.
 */
(function () {
    if (window.__processingOverlayBooted) { return; }
    window.__processingOverlayBooted = true;

    var SAFETY_TIMEOUT_MS = 60000;
    var BACKSTOP_TIMEOUT_MS = 15000;
    var MIN_VISIBLE_MS = 350;

    var OVERLAY_ID = 'keystone-processing-overlay';
    var ACTIVE_ATTR = 'data-processing-active';
    var NO_PROCESSING = '[data-no-processing]';

    var state = {
        overlay: null,
        active: false,
        // Task requests currently in flight.
        pending: 0,
        // Extra show() calls that have not been matched by a completion.
        heldByAction: 0,
        shownAt: 0,
        safetyTimer: null,
        backstopTimer: null,
        hideTimer: null
    };

    function label() {
        return (window.__keystoneProcessingLabel || 'Processing…');
    }

    function ensureOverlay() {
        if (state.overlay && document.body.contains(state.overlay)) { return state.overlay; }

        var el = document.getElementById(OVERLAY_ID);
        if (el) { state.overlay = el; return el; }

        el = document.createElement('div');
        el.id = OVERLAY_ID;
        el.setAttribute(ACTIVE_ATTR, 'false');
        el.setAttribute('role', 'alert');
        el.setAttribute('aria-live', 'assertive');
        el.setAttribute('aria-busy', 'false');
        el.setAttribute('aria-label', label());
        el.innerHTML =
            '<div class="keystone-processing__panel">' +
            '<img class="keystone-processing__logo" src="" alt="" aria-hidden="true">' +
            '<div class="keystone-processing__bar"><span></span></div>' +
            '<div class="keystone-processing__label"></div>' +
            '</div>';

        var logo = el.querySelector('.keystone-processing__logo');
        logo.src = window.__keystoneProcessingLogo || '';
        el.querySelector('.keystone-processing__label').textContent = label();

        document.body.appendChild(el);
        state.overlay = el;

        return el;
    }

    function clearTimers() {
        if (state.safetyTimer) { clearTimeout(state.safetyTimer); state.safetyTimer = null; }
        if (state.backstopTimer) { clearTimeout(state.backstopTimer); state.backstopTimer = null; }
        if (state.hideTimer) { clearTimeout(state.hideTimer); state.hideTimer = null; }
    }

    function show() {
        if (!document.body) { return; }
        var el = ensureOverlay();
        clearTimers();

        if (!state.active) {
            state.active = true;
            state.shownAt = Date.now();
            el.setAttribute(ACTIVE_ATTR, 'true');
            el.setAttribute('aria-busy', 'true');
            document.documentElement.setAttribute(ACTIVE_ATTR, 'true');
        }

        // Last-resort release: a response that never arrives must not leave the
        // app unusable.
        state.safetyTimer = setTimeout(function () {
            state.pending = 0;
            state.heldByAction = 0;
            hide();
        }, SAFETY_TIMEOUT_MS);

        // A navigation-backed task (a normal form POST) never calls end(),
        // because the browser unloads the page. Release it if that unload is
        // somehow prevented or the request fails and the page stays put.
        state.backstopTimer = setTimeout(function () {
            state.heldByAction = 0;
            settle();
        }, BACKSTOP_TIMEOUT_MS);
    }

    function hide() {
        if (!state.active) { return; }
        clearTimers();

        // Avoid a flash of overlay for work that finished instantly.
        var elapsed = Date.now() - state.shownAt;
        if (elapsed < MIN_VISIBLE_MS) {
            state.hideTimer = setTimeout(hide, MIN_VISIBLE_MS - elapsed);
            return;
        }

        state.active = false;
        if (state.overlay) {
            state.overlay.setAttribute(ACTIVE_ATTR, 'false');
            state.overlay.setAttribute('aria-busy', 'false');
        }
        document.documentElement.removeAttribute(ACTIVE_ATTR);
    }

    /** Task started and will be ended explicitly. */
    function begin() {
        state.heldByAction++;
        show();
    }

    /** The task this begin() belongs to has finished. */
    function end() {
        state.heldByAction = Math.max(0, state.heldByAction - 1);
        settle();
    }

    /** Release once nothing is outstanding any more. */
    function settle() {
        if (state.pending === 0 && state.heldByAction === 0) { hide(); }
    }

    function isTaskRequest(input, init) {
        var method = '';

        if (init && init.method) {
            method = init.method;
        } else if (input && typeof input === 'object' && input.method) {
            method = input.method;
        } else if (typeof input === 'string') {
            // fetch(url) defaults to GET.
            method = 'GET';
        }

        method = String(method).toUpperCase();
        if (method === 'HEAD' || method === 'OPTIONS') { return false; }

        // A GET/HEAD is a read (list filtering, search, polling) — never block.
        if (method === 'GET') { return false; }

        if (init && init.task === false) { return false; }
        if (init && init.task === true) { return true; }

        // Honour an opt-out on the page element that started the request.
        try {
            if (init && init.headers && init.headers['X-Keystone-No-Processing']) { return false; }
        } catch (e) { /* ignore */ }

        return true;
    }

    function patchFetch() {
        if (typeof window.fetch !== 'function' || window.fetch.__keystonePatched) { return; }
        var original = window.fetch;

        var patched = function (input, init) {
            var task = isTaskRequest(input, init);

            if (!task) { return original.apply(this, arguments); }

            state.pending++;
            show();

            var args = arguments;
            return original.apply(this, args).then(
                function (res) { state.pending--; settle(); return res; },
                function (err) { state.pending--; settle(); throw err; }
            );
        };

        patched.__keystonePatched = true;
        window.fetch = patched;
    }

    function patchXhr() {
        var XHR = window.XMLHttpRequest;
        if (!XHR || XHR.__keystonePatched) { return; }

        var open = XHR.prototype.open;
        var send = XHR.prototype.send;

        XHR.prototype.open = function (method) {
            this.__keystoneMethod = String(method || 'GET').toUpperCase();
            return open.apply(this, arguments);
        };

        XHR.prototype.send = function () {
            var method = this.__keystoneMethod || 'GET';
            var task = method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS';

            if (!task) { return send.apply(this, arguments); }

            state.pending++;
            show();

            var self = this;
            var done = false;
            var settle = function () {
                if (done) { return; }
                done = true;
                self.removeEventListener('loadend', settle);
                state.pending--;
                window.__keystoneSettle();
            };
            this.addEventListener('loadend', settle);

            return send.apply(this, arguments);
        };

        XHR.prototype.__keystonePatched = true;
    }

    window.__keystoneSettle = settle;

    // ── Triggers ─────────────────────────────────────────────────────────────

    function closestOptOut(target) {
        if (!target || !target.closest) { return false; }
        return !!target.closest(NO_PROCESSING);
    }

    function wireForms() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') { return; }

            // Live filtering never navigates — it swaps results in place, so
            // showing a blocking overlay on every keystroke would be unusable.
            if (form.hasAttribute('data-live-filter')) { return; }
            if (closestOptOut(form)) { return; }

            // Bubble phase, so any other handler has already run and had its
            // chance to preventDefault (an AJAX submit owns its own release).
            if (e.defaultPrevented) { return; }

            // HTML5 constraint validation blocks the submit event entirely, so a
            // form that fails validation never reaches here.

            begin();
        }, false);
    }

    function wireExplicitTasks() {
        document.addEventListener('click', function (e) {
            var el = e.target;
            if (!el || !el.closest) { return; }

            // While a task is already running the overlay must swallow clicks
            // rather than start another one.
            if (state.active) { return; }

            var trigger = el.closest('[data-task]');
            if (!trigger) { return; }
            if (closestOptOut(trigger)) { return; }

            // A submit button is owned by the form submit handler.
            var type = (trigger.type || '').toLowerCase();
            if (type === 'submit') { return; }

            begin();
            setTimeout(end, 0);
        }, false);
    }

    function boot() {
        ensureOverlay();
        patchFetch();
        patchXhr();
        wireForms();
        wireExplicitTasks();

        // A click landing on the overlay must never reach the page underneath.
        document.addEventListener('click', function (e) {
            if (!state.active) { return; }
            if (state.overlay && (e.target === state.overlay || state.overlay.contains(e.target))) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);

        // Leaving the page ends any task: the response cannot arrive.
        window.addEventListener('pagehide', function () {
            state.pending = 0;
            state.heldByAction = 0;
            state.active = false;
            if (state.overlay) { state.overlay.setAttribute(ACTIVE_ATTR, 'false'); }
            document.documentElement.removeAttribute(ACTIVE_ATTR);
        });

        // Returning via bfcache must not restore a stuck overlay.
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) {
                state.pending = 0;
                state.heldByAction = 0;
                hide();
            }
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && state.active) {
                // Coming back to a tab whose request finished while hidden.
                settle();
            }
        });
    }

    // Expose a tiny manual API for long tasks that are not a form/fetch pair.
    window.KeystoneProcessing = {
        show: show,
        hide: hide,
        begin: begin,
        end: end,
        isActive: function () { return state.active; }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();