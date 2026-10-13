@props([
    'count' => null,
    'records' => null,
    'label' => null,
    'total' => null,
])

@php
    // Accept either an explicit number or a paginator / collection / array.
    $n = $count;
    if ($n === null && $records !== null) {
        $n = $records instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? $records->total()
            : (is_countable($records) ? count($records) : 0);
    }
    $n = (int) ($n ?? 0);

    // A "filtered" hint appears whenever the request carries anything beyond
    // paging / sorting, so the number is shown as the search result count.
    $activeFilters = collect(request()->query())
        ->except(['page', 'sort', 'direction', 'density', 'per_page', 'tab', 'view'])
        ->filter(fn ($value) => $value !== null && $value !== '')
        ->isNotEmpty();

    $label = $label ?? __('records');
@endphp

<div class="d-flex align-items-center gap-2 px-3 py-2 small text-secondary" data-record-count>
    <span>{{ __('Showing') }} <span class="fw-bold text-body">{{ number_format($n) }}</span> {{ $label }}</span>
    @if($total !== null && (int) $total !== $n)
        <span>{{ __('of') }} {{ number_format((int) $total) }}</span>
    @endif
    @if($activeFilters)
        <span class="badge bg-azure-lt">{{ __('Filtered') }}</span>
    @endif
</div>
