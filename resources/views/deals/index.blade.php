@extends('layouts.app')

@section('title', ($businessMode ?? 'wholesale') === 'realestate' ? __('Transactions') : __('Deals'))
@section('page-title', ($businessMode ?? 'wholesale') === 'realestate' ? __('All Transactions') : __('All Deals'))

@section('content')
@php $filters = request()->only(['search', 'deal_type', 'stage', 'agent', 'temp', 'source']); @endphp
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ ($businessMode ?? 'wholesale') === 'realestate' ? __('All Transactions') : __('All Deals') }}</h3>
        <div class="card-actions">
            <div class="btn-group me-2" role="group" aria-label="{{ __('View') }}">
                <a href="{{ route('deals.index', $filters) }}" class="btn btn-outline-primary btn-sm {{ ($currentView ?? null) === 'list' ? 'active' : '' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
                    {{ __('List') }}
                </a>
                <a href="{{ route('pipeline.board', $filters) }}" class="btn btn-outline-primary btn-sm {{ ($currentView ?? null) === 'board' ? 'active' : '' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="10" y="3" width="4" height="12" rx="1"/><rect x="15" y="3" width="6" height="7" rx="1"/></svg>
                    {{ __('Kanban') }}
                </a>
            </div>
            <a href="{{ route('deals.export', request()->query()) }}" class="btn btn-outline-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><polyline points="7 11 12 16 17 11"/><line x1="12" y1="4" x2="12" y2="16"/></svg>
                {{ __('Export CSV') }}
            </a>
        </div>
    </div>

    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('deals.index') }}" class="row g-2" data-live-filter>
            <div class="col-md-3">
                <label for="filter-search" class="visually-hidden">{{ __('Search') }}</label>
                <input type="text" name="search" id="filter-search" class="form-control" placeholder="{{ __('Search title, lead, phone, property...') }}" value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label for="filter-type" class="visually-hidden">{{ __('Deal type') }}</label>
                <select name="deal_type" id="filter-type" class="form-select">
                    <option value="">{{ __('All Types') }}</option>
                    <option value="rent" {{ request('deal_type') === 'rent' ? 'selected' : '' }}>{{ __('Leasing') }}</option>
                    <option value="sale" {{ request('deal_type') === 'sale' ? 'selected' : '' }}>{{ __('Sales') }}</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter-stage" class="visually-hidden">{{ __('Stage') }}</label>
                <select name="stage" id="filter-stage" class="form-select">
                    <option value="">{{ __('All Stages') }}</option>
                    @foreach($stageLabels as $stageKey => $stageLabel)
                        <option value="{{ $stageKey }}" {{ request('stage') === $stageKey ? 'selected' : '' }}>{{ $stageLabel }}</option>
                    @endforeach
                </select>
            </div>
            @if(auth()->user()->isAdmin() && $agents->count() > 1)
            <div class="col-md-2">
                <label for="filter-agent" class="visually-hidden">{{ __('Agent') }}</label>
                <select name="agent" id="filter-agent" class="form-select">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-md-2">
                <label for="filter-temp" class="visually-hidden">{{ __('Temperature') }}</label>
                <select name="temp" id="filter-temp" class="form-select">
                    <option value="">{{ __('All Temperatures') }}</option>
                    @foreach(['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold'] as $value => $label)
                        <option value="{{ $value }}" {{ request('temp') === $value ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter-source" class="visually-hidden">{{ __('Lead Source') }}</label>
                <select name="source" id="filter-source" class="form-select">
                    <option value="">{{ __('All Sources') }}</option>
                    @foreach(\App\Services\CustomFieldService::getOptions('lead_source') as $value => $label)
                        <option value="{{ $value }}" {{ request('source') === $value ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            @if(request()->hasAny(['search', 'deal_type', 'stage', 'agent', 'temp', 'source']))
            <div class="col-md-1">
                <a href="{{ route('deals.index') }}" class="btn btn-outline-secondary w-100">{{ __('Clear') }}</a>
            </div>
            @endif
        </form>
    </div>

    @php
        $currentSort = request('sort', '');
        $currentDir = request('direction', 'asc');
        $sortArrow = function ($col) use ($currentSort, $currentDir) {
            if ($currentSort !== $col) return '';
            return $currentDir === 'asc'
                ? '<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm ms-1" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M6 15l6-6l6 6"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm ms-1" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M6 9l6 6l6-6"/></svg>';
        };
        $sortUrl = function ($col) use ($currentSort, $currentDir) {
            $dir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
            return request()->fullUrlWithQuery(['sort' => $col, 'direction' => $dir]);
        };
    @endphp

    <div data-live-results>
    <div class="table-responsive">
        <table class="table table-vcenter card-table mobile-cols-3">
            <thead>
                <tr>
                    <th><a href="{{ $sortUrl('deal_type') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __('Type') }}{!! $sortArrow('deal_type') !!}</a></th>
                    <th><a href="{{ $sortUrl('title') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __('Deal') }}{!! $sortArrow('title') !!}</a></th>
                    <th><a href="{{ $sortUrl('stage') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __('Stage') }}{!! $sortArrow('stage') !!}</a></th>
                    <th>{{ __('Property') }}</th>
                    <th><a href="{{ $sortUrl('contract_price') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __('Contract') }}{!! $sortArrow('contract_price') !!}</a></th>
                    <th><a href="{{ $sortUrl('fee') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __($modeTerms['money_label']) }}{!! $sortArrow('fee') !!}</a></th>
                    <th><a href="{{ $sortUrl('agent') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __('Agent') }}{!! $sortArrow('agent') !!}</a></th>
                    <th>{{ __('Temp') }}</th>
                    <th>{{ __('In Stage') }}</th>
                    <th><a href="{{ $sortUrl('updated_at') }}" class="text-reset text-decoration-none d-inline-flex align-items-center">{{ __('Updated') }}{!! $sortArrow('updated_at') !!}</a></th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($deals as $deal)
                @php $daysInStage = $deal->stage_changed_at ? (int) now()->diffInDays($deal->stage_changed_at, true) : 0; @endphp
                <tr data-href="{{ route('deals.show', $deal) }}">
                    <td>
                        @if(($businessMode ?? 'wholesale') === 'realestate')
                        <span class="badge {{ $deal->dealType() === 'rent' ? 'bg-teal-lt' : 'bg-indigo-lt' }}">{{ $deal->dealType() === 'rent' ? __('Leasing') : __('Sales') }}</span>
                        @else
                        <span class="badge bg-secondary-lt">{{ $deal->dealType() === 'rent' ? __('Leasing') : __('Sales') }}</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('deals.show', $deal) }}" class="fw-semibold text-reset">{{ $deal->title ?? ('Deal #'.$deal->id) }}</a>
                        @if($deal->lead)
                        <div class="text-secondary small">{{ $deal->lead->full_name }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="badge bg-primary-lt">{{ \App\Models\Deal::stageLabel($deal->stage) }}</span>
                    </td>
                    <td class="text-secondary">{{ $deal->lead?->property?->address ?? '-' }}</td>
                    <td class="text-nowrap">@if($deal->contract_price){{ Fmt::currency($deal->contract_price, 0) }}@else - @endif</td>
                    <td class="text-nowrap">@if($deal->{$feeField}){{ Fmt::currency($deal->{$feeField}, 0) }}@else - @endif</td>
                    <td class="text-secondary">{{ $deal->agent->name ?? '-' }}</td>
                    <td>
                        @php $tempColors = ['hot' => 'bg-red-lt', 'warm' => 'bg-yellow-lt', 'cold' => 'bg-azure-lt']; @endphp
                        @if($deal->lead && $deal->lead->temperature)
                        <span class="badge {{ $tempColors[$deal->lead->temperature] ?? 'bg-secondary-lt' }}">{{ __(ucfirst($deal->lead->temperature)) }}</span>
                        @else
                        <span class="text-secondary">-</span>
                        @endif
                    </td>
                    <td class="text-secondary">{{ $daysInStage }} {{ Str::plural(__('day'), $daysInStage) }}</td>
                    <td class="text-secondary">{{ $deal->updated_at?->diffForHumans() ?: '-' }}</td>
                    <td>
                        <div class="dropdown">
                            <button class="btn btn-ghost-secondary btn-icon" data-bs-toggle="dropdown" aria-label="{{ __('Actions for') }} {{ $deal->title }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" aria-hidden="true"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="12" cy="5" r="1"/></svg>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="{{ route('deals.show', $deal) }}">{{ __('View') }}</a>
                                <a class="dropdown-item" href="{{ route('pipeline', array_merge($filters, ['show_empty' => 1])) }}#">{{ __('Open Kanban') }}</a>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="11" class="text-center py-4">
                        @if(request()->hasAny(['search', 'deal_type', 'stage', 'agent', 'temp', 'source']))
                            <div class="text-secondary mb-2">{{ __('No deals match your current filters.') }}</div>
                            <a href="{{ route('deals.index') }}" class="btn btn-sm btn-outline-secondary">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4"/><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4"/></svg>
                                {{ __('Clear Filters') }}
                            </a>
                        @else
                            <div class="text-secondary mb-2">{{ __('No deals yet. Deals are created automatically when a lead reaches a revenue stage.') }}</div>
                            <a href="{{ route('pipeline') }}" class="btn btn-sm btn-outline-secondary">{{ __('Open Pipeline') }}</a>
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex align-items-center">
        <p class="m-0 text-secondary">{{ __('Showing') }} <span>{{ $deals->firstItem() ?? 0 }}</span> {{ __('to') }} <span>{{ $deals->lastItem() ?? 0 }}</span> {{ __('of') }} <span>{{ $deals->total() }}</span> {{ __('entries') }}</p>
        <div class="ms-auto">
            {{ $deals->withQueryString()->links() }}
        </div>
    </div>
    </div>
</div>

@push('scripts')
<script>
// Desktop row click → open the deal. Never hijacks links, buttons, forms or
// the stage/agent selects. Delegated so it survives live-filter swaps.
document.addEventListener('click', function (e) {
    var tr = e.target && e.target.closest ? e.target.closest('tbody tr[data-href]') : null;
    if (!tr) return;
    if (e.target.closest('a, button, input, select, textarea, label, .dropdown, [data-bs-toggle], [data-bs-dismiss]')) return;
    e.preventDefault();
    window.location.href = tr.dataset.href;
});
</script>
@endpush
@endsection