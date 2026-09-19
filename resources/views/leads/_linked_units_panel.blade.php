@if(($businessMode ?? 'wholesale') === 'realestate')
<div class="card mb-3" id="linked-units-panel">
    <div class="card-header">
        <h3 class="card-title">{{ __('Linked Inventory Units') }}</h3>
    </div>
    <div class="card-body">
        @if($lead->properties->isNotEmpty())
            <div class="list-group mb-3">
                @foreach($lead->properties as $unit)
                    <div class="list-group-item d-flex align-items-center gap-2">
                        <a href="{{ route('inventory.show', $unit) }}" class="flex-fill text-decoration-none">
                            <span class="fw-bold">{{ $unit->display_name }}</span>
                            <span class="d-block text-muted small">
                                {{ $unit->sub_community ?: $unit->community }}{{ $unit->community && $unit->sub_community ? ', ' . $unit->community : '' }}
                                • {{ $unit->price_line }}
                            </span>
                        </a>
                        <span class="badge bg-azure-lt">{{ __(\App\Models\Property::AVAILABILITIES[$unit->availability] ?? $unit->availability) }}</span>
                        <form method="POST" action="{{ route('leads.property.unlink', [$lead, $unit]) }}" class="d-inline">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger"
                                onclick="return confirm('{{ __('Unlink this unit from the lead?') }}')">{{ __('Unlink') }}</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-secondary">{{ __('No units linked yet.') }}</p>
        @endif

        <div class="row g-2 mb-3">
            <div class="col-md-6">
                <label for="lp-search-q" class="visually-hidden">{{ __('Search inventory') }}</label>
                <input type="text" id="lp-search-q" class="form-control" placeholder="{{ __('Type to search inventory and link...') }}" autocomplete="off">
            </div>
            <div class="col-md-3">
                <select id="lp-search-intent" class="form-select" aria-label="{{ __('Intent') }}">
                    <option value="">{{ __('All intents') }}</option>
                    @foreach(\App\Models\Property::INTENTS as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select id="lp-search-category" class="form-select" aria-label="{{ __('Category') }}">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div id="lp-results" class="list-group" style="max-height:360px; overflow:auto;"></div>
        <div id="lp-count" class="small text-secondary mt-2"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const panel = document.getElementById('linked-units-panel');
    if (! panel) { return; }

    const resultsEl = document.getElementById('lp-results');
    const countEl = document.getElementById('lp-count');

    function renderCount(total) {
        if (! countEl) { return; }
        countEl.textContent = total + ' ' + (total === 1 ? '{{ __('unit found') }}' : '{{ __('units found') }}');
    }

    function renderRows(units) {
        resultsEl.innerHTML = '';
        if (! units.length) {
            resultsEl.innerHTML = '<div class="list-group-item text-muted">{{ __('No matching units found.') }}</div>';
            return;
        }
        units.forEach(u => {
            const row = document.createElement('form');
            row.method = 'POST';
            row.action = '{{ route('leads.property.link', $lead) }}';
            row.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2 m-0';

            const token = document.createElement('input');
            token.type = 'hidden';
            token.name = '_token';
            token.value = '{{ csrf_token() }}';

            const idField = document.createElement('input');
            idField.type = 'hidden';
            idField.name = 'property_id';
            idField.value = u.id;

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

            const btn = document.createElement('button');
            btn.type = 'submit';
            btn.className = 'btn btn-sm btn-primary';
            btn.textContent = '{{ __('Link') }}';

            row.appendChild(token);
            row.appendChild(idField);
            row.appendChild(span);
            row.appendChild(badge);
            row.appendChild(btn);
            resultsEl.appendChild(row);
        });
    }

    function doSearch() {
        const params = new URLSearchParams();
        const q = document.getElementById('lp-search-q').value.trim();
        const intent = document.getElementById('lp-search-intent').value;
        const category = document.getElementById('lp-search-category').value;
        if (q) { params.set('q', q); }
        if (intent) { params.set('intent', intent); }
        if (category) { params.set('category', category); }

        fetch('{{ route('inventory.search') }}' + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => { renderRows(data.units); renderCount(data.total); })
            .catch(() => { renderRows([]); renderCount(0); });
    }

    // Live search while typing, debounced like the rest of the app.
    let searchTimer = null;
    document.getElementById('lp-search-q').addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 350);
    });
    document.getElementById('lp-search-q').addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });
    document.getElementById('lp-search-intent').addEventListener('change', doSearch);
    document.getElementById('lp-search-category').addEventListener('change', doSearch);
})();
</script>
@endpush
@endif