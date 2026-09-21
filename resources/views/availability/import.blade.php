@extends('layouts.app')

@section('title', __('Import List — ') . $source->name)
@section('page-title', __('Import Availability List'))

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-9">
        @if($source->column_map && count($source->column_map))
        <div class="card mb-3">
            <div class="card-header">
                <div class="d-flex justify-content-between w-100 align-items-center">
                    <h3 class="card-title mb-0">{{ __('Quick Re-import') }}</h3>
                    <span class="badge bg-azure-lt">{{ $source->name }}</span>
                </div>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    {{ __('Upload the refreshed sheet — it is parsed with your saved mapping (delimiter, header, columns) and run immediately. Existing units are updated in place, nothing is duplicated, and any listed unit the sheet shows as leased goes to the decision queue instead of being unlisted.') }}
                </p>
                <form method="POST" action="{{ route('availability-sources.import-direct', $source) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-2 align-items-center">
                        <div class="col-md-7">
                            <input type="file" name="file" class="form-control" accept=".xlsx,.csv,.txt" required>
                            @error('file') <div class="text-danger small">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-5 d-flex gap-2">
                            <button class="btn btn-primary">{{ __('Re-import Now') }}</button>
                            @if($source->latestRun)
                            <span class="text-muted small align-self-center">{{ __('Last: ') }}{{ $source->latestRun->created_at->format('d M H:i') }}</span>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        </div>
        @endif

        <div class="card mb-3">
            <div class="card-header">
                <div class="d-flex justify-content-between w-100 align-items-center">
                    <h3 class="card-title mb-0">{{ $source->name }}</h3>
                    <a href="{{ route('availability-sources.edit', $source) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit Mapping') }}</a>
                </div>
            </div>
            <div class="card-body">
                <ul class="text-muted small mb-3">
                    <li>{{ __('Upload an .xlsx, .csv or .txt you received by email, or paste the text straight from the PDF/email.') }}</li>
                    <li>{{ __('You preview the parsed rows before anything touches the inventory.') }}</li>
                    <li>{{ __('Units already on the sheet are updated in place; ones that left the sheet become unlisted.') }}</li>
                </ul>

                <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="small">{{ __('Preparing the sheet yourself? Use the sample that matches this source\'s mapping, then save it as CSV.') }}</div>
                    <div class="text-nowrap">
                        <a href="{{ route('availability-sources.sample', $source) }}" class="btn btn-sm btn-outline-primary">{{ __('Download sample CSV') }}</a>
                        <a href="{{ route('availability-sources.guide') }}" class="btn btn-sm btn-link">{{ __('Import guide') }}</a>
                    </div>
                </div>

                <form method="POST" action="{{ route('availability-sources.import-parse', $source) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label">{{ __('File (optional)') }}</label>
                        <input type="file" name="file" class="form-control" accept=".xlsx,.csv,.txt">
                        @error('file') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">{{ __('…or paste the list text') }}</label>
                        <textarea name="pasted" rows="10" class="form-control font-monospace" placeholder="Paste the availability rows here (e.g. the text of the PDF/email)…">{{ old('pasted', $source->parse_options && !session()->has('errors') ? '' : '') }}</textarea>
                        @error('pasted') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Column separator') }}</label>
                            <select name="delimiter" class="form-select">
                                @foreach(['multi_space' => 'Spaces (PDF/email columns)', 'tab' => 'Tab', 'comma' => 'Comma', 'semicolon' => 'Semicolon'] as $key => $label)
                                    <option value="{{ $key }}" {{ old('delimiter', $source->parse_options['delimiter'] ?? 'multi_space') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4 mt-4">
                            <label class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="has_header" value="1" {{ old('has_header', $source->parse_options['has_header'] ?? false) ? 'checked' : '' }}>
                                <span class="form-check-label">{{ __('First row is a header row') }}</span>
                            </label>
                        </div>
                    </div>

                    <button class="btn btn-primary">{{ __('Parse & Preview') }}</button>
                    <a href="{{ route('availability-sources.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                </form>
            </div>
        </div>

        @if($source->column_map && count($source->column_map))
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Current mapping') }}</h3></div>
            <div class="card-body">
                <div class="row">
                    @foreach($source->column_map as $header => $target)
                    <div class="col-lg-6">
                        <code>{{ $header }}</code> <span class="text-muted">→</span> <strong>{{ $target }}</strong>
                        @if(in_array($header, $source->parse_options['inherit_columns'] ?? []))
                            <span class="badge bg-azure-lt ms-1">{{ __('fills down') }}</span>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection