@extends('layouts.app')
@section('title', __('Portal API Import'))
@section('page-title', __('Portal API Import'))

@section('content')
@php
    $statusColors = [
        'queued' => 'secondary',
        'running' => 'blue',
        'ready' => 'green',
        'ready_with_errors' => 'yellow',
        'completed' => 'green',
        'completed_with_errors' => 'yellow',
        'failed' => 'red',
        'cancelled' => 'dark',
    ];
    $isActive = in_array($run->status, ['queued', 'running'], true);
    $canConfirm = $run->mode === 'preview' && in_array($run->status, ['ready', 'ready_with_errors'], true);
@endphp

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div>
                    <h3 class="card-title">{{ $portals[$run->portal] ?? $run->portal }}</h3>
                    <div class="text-muted small">
                        {{ $run->mode === 'preview' ? __('Preview') : __('Confirmed import') }} · {{ $run->criteria['date_from'] }} to {{ $run->criteria['date_to'] }}
                    </div>
                </div>
                <span class="badge bg-{{ $statusColors[$run->status] ?? 'secondary' }}" data-import-status data-status="{{ $run->status }}">{{ $statuses[$run->status] ?? $run->status }}</span>
            </div>
            <div class="card-body">
                @if ($run->status === 'queued')
                    <div class="alert alert-secondary mb-0">{{ __('This pull is waiting for a queue worker.') }}</div>
                @elseif ($run->status === 'running')
                    <div class="alert alert-info mb-0">{{ __('The portal history is being read. This page refreshes automatically.') }}</div>
                @elseif ($canConfirm)
                    <div class="alert alert-success mb-0">{{ __('Preview complete. Review the counts and warnings before importing.') }}</div>
                @elseif ($run->status === 'failed')
                    <div class="alert alert-danger mb-0">{{ __('The pull failed before it could complete.') }}</div>
                @elseif ($run->status === 'cancelled')
                    <div class="alert alert-secondary mb-0">{{ __('This pull was cancelled.') }}</div>
                @elseif ($run->status === 'completed_with_errors' || $run->status === 'ready_with_errors')
                    <div class="alert alert-warning mb-0">{{ __('The pull completed with warnings. Review them below.') }}</div>
                @else
                    <div class="alert alert-success mb-0">{{ __('The pull completed.') }}</div>
                @endif
            </div>
        </div>

        <div class="row g-3 mt-1">
            @php
                $cards = [
                    ['label' => $run->mode === 'preview' ? __('Would add') : __('Added'), 'value' => $run->imported_count, 'color' => 'green'],
                    ['label' => __('Already active'), 'value' => $run->already_active_count, 'color' => 'cyan'],
                    ['label' => __('Duplicates'), 'value' => $run->duplicate_count, 'color' => 'blue'],
                    ['label' => __('Outside range'), 'value' => $run->out_of_range_count, 'color' => 'secondary'],
                    ['label' => __('No contact'), 'value' => $run->skipped_no_contact_count, 'color' => 'yellow'],
                    ['label' => __('Invalid / undated'), 'value' => $run->skipped_invalid_count, 'color' => 'red'],
                ];
            @endphp
            @foreach ($cards as $card)
                <div class="col-6 col-md-4">
                    <div class="card card-sm">
                        <div class="card-body">
                            <div class="text-muted small">{{ $card['label'] }}</div>
                            <div class="h2 mb-0 mt-1">{{ number_format($card['value']) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Actions') }}</h3></div>
            <div class="card-body">
                @if ($canConfirm)
                    <form method="POST" action="{{ route('recycled.portal-import.confirm', $run) }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-primary w-100">{{ __('Import Previewed Leads') }}</button>
                    </form>
                @endif

                @if ($isActive)
                    <form method="POST" action="{{ route('recycled.portal-import.cancel', $run) }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger w-100">{{ __('Cancel Pull') }}</button>
                    </form>
                @endif

                @if (! $isActive)
                    <a href="{{ route('recycled.index', ['portal' => $run->portal, 'source' => 'api_import', 'date_from' => $run->criteria['date_from'], 'date_to' => $run->criteria['date_to']]) }}" class="btn btn-outline-primary w-100 mb-3">
                        {{ __('View Imported Pool Records') }}
                    </a>
                @endif

                <a href="{{ route('recycled.portal-import.create') }}" class="btn btn-outline-secondary w-100 mb-3">{{ __('Start Another Pull') }}</a>
                <a href="{{ route('recycled.index') }}" class="btn btn-link w-100">{{ __('Back to Recycled Leads') }}</a>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">{{ __('Pull Summary') }}</h3></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5">{{ __('Portal') }}</dt><dd class="col-7">{{ $portals[$run->portal] ?? $run->portal }}</dd>
                    <dt class="col-5">{{ __('Records read') }}</dt><dd class="col-7">{{ number_format($run->observed_count) }}</dd>
                    <dt class="col-5">{{ __('Contactable') }}</dt><dd class="col-7">{{ number_format($run->contactable_count) }}</dd>
                    <dt class="col-5">{{ __('Enriched existing') }}</dt><dd class="col-7">{{ number_format($run->enriched_count) }}</dd>
                    <dt class="col-5">{{ __('Warnings') }}</dt><dd class="col-7">{{ number_format($run->error_count) }}</dd>
                    <dt class="col-5">{{ __('Started') }}</dt><dd class="col-7">{{ $run->started_at?->format('M j, Y H:i') ?? '—' }}</dd>
                    <dt class="col-5">{{ __('Finished') }}</dt><dd class="col-7">{{ $run->completed_at?->format('M j, Y H:i') ?? '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

@if ($run->errors)
    <div class="card mt-3">
        <div class="card-header"><h3 class="card-title">{{ __('Warnings') }}</h3></div>
        <div class="card-body">
            <ul class="mb-0">
                @foreach ($run->errors as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const status = document.querySelector('[data-import-status]');
    if (status && ['queued', 'running'].includes(status.dataset.status)) {
        window.setTimeout(() => window.location.reload(), 3000);
    }
});
</script>
@endpush
