@props([
    'name' => '',
    'placeholder' => __('Select...'),
    'searchPlaceholder' => __('Type to search...'),
    'options' => [],
    'selected' => '',
    'required' => false,
    'invalid' => false,
])

<div class="searchable-select" data-ss
    data-placeholder="{{ $placeholder }}"
    data-search-placeholder="{{ $searchPlaceholder }}"
    data-selected="{{ $selected }}"
    data-options="{{ e(json_encode($options)) }}">
    <input type="text" class="form-control searchable-select-field {{ $invalid ? 'is-invalid' : '' }}" data-ss-field readonly tabindex="0"
        placeholder="{{ $placeholder }}" aria-haspopup="listbox" autocomplete="off">
    <div class="searchable-select-menu d-none" data-ss-menu>
        <input type="text" class="form-control form-control-sm searchable-select-filter" data-ss-filter placeholder="{{ $searchPlaceholder }}" autocomplete="off">
        <div class="searchable-select-list" data-ss-list role="listbox"></div>
    </div>
    <select name="{{ $name }}" class="d-none" data-ss-select {{ $required ? 'required' : '' }} aria-hidden="true">
        <option value="">{{ $placeholder }}</option>
        @foreach($options as $opt)
            <option value="{{ $opt['value'] }}" {{ (string) $opt['value'] === (string) $selected ? 'selected' : '' }}>{{ $opt['label'] }}</option>
        @endforeach
    </select>
</div>

<style>
    .searchable-select { position: relative; }
    .searchable-select .searchable-select-menu {
        position: absolute; z-index: 1050; top: 100%; left: 0; right: 0;
        margin-top: .25rem; padding: .35rem;
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
    if (window.__searchableSelects) { return; }
    window.__searchableSelects = true;

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function init(root) {
        const field = root.querySelector('[data-ss-field]');
        const menu = root.querySelector('[data-ss-menu]');
        const filter = root.querySelector('[data-ss-filter]');
        const list = root.querySelector('[data-ss-list]');
        const select = root.querySelector('[data-ss-select]');

        let options = [];
        try { options = JSON.parse(root.dataset.options || '[]'); } catch (e) { options = []; }

        function labelOf(value) {
            const found = options.find(function (o) { return String(o.value) === String(value); });
            return found ? found.label : '';
        }

        function render() {
            const q = filter.value.trim().toLowerCase();
            let html = '';
            options.forEach(function (o) {
                if (q && o.label.toLowerCase().indexOf(q) === -1) { return; }
                const selected = String(o.value) === String(select.value);
                html += '<div class="ss-item' + (selected ? ' ss-item-selected' : '') + '" data-value="' + escapeHtml(o.value) + '">' + escapeHtml(o.label) + '</div>';
            });
            if (!html) {
                list.innerHTML = '<div class="ss-empty text-muted">' + '{{ __('No matches found.') }}' + '</div>';
            } else {
                list.innerHTML = html;
            }
        }

        function setValue(value) {
            select.value = value;
            field.value = value ? labelOf(value) : '';
            close();
            root.dispatchEvent(new CustomEvent('ss-change', { detail: value }));
        }

        function open() {
            filter.value = '';
            render();
            menu.classList.remove('d-none');
            setTimeout(function () { filter.focus(); }, 0);
        }

        function close() {
            menu.classList.add('d-none');
        }

        let activeIndex = -1;

        function activate(index) {
            const items = list.querySelectorAll('.ss-item');
            if (!items.length) { return; }
            if (index < 0) { index = items.length - 1; }
            if (index >= items.length) { index = 0; }
            activeIndex = index;
            items.forEach(function (el, i) {
                el.classList.toggle('ss-item-selected', String(el.dataset.value) === String(select.value) ? i === index : false);
            });
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        field.addEventListener('click', function (e) {
            e.stopPropagation();
            if (menu.classList.contains('d-none')) { open(); } else { close(); }
        });

        field.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter') {
                e.preventDefault();
                open();
            }
        });

        filter.addEventListener('input', function () { activeIndex = -1; render(); });

        filter.addEventListener('keydown', function (e) {
            const items = list.querySelectorAll('.ss-item');
            if (e.key === 'Escape') { e.preventDefault(); close(); }
            if (e.key === 'ArrowDown') { e.preventDefault(); activate(activeIndex + 1); }
            if (e.key === 'ArrowUp') { e.preventDefault(); activate(activeIndex - 1); }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && items[activeIndex]) {
                    setValue(items[activeIndex].dataset.value);
                } else if (items.length) {
                    setValue(items[0].dataset.value);
                }
            }
        });

        list.addEventListener('click', function (e) {
            const item = e.target.closest ? e.target.closest('.ss-item') : null;
            if (item) { setValue(item.dataset.value); }
        });

        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) { close(); }
        });

        const initial = select.value;
        if (initial) { field.value = labelOf(initial); }
    }

    function boot() {
        document.querySelectorAll('[data-ss]').forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>