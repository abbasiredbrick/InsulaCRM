/**
 * Live list filtering.
 *
 * Turns a plain GET filter form into an instant-search list:
 *   - text inputs re-query as the user types (debounced);
 *   - dropdown / checkbox / date changes re-query immediately;
 *   - results are fetched as HTML and swapped in place (no full page load);
 *   - the active filter state is mirrored into the URL via history.replaceState,
 *     so opening a record and pressing Back returns to the exact same search.
 *
 * Usage:
 *   <form method="GET" action="/inventory" data-live-filter>...</form>
 *   <div data-live-results>... table + pagination ...</div>
 *
 * The form itself is never replaced, so its controls (and any per-control
 * behaviour such as cascading option lists) keep working. Scripts that bind
 * to elements inside the results region should listen for the bubbling
 * "insulacrm:live-updated" event and re-bind.
 */
(function () {
    if (window.__liveFilterBooted) { return; }
    window.__liveFilterBooted = true;

    var DEBOUNCE = 350;
    var RESULTS_SELECTOR = '[data-live-results]';

    var LIST_STATE_PREFIX = 'lf|list|';

    function listStateKey(pathname) {
        return LIST_STATE_PREFIX + pathname;
    }

    /**
     * Remember the exact search URL of every list in this tab. Detail pages use
     * it to rewrite their "back to list" breadcrumbs so returning to the list
     * keeps the applied filters instead of landing on the bare list.
     */
    function rememberListUrl(url) {
        try {
            if (url.search) {
                sessionStorage.setItem(listStateKey(url.pathname), url.pathname + url.search);
            } else {
                sessionStorage.removeItem(listStateKey(url.pathname));
            }
        } catch (e) { /* ignore */ }
    }

    /**
     * Rewrite in-page links that point at a known list path so they return to
     * the exact search that was active (breadcrumbs, "back" buttons...).
     * Only exact-list hrefs with no query on them are rewritten, and the list
     * page itself (where the Reset button lives) is never touched.
     */
    function rewireBackLinks() {
        var current = window.location.pathname;
        var keys = [];
        try {
            for (var i = 0; i < sessionStorage.length; i++) {
                var k = sessionStorage.key(i);
                if (k && k.indexOf(LIST_STATE_PREFIX) === 0) keys.push(k);
            }
        } catch (e) { return; }

        for (var j = 0; j < keys.length; j++) {
            var savedPath = keys[j].slice(LIST_STATE_PREFIX.length);
            var savedUrl = null;
            try { savedUrl = sessionStorage.getItem(keys[j]); } catch (e) { /* ignore */ }
            if (!savedUrl || savedPath === current) continue;

            var links = document.querySelectorAll('a[href]');
            for (var k = 0; k < links.length; k++) {
                var a = links[k];
                // Leave primary navigation tabs and sidebar links alone — only
                // breadcrumb / back-to-list links get the saved filters.
                if (a.hasAttribute('data-tab') || a.classList.contains('nav-link') ||
                    a.closest('.nav, nav, [data-tab]')) continue;
                var href = a.getAttribute('href') || '';
                if (href.indexOf('?') !== -1 || href.indexOf('#') !== -1) continue;
                try {
                    var target = new URL(a.href, window.location.origin);
                    if (target.pathname !== savedPath) continue;
                    a.href = savedUrl;
                } catch (e) { /* ignore */ }
            }
        }
    }

    function boot() {
        var forms = document.querySelectorAll('form[data-live-filter]');
        for (var i = 0; i < forms.length; i++) {
            wire(forms[i]);
        }
        rewireBackLinks();
    }

    function wire(form) {
        if (form.__lfWired) { return; }
        form.__lfWired = true;

        var results = document.querySelector(form.dataset.liveResults || RESULTS_SELECTOR);
        if (!results) { return; }

        var timer = null;
        var controller = null;
        var seq = 0;

        function setBusy(busy) {
            results.style.opacity = busy ? '0.45' : '1';
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
        }

        function run() {
            var url = buildUrl(form);
            rememberListUrl(url);
            try {
                history.replaceState(history.state, '', url.pathname + url.search + url.hash);
            } catch (e) { /* ignore */ }

            var token = ++seq;
            clearTimeout(timer);
            if (controller) { controller.abort(); }
            controller = new AbortController();
            var signal = controller.signal;

            setBusy(true);
            fetch(url.toString(), { headers: { 'Accept': 'text/html' }, signal: signal })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.text();
                })
                .then(function (html) {
                    if (token !== seq) { return; }
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var next = doc.querySelector(RESULTS_SELECTOR);
                    if (next) {
                        results.replaceWith(next);
                        results = next;
                        results.dispatchEvent(
                            new CustomEvent('insulacrm:live-updated', { bubbles: true })
                        );
                    }
                    setBusy(false);
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    if (token !== seq) { return; }
                    setBusy(false);
                    // Network hiccup / session timeout: fall back to a normal submit
                    // so the user still gets a usable page (or the login screen).
                    try { form.submit(); } catch (e) { /* ignore */ }
                });
        }

        function runNow() {
            clearTimeout(timer);
            run();
        }

        // Intercept Enter / the filter button so "filtering" never triggers a
        // full page navigation that would lose the live-search behaviour.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            runNow();
        });

        form.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(runNow, DEBOUNCE);
        });

        form.addEventListener('change', function () {
            runNow();
        });
    }

    function buildUrl(form) {
        var url = new URL(form.action, window.location.href);
        url.searchParams.delete('page');

        var els = form.elements;
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            if (!el.name || el.disabled) { continue; }
            url.searchParams.delete(el.name);

            var type = (el.type || '').toLowerCase();
            if (type === 'submit' || type === 'button' || type === 'reset' ||
                type === 'file' || type === 'password') { continue; }

            if (type === 'checkbox' || type === 'radio') {
                if (el.checked) { url.searchParams.append(el.name, el.value); }
                continue;
            }

            if (el.tagName === 'SELECT' && el.multiple) {
                var any = false;
                for (var j = 0; j < el.options.length; j++) {
                    if (el.options[j].selected) {
                        url.searchParams.append(el.name, el.options[j].value);
                        any = true;
                    }
                }
                if (!any) { url.searchParams.delete(el.name); }
                continue;
            }

            if (el.value !== '' && el.value != null) {
                url.searchParams.append(el.name, el.value);
            }
        }
        return url;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();