@extends('layouts.app')

@section('title', __('Availability Lists'))
@section('page-title', __('Availability Lists'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-0">{{ __('Property-management companies (AMS, etc.) share availability sheets in Excel, CSV, PDFs or emails. Import each one here and it keeps units in sync — new units are added, changed units updated, and units that leave the sheet are marked unlisted.') }}</p>
    </div>
    <a href="{{ route('availability-sources.create') }}" class="btn btn-primary">{{ __('Add Source') }}</a>
</div>

@if($sources->isEmpty())
<div class="empty">
    <div class="empty-img"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="64" height="64" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2"/><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z"/><path d="M9 17v-4"/><path d="M15 17v-2"/></svg></div>
    <p class="empty-title">{{ __('No sources yet') }}</p>
    <p class="empty-subtitle text-muted">{{ __('Add a PM company and define its column layout once, then import refreshed sheets anytime.') }}</p>
    <div class="empty-action">
        <a href="{{ route('availability-sources.create') }}" class="btn btn-primary">{{ __('Add Source') }}</a>
    </div>
</div>
@else
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Source') }}</th>
                    <th>{{ __('Units on sheet') }}</th>
                    <th>{{ __('Last import') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($sources as $source)
                <tr>
                    <td>
                        <div class="fw-bold">{{ $source->name }}</div>
                        <div class="text-muted small">
                            {{ $source->contact_info ?: __('(no contact info)') }}
                            @if($source->default_building) · {{ $source->default_building }} @endif
                        </div>
                    </td>
                    <td>{{ $source->properties_count }}</td>
                    <td>
                        @if($source->last_imported_at)
                            <div>{{ $source->last_imported_at->format('d M Y, H:i') }}</div>
                            @if($source->latestRun)
                                <div class="text-muted small">
                                    {{ __('+') }}{{ $source->latestRun->created_rows }} {{ __('new') }} ·
                                    {{ $source->latestRun->updated_rows }} {{ __('updated') }} ·
                                    {{ $source->latestRun->missing_rows }} {{ __('unlisted') }}
                                </div>
                            @endif
                        @else
                            <span class="text-muted">{{ __('Never imported') }}</span>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('availability-sources.import', $source) }}" class="btn btn-sm btn-primary">{{ __('Import List') }}</a>
                        <a href="{{ route('availability-sources.edit', $source) }}" class="btn btn-sm btn-outline-secondary">{{ __('Mapping') }}</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection