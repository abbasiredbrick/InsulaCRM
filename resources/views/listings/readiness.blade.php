@extends('layouts.app')

@section('title', __('Bayut Readiness'))
@section('page-title', __('Bayut Readiness'))

@section('content')
<div class="row mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-primary text-white avatar"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 8l1 0"/><path d="M9 12l1 0"/><path d="M9 16l1 0"/><path d="M14 8l1 0"/><path d="M14 12l1 0"/><path d="M14 16l1 0"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6"/><path d="M19 22v-6"/><path d="M22 19l-3 3l-3 -3"/></svg></span></div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $report['total'] }}</div>
                        <div class="text-muted">{{ __('Units in Portal Inventory') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-green text-white avatar"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 12l5 5l10 -10"/><path d="M2 12l5 5m5 -5l5 -5"/></svg></span></div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $report['ready'] }}</div>
                        <div class="text-muted">{{ __('Ready to Push') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-red text-white avatar"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5v9L12 21l-8 -4.5v-9L12 3z"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/></svg></span></div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $report['blocked'] }}</div>
                        <div class="text-muted">{{ __('Blocked (Missing Fields)') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="bg-azure text-white avatar"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2H6a2 2 0 0 1 -2 -2V7z"/><path d="M8 7v0.01"/><path d="M12 7v0.01"/><path d="M16 7v0.01"/><path d="M4 15l4 0"/></svg></span></div>
                    <div class="col">
                        <div class="font-weight-medium">{{ count($report['specs']) }}</div>
                        <div class="text-muted">{{ __('Mandatory Fields') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3 class="card-title">{{ __('Bayut Mandatory Fields') }}</h3>
        <a href="{{ route('listings.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Back to Listed Units') }}</a>
    </div>
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('listings.readiness') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">{{ __('Search Unit') }}</label>
                <input type="text" name="q" class="form-control form-control-sm" value="{{ request('q') }}" placeholder="{{ __('Title, unit, building...') }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                <a href="{{ route('listings.readiness') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Agent') }}</th>
                    <th>{{ __('Blockers') }}</th>
                    <th class="w-1">{{ __('Ready') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($report['rows'] as $row)
                    @php($p = $row['property'])
                    <tr>
                        <td>
                            <div class="fw-bold">{{ $p->display_name }}</div>
                            <div class="text-muted small">
                                {{ __(\App\Models\Property::AVAILABILITIES[$p->availability] ?? $p->availability) }}
                                @if($p->intent) • {{ __(\App\Models\Property::INTENTS[$p->intent] ?? $p->intent) }}@endif
                                @if($p->bedrooms) • {{ $p->bedrooms }} {{ __('bd') }}@endif
                            </div>
                            @if($p->unit_no)
                                <div class="text-muted small">{{ __('Unit') }} {{ $p->unit_no }}</div>
                            @endif
                        </td>
                        <td class="text-nowrap"><strong>{{ $p->price_line }}</strong></td>
                        <td>{{ $p->assignedAgent?->name ?? '-' }}</td>
                        <td>
                            @forelse($row['missing'] as $miss)
                                <span class="badge bg-red-lt mb-1 me-1">{{ $miss['label'] }}</span>
                            @empty
                                <span class="text-muted small">{{ __('Nothing missing') }}</span>
                            @endforelse
                        </td>
                        <td>
                            @if(count($row['missing']) === 0)
                                <span class="badge bg-green">{{ __('Ready') }}</span>
                            @else
                                <span class="badge bg-red">{{ count($row['missing']) }}/{{ count($report['specs']) }}</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('inventory.show', $p->id) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No units in portal inventory (Ready to List / Listed).') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($report['miss_by_key']->isNotEmpty())
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">{{ __('What is blocking units') }}</h3>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            @foreach($report['specs'] as $spec)
                @if(isset($report['miss_by_key'][$spec['key']]))
                    <span class="badge bg-red-lt">{{ $spec['label'] }} — {{ $report['miss_by_key'][$spec['key']] }} unit(s)</span>
                @endif
            @endforeach
        </div>
    </div>
</div>
@endif
@endsection