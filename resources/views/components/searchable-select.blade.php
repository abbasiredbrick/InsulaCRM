@props([
    'name' => '',
    'placeholder' => __('Select...'),
    'searchPlaceholder' => __('Type to search...'),
    'options' => [],
    'selected' => '',
    'required' => false,
    'invalid' => false,
    'remote' => '',
    'labelName' => '',
])

<div class="searchable-select" data-ss
    data-placeholder="{{ $placeholder }}"
    data-search-placeholder="{{ $searchPlaceholder }}"
    data-selected="{{ $selected }}"
    data-remote="{{ $remote }}"
    data-options="{{ json_encode($options) }}">
    <button type="button" class="form-select searchable-select-field text-start {{ $invalid ? 'is-invalid' : '' }}" data-ss-field
        aria-haspopup="listbox" aria-expanded="false">
        <span class="text-truncate d-block {{ $selected ? '' : 'text-muted' }}" data-ss-value>{{ $selected ? \Illuminate\Support\Str::limit($selected, 60) : $placeholder }}</span>
    </button>
    <div class="searchable-select-menu d-none" data-ss-menu>
        <input type="text" class="form-control form-control-sm" data-ss-filter placeholder="{{ $searchPlaceholder }}" autocomplete="off">
        <div class="searchable-select-list" data-ss-list role="listbox"></div>
    </div>
    <select name="{{ $name }}" class="d-none" data-ss-select {{ $required ? 'required' : '' }} aria-hidden="true">
        <option value="">{{ $placeholder }}</option>
        @foreach($options as $opt)
            <option value="{{ $opt['value'] }}" {{ (string) $opt['value'] === (string) $selected ? 'selected' : '' }}>{{ $opt['label'] }}</option>
        @endforeach
    </select>
@if($labelName)
    <input type="hidden" name="{{ $labelName }}" data-ss-label aria-hidden="true" value="{{ $selected }}">
    @endif
</div>

