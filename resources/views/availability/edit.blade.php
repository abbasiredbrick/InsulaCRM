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
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">{{ __('Column mapping') }}</h3>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addMappingRow">{{ __('Add Column') }}</button>
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
                    <div class="form-hint">{{ __('Leave empty for defaults (vacant → ready_to_list, upcoming → ready_to_list, under offer → reserved, rented → leased, sold → sold). Lines: source => destination.') }}</div>
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
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ __('How it works') }}</h3></div>
            <div class="card-body text-muted small">
                <ol class="mb-0">
                    <li>{{ __('Create one source per PM company (AMS, etc.).') }}</li>
                    <li>{{ __('Map their column layout once. Column names can be the actual header (e.g. "Unit No.") or positions (col0, col1…) when the sheet has no header.') }}</li>
                    <li>{{ __('Each time a refreshed list arrives (daily/every two days), go to Import List, upload the file or paste the text, review a preview, and run it.') }}</li>
                    <li>{{ __('Rents, deposits, fees, availability and dates update in place. Units that disappear from the sheet are automatically marked unlisted so they stop showing as available.') }}</li>
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
    const nextIndex = () => {
        if (body.querySelector('.text-muted')) return 0;
        let max = -1;
        body.querySelectorAll('[name^="column_map"]').forEach(el => {
            const m = el.name.match(/\[(\d+)\]/);
            if (m) max = Math.max(max, parseInt(m[1], 10));
        });
        return max + 1;
    };
    addRow.addEventListener('click', () => {
        if (body.querySelector('.text-muted')) body.innerHTML = '';
        const n = nextIndex();
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input type="text" class="form-control" name="column_map[${n}][source]" placeholder="{{ __('e.g. Unit No. or col1') }}"></td>
            <td><select class="form-select" name="column_map[${n}][target]">
                @include('availability._target_options', ['selected' => null])
            </select></td>
            <td><label class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="column_map[${n}][inherit]" value="1">
                <span class="form-check-label">{{ __('repeat') }}</span>
            </label></td>`;
        body.appendChild(row);
    });
});
</script>
@endsection