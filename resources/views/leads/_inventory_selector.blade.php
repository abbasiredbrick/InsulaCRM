@if(($businessMode ?? 'wholesale') === 'realestate')
<div class="card mb-3" id="inventory-linker">
    <div class="card-header">
        <h3 class="card-title">{{ __('Link Inventory Units') }}</h3>
    </div>
    <div class="card-body">
        <p class="text-secondary small">{{ __('Search your inventory (community, size, price, unit...) and link the units this lead owns or is interested in.') }}</p>
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label for="inv-search-q" class="visually-hidden">{{ __('Search inventory') }}</label>
                <input type="text" id="inv-search-q" class="form-control" placeholder="{{ __('Search inventory...') }}">
            </div>
            <div class="col-md-3">
                <select id="inv-search-intent" class="form-select" aria-label="{{ __('Intent') }}">
                    <option value="">{{ __('All intents') }}</option>
                    @foreach(\App\Models\Property::INTENTS as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select id="inv-search-category" class="form-select" aria-label="{{ __('Category') }}">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button type="button" class="btn btn-outline-primary" id="inv-search-btn">{{ __('Search') }}</button>
            </div>
        </div>

        <div class="mb-3">
            <span class="text-secondary">{{ __('Selected') }}:</span>
            <div id="inv-selected" class="d-flex flex-wrap gap-2 mt-1"></div>
            <div id="inv-hidden" class="d-none"></div>
        </div>

        <div id="inv-results" class="list-group" style="max-height:420px; overflow:auto;">
            @php $checkedIds = old('linked_units', $selectedUnitIds); @endphp
            @forelse($inventoryUnits as $unit)
                <label class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <input type="checkbox" class="form-check-input inv-check" value="{{ $unit->id }}" {{ in_array($unit->id, $checkedIds) ? 'checked' : '' }}>
                    <span class="flex-fill">
                        <span class="fw-bold">{{ $unit->display_name }}</span>
                        <span class="d-block text-muted small">
                            {{ $unit->sub_community ?: $unit->community }}{{ $unit->community && $unit->sub_community ? ', ' . $unit->community : '' }}
                            • {{ $unit->price_line }}
                        </span>
                    </span>
                    <span class="badge bg-azure-lt">{{ __($unit->availability_label) }}</span>
                </label>
            @empty
                <div class="list-group-item text-muted">{{ __('No inventory yet. Add units under Inventory first.') }}</div>
            @endforelse
        </div>
        <div id="inv-count" class="small text-secondary mt-2"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const container = document.getElementById('inventory-linker');
    if (! container) { return; }

    const selectedIds = new Set();
    container.querySelectorAll('.inv-check:checked').forEach(cb => selectedIds.add(parseInt(cb.value, 10)));

    const hiddenBox = document.getElementById('inv-hidden');
    const selectedBox = document.getElementById('inv-selected');
    const resultsEl = document.getElementById('inv-results');
    const countEl = document.getElementById('inv-count');

    function renderCount(total) {
        if (! countEl) { return; }
        countEl.textContent = total + ' ' + (total === 1 ? '{{ __('unit found') }}' : '{{ __('units found') }}');
    }

    function labelFor(id) {
        const cb = container.querySelector('.inv-check[value="' + id + '"]');
        return cb ? cb.closest('label').querySelector('.fw-bold').textContent : ('#' + id);
    }

    function syncSelected() {
        hiddenBox.innerHTML = '';
        selectedBox.innerHTML = '';

        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'linked_units[]';
            input.value = id;
            hiddenBox.appendChild(input);

            const chip = document.createElement('span');
            chip.className = 'badge bg-cyan-lt';
            chip.textContent = labelFor(id);
            selectedBox.appendChild(chip);
        });

        if (! selectedIds.size) {
            selectedBox.textContent = '—';
        }
    }

    container.addEventListener('change', e => {
        if (e.target.classList && e.target.classList.contains('inv-check')) {
            const id = parseInt(e.target.value, 10);
            if (e.target.checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
            syncSelected();
        }
    });

    function renderRows(units) {
        resultsEl.innerHTML = '';
        if (! units.length) {
            resultsEl.innerHTML = '<div class="list-group-item text-muted">{{ __('No matching units found.') }}</div>';
            return;
        }
        units.forEach(u => {
            const label = document.createElement('label');
            label.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2';

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'form-check-input inv-check';
            cb.value = u.id;
            cb.checked = selectedIds.has(u.id);

            const span = document.createElement('span');
            span.className = 'flex-fill';

            const title = document.createElement('span');
            title.className = 'fw-bold';
            title.textContent = u.label;

            const meta = document.createElement('span');
            meta.className = 'd-block text-muted small';
            meta.textContent = u.detail ? (u.detail + ' • ' + u.meta) : u.meta;

            span.appendChild(title);
            span.appendChild(meta);

            const badge = document.createElement('span');
            badge.className = 'badge bg-azure-lt';
            badge.textContent = u.availability;

            label.appendChild(cb);
            label.appendChild(span);
            label.appendChild(badge);
            resultsEl.appendChild(label);
        });
    }

    function buildParams() {
        const params = new URLSearchParams();
        const q = document.getElementById('inv-search-q').value.trim();
        const intent = document.getElementById('inv-search-intent').value;
        const category = document.getElementById('inv-search-category').value;
        if (q) { params.set('q', q); }
        if (intent) { params.set('intent', intent); }
        if (category) { params.set('category', category); }
        return params;
    }

    let searchTimer = null;
    let searchController = null;
    let searchSeq = 0;

    function runSearch() {
        const params = buildParams();
        const btn = document.getElementById('inv-search-btn');
        btn.disabled = true;

        const token = ++searchSeq;
        clearTimeout(searchTimer);
        if (searchController) { searchController.abort(); }
        searchController = new AbortController();

        fetch('{{ route('inventory.search') }}' + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            signal: searchController.signal
        })
            .then(r => r.json())
            .then(data => { if (token === searchSeq) { renderRows(data.units); renderCount(data.total); } })
            .catch(err => { if (err && err.name === 'AbortError') return; if (token === searchSeq) { renderRows([]); renderCount(0); } })
            .finally(() => { if (token === searchSeq) { btn.disabled = false; } });
    }

    function debounceSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 350);
    }

    document.getElementById('inv-search-btn').addEventListener('click', runSearch);
    document.getElementById('inv-search-q').addEventListener('input', debounceSearch);
    document.getElementById('inv-search-intent').addEventListener('change', debounceSearch);
    document.getElementById('inv-search-category').addEventListener('change', debounceSearch);
    document.getElementById('inv-search-q').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
    });

    renderCount(resultsEl.querySelectorAll('.inv-check').length);

    syncSelected();
})();
</script>
@endpush
@endif