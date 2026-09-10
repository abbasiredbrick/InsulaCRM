@extends('layouts.app')

@section('title', __('Review Import — ') . $source->name)
@section('page-title', __('Review Import'))

@section('content')
@php
    $unmapped = array_diff($preview['header'], array_keys($columnMap));
    $sample = $sample;
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">{{ $source->name }}</h3>
        <span class="text-muted">
            {{ $counts['total'] }} {{ __('rows parsed ·') }}
            <span class="{{ $counts['mapped'] ? 'text-success' : 'text-danger' }}">{{ $counts['mapped'] }} {{ __('mappable') }}</span>
        </span>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('availability-sources.run', $source) }}" onsubmit="return confirm('{{ __('Run import now? This updates inventory and unlists units missing from the sheet.') }}')">
            @csrf
            <button class="btn btn-primary" {{ $counts['mapped'] ? '' : 'disabled' }}>{{ __('Run Import') }}</button>
        </form>
        <form method="POST" action="{{ route('availability-sources.cancel', $source) }}">
            @csrf
            <button class="btn btn-outline-secondary">{{ __('Discard') }}</button>
        </form>
    </div>
</div>

@if(!$counts['mapped'])
<div class="alert alert-danger">
    {{ __('Nothing in this sheet maps to your saved columns — check the mapping, or set the separator / header option for this list.') }}
</div>
@endif

@if(!empty($unmapped))
<div class="alert alert-warning">
    {{ __('Column(s) not used by the mapping — they will be ignored:') }}
    <code>{{ implode('</code>, <code>', $unmapped) }}</code>
</div>
@endif

<div class="card">
    <div class="card-header"><h3 class="card-title">{{ __('Parsed rows — showing first 15 of') }} {{ $counts['total'] }}</h3></div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table table-sm">
            <thead>
                <tr>
                    @foreach($preview['header'] as $key)
                        <th @if(!array_key_exists($key, $columnMap)) class="text-muted text-decoration-line-through" @endif>{{ $key }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($sample as $row)
                <tr>
                    @foreach($preview['header'] as $key)
                        <td class="whitespace-nowrap">{{ $row[$key] ?? '' }}</td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($counts['total'] > 15)
    <div class="card-footer text-muted">… {{ $counts['total'] - 15 }} {{ __('more rows in the full list') }}</div>
    @endif
</div>
@endsection