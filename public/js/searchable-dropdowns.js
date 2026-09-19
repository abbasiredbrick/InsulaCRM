/**
 * Global searchable dropdowns.
 *
 * Progressively enhances every native <select> in the app into a searchable
 * combobox (same UX used on the Schedule Viewing form), while keeping the
 * original <select> in the DOM so existing forms, names, validation and any
 * inline handlers keep working unchanged.
 *
 * Opt out per element with:  <select data-no-search ...>  or class "no-searchable".
 * Force on a tiny list with:  <select data-searchable ...>.
 */
(function () {
    if (window.__sdBooted) { return; }
    window.__sdBooted = true;

    var MIN_OPTIONS = 4;

    function injectStyles() {
        if (document.getElementById('sd-styles')) { return; }
        var css = document.createElement('style');
        css.id = 'sd-styles';
        css.textContent = [
            '.sd-wrap{position:relative;}',
            '.sd-native{position:absolute !important; width:1px; height:1px; opacity:0 !important; pointer-events:none !important; overflow:hidden;}',
            '.sd-field{width:100%; cursor:pointer;}',
            '.sd-menu{position:absolute; left:0; right:0; z-index:1055; margin-top:.25rem; padding:.35rem;',
            '  background:#fff; border:1px solid var(--tblr-border-color,#dee2e6); border-radius:.375rem;',
            '  box-shadow:0 .4rem 1.2rem rgba(0,0,0,.18);}',
            '.sd-list{max-height:280px; overflow:auto; margin-top:.25rem;}',
            '.sd-item{padding:.45rem .6rem; border-radius:.25rem; cursor:pointer; font-size:.875rem; line-height:1.35;}',
            '.sd-item:hover,.sd-item.sd-active{background:var(--tblr-primary,#0054a6); color:#fff;}',
            '.sd-item.sd-selected{font-weight:600; background:rgba(0,0,0,.06);}',
            '.sd-item.sd-selected:hover{background:var(--tblr-primary,#0054a6); color:#fff;}',
            '.sd-empty{padding:.45rem .6rem; font-size:.875rem; color:#6c757d;}',
            '.sd-menu .form-control{margin-top:.15rem;}'
        ].join('');
        document.head.appendChild(css);
    }

    function placeholderFor(select) {
        if (select.dataset.placeholder) { return select.dataset.placeholder; }
        var first = select.options.length ? select.options[0] : null;
        if (first && (first.value === '' || first.disabled)) {
            var t = (first.textContent || '').trim();
            if (t && t !== '—' && t !== '-' && t !== '--') { return t; }
        }
        return 'Select...';
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function shouldEnhance(select) {
        if (!select || select.tagName !== 'SELECT') { return false; }
        if (select.dataset.sdDone) { return false; }
        if (select.multiple) { return false; }
        if (select.size && parseInt(select.size, 10) > 1) { return false; }
        if (select.dataset.noSearch !== undefined) { return false; }
        if (select.classList.contains('no-searchable')) { return false; }
        if (select.classList.contains('sd-native')) { return false; }
        if (select.closest('[data-ss]')) { return false; }
        if (select.closest('.sd-wrap')) { return false; }
        if (select.classList.contains('d-none') || select.style.display === 'none') { return false; }
        if (select.offsetParent === null) { return false; }
        var force = select.dataset.searchable !== undefined;
        if (!force && select.closest('.input-group')) { return false; }
        if (!force && select.options.length < MIN_OPTIONS) { return false; }
        return select.options.length > 1;
    }

    function enhance(select) {
        select.dataset.sdDone = '1';

        var wrap = document.createElement('div');
        wrap.className = 'sd-wrap';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('sd-native');
        select.setAttribute('tabindex', '-1');

        var placeholder = placeholderFor(select);
        var field = document.createElement('button');
        field.type = 'button';
        field.className = (select.className.replace('sd-native', '') + ' sd-field text-start').trim();
        field.setAttribute('aria-haspopup', 'listbox');
        field.setAttribute('aria-expanded', 'false');
        var valueEl = document.createElement('span');
        valueEl.className = 'text-truncate d-block';
        field.appendChild(valueEl);

        var menu = document.createElement('div');
        menu.className = 'sd-menu';
        menu.style.display = 'none';
        var filter = document.createElement('input');
        filter.type = 'text';
        filter.className = 'form-control form-control-sm';
        filter.placeholder = select.dataset.searchPlaceholder || 'Type to search...';
        filter.autocomplete = 'off';
        var list = document.createElement('div');
        list.className = 'sd-list';
        menu.appendChild(filter);
        menu.appendChild(list);

        wrap.appendChild(field);
        wrap.appendChild(menu);

        if (select.disabled) {
            field.disabled = true;
            field.classList.add('disabled');
        }

        var isOpen = false;
        var activeIndex = -1;

        function optionsList() {
            return Array.prototype.slice.call(select.options);
        }

        function refreshValueText() {
            var opt = select.options[select.selectedIndex] || null;
            var text = opt ? (opt.textContent || '').trim() : '';
            if (!text || opt.value === '') {
                valueEl.textContent = placeholder;
                valueEl.classList.add('text-muted');
            } else {
                valueEl.textContent = text;
                valueEl.classList.remove('text-muted');
            }
        }

        function render() {
            var q = (filter.value || '').trim().toLowerCase();
            var html = '';
            var items = optionsList();
            items.forEach(function (opt) {
                var t = (opt.textContent || '').trim();
                if (q && t.toLowerCase().indexOf(q) === -1) { return; }
                var sel = opt.selected ? ' sd-selected' : '';
                html += '<div class="sd-item' + sel + '" data-value="' + escapeHtml(opt.value) + '">' + escapeHtml(t || '—') + '</div>';
            });
            list.innerHTML = html || '<div class="sd-empty">No matches found.</div>';
        }

        function open() {
            if (select.disabled) { return; }
            isOpen = true;
            activeIndex = -1;
            filter.value = '';
            menu.style.display = '';
            field.setAttribute('aria-expanded', 'true');
            render();
            setTimeout(function () { filter.focus(); }, 0);
        }

        function close() {
            isOpen = false;
            menu.style.display = 'none';
            field.setAttribute('aria-expanded', 'false');
        }

        function choose(value) {
            select.value = value;
            refreshValueText();
            close();
            try {
                select.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (e) {
                var ev = document.createEvent('HTMLEvents');
                ev.initEvent('change', true, false);
                select.dispatchEvent(ev);
            }
        }

        field.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isOpen) { close(); } else { open(); }
        });

        filter.addEventListener('input', function () { activeIndex = -1; render(); });

        filter.addEventListener('keydown', function (e) {
            var items = list.querySelectorAll('.sd-item');
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1); }
            if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && items[activeIndex]) { choose(items[activeIndex].dataset.value); }
                else if (items.length) { choose(items[0].dataset.value); }
                return;
            }
            items.forEach(function (el, i) { el.classList.toggle('sd-active', i === activeIndex); });
            if (activeIndex >= 0 && items[activeIndex]) {
                try { items[activeIndex].scrollIntoView({ block: 'nearest' }); } catch (err) {}
            }
        });

        list.addEventListener('click', function (e) {
            var item = e.target.closest ? e.target.closest('.sd-item') : null;
            if (item) { choose(item.dataset.value); }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) { close(); }
        });

        select.addEventListener('change', refreshValueText);

        refreshValueText();
    }

    function boot(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var selects = scope.querySelectorAll('select');
        for (var i = 0; i < selects.length; i++) {
            try {
                if (shouldEnhance(selects[i])) { enhance(selects[i]); }
            } catch (e) { /* never break the page over a dropdown */ }
        }
    }

    function start() {
        injectStyles();
        boot(document);

        if (window.MutationObserver) {
            var pending = false;
            var observer = new MutationObserver(function () {
                if (pending) { return; }
                pending = true;
                setTimeout(function () {
                    pending = false;
                    boot(document);
                }, 150);
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