<style>
    .searchable-select .searchable-select-field { width: 100%; }
    .searchable-select .searchable-select-menu {
        width: 100%; margin-top: .25rem; padding: .35rem;
        background: #fff; border: 1px solid var(--tblr-border-color, #dee2e6);
        border-radius: .375rem; box-shadow: 0 .25rem 1rem rgba(0, 0, 0, .15);
    }
    .searchable-select .searchable-select-list { max-height: 260px; overflow: auto; margin-top: .25rem; }
    .searchable-select .ss-item {
        padding: .45rem .6rem; border-radius: .25rem; cursor: pointer;
        font-size: .875rem; line-height: 1.35;
    }
    .searchable-select .ss-item:hover { background-color: var(--tblr-primary, #0054a6); color: #fff; }
    .searchable-select .ss-item.ss-item-selected { font-weight: 600; background-color: rgba(0, 0, 0, .05); }
    .searchable-select .ss-empty { padding: .45rem .6rem; font-size: .875rem; }
</style>

<script>
(function () {
    window.__ssScriptRan = (window.__ssScriptRan || 0) + 1;

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function init(root) {
        const field = root.querySelector('[data-ss-field]');
        const valueEl = root.querySelector('[data-ss-value]');
        const menu = root.querySelector('[data-ss-menu]');
        const filter = root.querySelector('[data-ss-filter]');
        const list = root.querySelector('[data-ss-list]');
        const select = root.querySelector('[data-ss-select]');
        const labelInput = root.querySelector('[data-ss-label]');
        const remoteUrl = (root.dataset.remote || '').trim();

        let options = [];
        try { options = JSON.parse(root.dataset.options || '[]'); } catch (e) { options = []; }
        let remoteOptions = [];
        let isRemoteLoading = false;

        function ensureOption(value, label) {
            const v = String(value);
            const existing = Array.prototype.find.call(select.options, function (o) { return String(o.value) === v; });
            if (!existing) {
                const opt = document.createElement('option');
                opt.value = v;
                opt.textContent = label || v;
                select.appendChild(opt);
            }
        }

        function setOptionsFrom(list) {
            remoteOptions = list;
            list.forEach(function (o) { ensureOption(o.value, o.label); });
        }

        function currentList() {
            const q = (filter.value || '').trim().toLowerCase();
            if (remoteUrl && q) { return remoteOptions; }
            return options;
        }

        let isOpen = false;
        let activeIndex = -1;

        function labelOf(value) {
            const found = currentList().find(function (o) { return String(o.value) === String(value); });
            if (found) { return String(found.label || ''); }
            const localFound = options.find(function (o) { return String(o.value) === String(value); });
            return localFound ? String(localFound.label || '') : '';
        }

        function syncLabelInput(value) {
            const lbl = labelOf(value);
            if (labelInput) { labelInput.value = lbl; }
            return lbl;
        }

        function render() {
            const q = (filter.value || '').trim().toLowerCase();
            let html = '';
            let emptyText = '{{ __('No matches found.') }}';
            let rows = currentList();
            if (remoteUrl && q && isRemoteLoading) { html = '<div class="ss-empty text-muted">{{ __('Searching...') }}</div>'; emptyText = ''; }
            else {
                rows.forEach(function (o) {
                    let t;
                    try { t = String(o.label || ''); } catch (e) { t = ''; }
                    if (q && t.toLowerCase().indexOf(q) === -1) { return; }
                    const selected = String(o.value) === String(select.value);
                    html += '<div class="ss-item' + (selected ? ' ss-item-selected' : '') + '" data-value="' + escapeHtml(o.value) + '">' + escapeHtml(t) + '</div>';
                });
            }
            list.innerHTML = html || (emptyText ? '<div class="ss-empty text-muted">' + emptyText + '</div>' : '');
        }

        function refreshValueText() {
            valueEl.textContent = labelOf(select.value) || root.dataset.placeholder || '';
            var disableClass = 'text-muted';
            if (select.value) { valueEl.classList.remove(disableClass); } else { valueEl.classList.add(disableClass); }
        }

        function setValue(value) {
            select.value = value;
            syncLabelInput(value);
            refreshValueText();
            close();
            root.dispatchEvent(new CustomEvent('ss-change', { detail: value }));
        }

        function open() {
            isOpen = true;
            activeIndex = -1;
            filter.value = '';
            menu.classList.remove('d-none');
            field.setAttribute('aria-expanded', 'true');
            try { render(); } catch (e) {
                list.innerHTML = '{{ __('Could not list options.') }} ' + String(e && e.message || e);
            }
            setTimeout(function () { filter.focus(); }, 0);
        }

        function close() {
            isOpen = false;
            menu.classList.add('d-none');
            field.setAttribute('aria-expanded', 'false');
        }

        field.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isOpen) { close(); } else { open(); }
        });

        field.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (!isOpen) { open(); }
            }
        });

        filter.addEventListener('input', function () {
            activeIndex = -1;
            const q = filter.value.trim();

            if (remoteUrl && q) {
                isRemoteLoading = true;
                render();
                clearTimeout(window.__ssRemoteTimer);
                window.__ssRemoteTimer = setTimeout(function () {
                    fetch(remoteUrl + (remoteUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            isRemoteLoading = false;
                            const list = data && data.results !== undefined ? data.results : (Array.isArray(data) ? data : []);
                            setOptionsFrom(Array.isArray(list) ? list : []);
                            render();
                        })
                        .catch(function () { isRemoteLoading = false; render(); });
                }, 260);
                return;
            }

            isRemoteLoading = false;
            render();
        });

        filter.addEventListener('keydown', function (e) {
            const items = list.querySelectorAll('.ss-item');
            if (e.key === 'Escape') { e.preventDefault(); close(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1); }
            if (e.key === 'ArrowUp') { e.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0); }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && items[activeIndex]) { setValue(items[activeIndex].dataset.value); }
                else if (items.length) { setValue(items[0].dataset.value); }
                return;
            }
            if (activeIndex >= 0 && items[activeIndex]) {
                items.forEach(function (el, i) { el.classList.toggle('ss-item-selected', String(el.dataset.value) === String(select.value) ? i === activeIndex : false); });
                try { items[activeIndex].scrollIntoView({ block: 'nearest' }); } catch (e) {}
            }
        });

        list.addEventListener('click', function (e) {
            const item = e.target.closest ? e.target.closest('.ss-item') : null;
            if (item) { setValue(item.dataset.value); }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) { close(); }
        });

        refreshValueText();
        if (labelInput) { labelInput.value = labelOf(select.value); }
        root.dataset.ssReady = '1';
    }

    function boot() {
        var roots = document.querySelectorAll('[data-ss]');
        for (var i = 0; i < roots.length; i++) {
            if (roots[i].dataset && roots[i].dataset.ssInited) { continue; }
            if (roots[i].dataset) { roots[i].dataset.ssInited = '1'; }
            try { init(roots[i]); } catch (e) {
                if (roots[i].dataset) { roots[i].dataset.ssError = String(e && e.message || e); }
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    setTimeout(function () {
        var roots = document.querySelectorAll('[data-ss]');
        for (var i = 0; i < roots.length; i++) {
            var r = roots[i];
            if (!r.dataset) { continue; }
            if (r.dataset.ssError) {
                var menu = r.querySelector('[data-ss-menu]');
                var list = r.querySelector('[data-ss-list]');
                if (menu && list) {
                    menu.classList.remove('d-none');
                    list.innerHTML = '<div class="ss-empty text-danger">Select error: ' + escapeHtml(r.dataset.ssError) + '</div>';
                }
            } else if (r.dataset.ssReady !== '1') {
                var m2 = r.querySelector('[data-ss-menu]');
                var l2 = r.querySelector('[data-ss-list]');
                if (m2 && l2) {
                    m2.classList.remove('d-none');
                    l2.innerHTML = '<div class="ss-empty text-danger">Select did not initialise. page script runs: ' + String(window.__ssScriptRan || 0) + '</div>';
                }
            }
        }
    }, 400);
})();
</script>