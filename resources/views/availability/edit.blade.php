@extends('layouts.app')

@section('title', __('Mapping — ') . $source->name)
@section('page-title', __('Availability Source Mapping'))

@section('content')
<div class="row">
    <div class="col-lg-7">
        <form method="POST" action="{{ route('availability-sources.update', $source) }}">
            @csrf
            @method('PUT')

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('Source') }}</h3></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Name') }}</label>
                            <input type="text" name="name" class="form-control" value="{{ old('name', $source->name) }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Contact info') }}</label>
                            <input type="text" name="contact_info" class="form-control" value="{{ old('contact_info', $source->contact_info) }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Public availability link') }}</label>
                            <input type="url" name="url" class="form-control" value="{{ old('url', $source->url) }}" placeholder="{{ __('https://rdk.ae/Listing/data.json') }}">
                            <div class="form-hint">{{ __('When set, a "Sync now" button appears below — it fetches the URL, imports only the published units, updates in place and handles decisions the same way as file imports.') }}</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default building') }}</label>
                            <input type="text" name="default_building" class="form-control" value="{{ old('default_building', $source->default_building) }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default category') }}</label>
                            <select name="default_category" class="form-select">
                                <option value="">{{ __('— detect from text —') }}</option>
                                @foreach(\App\Models\Property::CATEGORIES as $key => $label)
                                    <option value="{{ $key }}" {{ old('default_category', $source->default_category) === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default city') }}</label>
                            <input type="text" name="default_city" class="form-control" value="{{ old('default_city', $source->default_city ?? 'Abu Dhabi') }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default deposit (AED)') }}</label>
                            <input type="number" name="default_deposit" min="0" step="0.01" class="form-control" value="{{ old('default_deposit', $source->default_deposit) }}">
                            <div class="form-hint">{{ __('Fixed amount, or leave blank to use a formula.') }}</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Deposit % of annual rent') }}</label>
                            <input type="number" name="default_deposit_pct" min="0" max="100" step="0.01" class="form-control" value="{{ old('default_deposit_pct', $source->default_deposit_pct) }}">
                            <div class="form-hint">{{ __('e.g. 5 → max(min, 5% × rent) per unit.') }}</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Minimum deposit (AED)') }}</label>
                            <input type="number" name="default_deposit_min" min="0" step="0.01" class="form-control" value="{{ old('default_deposit_min', $source->default_deposit_min) }}">
                            <div class="form-hint">{{ __('e.g. 5000 → "AED 5,000/- or 5%, whichever is higher".') }}</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default admin fee (AED)') }}</label>
                            <input type="number" name="default_admin_fee" min="0" step="0.01" class="form-control" value="{{ old('default_admin_fee', $source->default_admin_fee) }}">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">{{ __('Default Tawtheeq fee (AED)') }}</label>
                            <input type="number" name="default_tawtheeq_fee" min="0" step="0.01" class="form-control" value="{{ old('default_tawtheeq_fee', $source->default_tawtheeq_fee) }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('How the sheet is laid out') }}</h3></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('Column separator') }}</label>
                            <select name="delimiter" class="form-select">
                                @foreach(['multi_space' => 'Spaces (PDF/email columns)', 'tab' => 'Tab', 'comma' => 'Comma', 'semicolon' => 'Semicolon', 'auto' => 'Auto-detect'] as $key => $label)
                                    <option value="{{ $key }}" {{ old('delimiter', $source->parse_options['delimiter'] ?? 'multi_space') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                            <div class="form-hint">{{ __('For pasted text and PDF exports. Excel uploads ignore this.') }}</div>
                        </div>
                        <div class="col-md-6 mb-3 mt-4">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="has_header" value="1" {{ old('has_header', $source->parse_options['has_header'] ?? false) ? 'checked' : '' }}>
                                <span class="form-check-label">{{ __('First row is a header row') }}</span>
                            </label>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('When a tracked unit leaves the list') }}</label>
                            <select name="missing_status" class="form-select">
                                <option value="leased" {{ old('missing_status', $source->missing_status ?: 'leased') === 'leased' ? 'selected' : '' }}>{{ __('Mark as leased (rented)') }}</option>
                                <option value="unlisted" {{ old('missing_status', $source->missing_status ?: 'leased') === 'unlisted' ? 'selected' : '' }}>{{ __('Mark as unlisted (off the market)') }}</option>
                            </select>
                            <div class="form-hint">{{ __('Sheets: dropped = rented. Published links (e.g. RDK): hidden = off the market, use Unlisted.') }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">{{ __('Column mapping') }}</h3>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addMappingRow">{{ __('Add Column') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-success" id="relevatePreset">{{ __('Load Relevate layout') }}</button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table" id="mappingTable">
                        <thead>
                            <tr>
                                <th style="width:35%">{{ __('Column in their sheet') }}</th>
                                <th style="width:45%">{{ __('Maps to') }}</th>
                                <th style="width:20%">{{ __('Carry forward') }}</th>
                            </tr>
                        </thead>
                        <tbody id="mappingBody">
                            @forelse($source->column_map ?? [] as $header => $target)
                            <tr>
                                <td><input type="text" class="form-control" name="column_map[{{ $loop->index }}][source]" value="{{ $header }}"></td>
                                <td>
                                    <select class="form-select" name="column_map[{{ $loop->index }}][target]">
                                        @include('availability._target_options', ['selected' => $target])
                                    </select>
                                </td>
                                <td>
                                    <label class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="column_map[{{ $loop->index }}][inherit]" value="1" {{ in_array($header, $source->parse_options['inherit_columns'] ?? []) ? 'checked' : '' }}>
                                        <span class="form-check-label">{{ __('repeat') }}</span>
                                    </label>
                                </td>
                            </tr>
                            @empty
                            <tr class="text-muted"><td colspan="3">{{ __('No mapping yet — add the columns below.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-body">
                    <div class="form-hint">
                        @include('availability._target_hint')
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('Status mapping') }}</h3></div>
                <div class="card-body">
                    <label class="form-label">{{ __('Their status word → CRM availability') }}</label>
                    @php
                        $statusLines = collect($source->status_map ?? [])
                            ->map(fn ($v, $k) => $k . ' => ' . $v)
                            ->implode("\n");
                    @endphp
                    <textarea name="status_map" rows="6" class="form-control font-monospace" placeholder="Vacant => ready_to_list&#10;Up-coming => ready_to_list&#10;Under Offer => reserved&#10;Rented => leased">{{ old('status_map', $statusLines) }}</textarea>
                    <div class="form-hint">{{ __('Leave empty for defaults (vacant → ready_to_list, upcoming → upcoming, under offer → reserved, rented → leased, sold → sold). Lines: source => destination.') }}</div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button class="btn btn-primary">{{ __('Save Mapping') }}</button>
                <a href="{{ route('availability-sources.import', $source) }}" class="btn btn-outline-secondary">{{ __('Import a List') }}</a>
                <a href="{{ route('availability-sources.index') }}" class="btn btn-link">{{ __('Back') }}</a>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        @if($source->url)
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('Sync from URL') }}</h3></div>
                <div class="card-body">
                    <div class="text-muted small mb-3">
                        {{ __('Fetches :url and imports the units it currently publishes — nothing is duplicated, updated units change in place, and listed units it no longer shows go to the decision queue instead of being unlisted.', ['url' => $source->url]) }}
                    </div>
                    <form method="POST" action="{{ route('availability-sources.sync-url', $source) }}">
                        @csrf
                        <button class="btn btn-primary w-100">{{ __('Sync now') }}</button>
                    </form>
                </div>
            </div>
        @endif
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('How it works') }}</h3></div>
            <div class="card-body text-muted small">
                <ol class="mb-0">
                    <li>{{ __('Create one source per PM company (AMS, etc.).') }}</li>
                    <li>{{ __('Map their column layout once. Column names can be the actual header (e.g. "Unit No.") or positions (col0, col1…) when the sheet has no header.') }}</li>
                    <li>{{ __('Each time a refreshed list arrives (daily/every two days), go to Import List, upload the file or paste the text, review a preview, and run it.') }}</li>
                    <li>{{ __('Rents, deposits, fees, availability and dates update in place. Units that disappear from the sheet are automatically marked unlisted so they stop showing as available — except units you have listed live on the portals, which keep their listing until an agent/manager decides to keep or unlist them (the madhmoun permit is costly to re-issue).') }}</li>
                </ol>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Recent imports') }}</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr><th>{{ __('Date') }}</th><th>{{ __('File') }}</th><th>{{ __('Result') }}</th></tr>
                    </thead>
                    <tbody>
                        @forelse($source->runs->take(10) as $run)
                        <tr>
                            <td>{{ $run->created_at->format('d M H:i') }}</td>
                            <td class="text-truncate" style="max-width:140px">{{ $run->filename }}</td>
                            <td>
                                @if($run->status === 'completed')
                                    <span class="text-success">{{ $run->created_rows }} / {{ $run->updated_rows }} / {{ $run->missing_rows }}</span>
                                @elseif($run->status === 'failed')
                                    <span class="text-danger">{{ __('failed') }}</span>
                                @else
                                    {{ $run->status }}
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-muted">{{ __('No imports yet') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.getElementById('mappingBody');
    const addRow = document.getElementById('addMappingRow');
    const relevatePreset = document.getElementById('relevatePreset');
    const TARGETS = {
        'unit_no': 'Unit No.',
        'building': 'Building',
        'features': 'Unit features (beds, size, view, kitchen)',
        'rent': 'Rent (AED)',
        'deposit': 'Deposit (AED)',
        'admin_fee': 'Admin fee (AED)',
        'tawtheeq': 'Tawtheeq fee (AED)',
        'status': 'Status word (Vacant / Up-coming…)',
        'parking': 'Parking',
        'balcony': 'Balcony (Yes/No)',
        'view': 'View (sea, community…)',
        'key_date': 'Key / vacant date',
        'available_from': 'Availability date',
        'amenities': 'Amenities / facilities',
        'remarks': 'Remarks & commission',
        'community': 'Community / area',
        'city': 'City',
        'bedrooms': 'Bedrooms',
        'bathrooms': 'Bathrooms',
        'square_footage': 'Square footage',
        'furnishing': 'Furnishing',
        'property_category': 'Category',
        'handover_date': 'Handover date',
        'notes': 'Notes',
    };
    const createRow = (source, target) => {
        const n = Math.floor(Date.now() % 100000) + Math.floor(Math.random() * 1000);
        const opts = Object.entries(TARGETS).map(([v, l]) =>
            `<option value="${v}" ${v === target ? 'selected' : ''}>${l}</option>`).join('');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control" name="column_map[${n}][source]" value="${source}"></td>
            <td><select class="form-select" name="column_map[${n}][target]">${opts}</select></td>
            <td><label class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="column_map[${n}][inherit]" value="1">
                <span class="form-check-label">{{ __('repeat') }}</span>
            </label></td>`;
        return tr;
    };
    addRow.addEventListener('click', () => {
        if (body.querySelector('.text-muted')) body.innerHTML = '';
        body.appendChild(createRow('', ''));
    });
    relevatePreset.addEventListener('click', () => {
        if (! confirm('{{ __("Replace the column mapping, status map and default fees with the Relevate (Burj Al Shams) layout?") }}')) return;
        body.innerHTML = '';
        [
            ['Unit No', 'unit_no'],
            ['Area (Sqft)', 'square_footage'],
            ['Unit Type', 'features'],
            ['Balcony', 'balcony'],
            ['View', 'view'],
            ['Status', 'status'],
            ['Expected vacating date', 'available_from'],
            ['Listing price', 'rent'],
        ].forEach(([src, tgt]) => body.appendChild(createRow(src, tgt)));
        const set = (name, value) => { const el = document.querySelector(`[name="${name}"]`); if (el) el.value = value; };
        const setCheck = (name, on) => { const el = document.querySelector(`[name="${name}"]`); if (el) el.checked = on; };
        set('default_building', 'Burj Al Shams');
        set('default_deposit_pct', '5');
        set('default_deposit_min', '5000');
        set('default_admin_fee', '1050');
        set('default_tawtheeq_fee', '150');
        set('delimiter', 'comma');
        setCheck('has_header', true);
        const sm = document.querySelector('[name="status_map"]');
        if (sm) sm.value = 'Available for viewing => ready_to_list\nUpcoming => upcoming';
    });
});
</script>
@endsection