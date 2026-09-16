@extends('layouts.app')

@section('title', __('Leases'))
@section('page-title', __('Leases'))

@section('content')
@php
    $fmt = \App\Helpers\TenantFormatHelper::class;
    $statusBadges = [
        'active' => 'green',
        'expiring' => 'yellow',
        'renewed' => 'blue',
        'expired' => 'red',
        'moved_out' => 'secondary',
    ];
@endphp

<div class="row g-3 mb-3 d-none d-md-flex">
    <div class="col-6 col-md-3">
        <a href="{{ route('leases.index', ['status' => 'active']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-green text-white">{{ $counts['active'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Active') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('leases.index', ['status' => 'expiring']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-yellow text-white">{{ $counts['expiring'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Expiring Soon') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('leases.index', ['status' => 'expired']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-red text-white">{{ $counts['expired'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Expired') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('leases.index', ['status' => 'moved_out']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-secondary text-white">{{ $counts['moved_out'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Moved Out') }}</div></div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Leases') }}</h3>
        <div class="card-actions">
            <a href="{{ route('leases.create') }}" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ __('Record Lease') }}
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('leases.index') }}" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label">{{ __('Status') }}</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>{{ __('Active') }}</option>
                    <option value="expiring" {{ request('status') === 'expiring' ? 'selected' : '' }}>{{ __('Expiring Soon') }}</option>
                    <option value="renewed" {{ request('status') === 'renewed' ? 'selected' : '' }}>{{ __('Renewed') }}</option>
                    <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>{{ __('Expired') }}</option>
                    <option value="moved_out" {{ request('status') === 'moved_out' ? 'selected' : '' }}>{{ __('Moved Out') }}</option>
                </select>
            </div>
            @if($agents->isNotEmpty())
            <div class="col-md-2">
                <label class="form-label">{{ __('Agent') }}</label>
                <select name="agent" class="form-select form-select-sm">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-md-3">
                <label class="form-label">{{ __('Search') }}</label>
                <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Client, unit, community...') }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                <a href="{{ route('leases.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-vcenter card-table mobile-cols-3">
            <thead>
                <tr>
                    <th>{{ __('Client') }}</th>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Contract') }}</th>
                    <th>{{ __('Rent') }}</th>
                    <th>{{ __('Agent') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leases as $lease)
                <tr data-href="{{ $lease->property ? route('properties.show', $lease->property) : '#' }}">
                    <td>
                        @if($lease->buyer)
                            <a href="{{ route('buyers.show', $lease->buyer) }}">{{ $lease->buyer->full_name }}</a>
                        @elseif($lease->lead)
                            <a href="{{ route('leads.show', $lease->lead) }}">{{ $lease->lead->full_name }}</a>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>
                        <div>{{ $lease->unit_label }}</div>
                        @if($lease->property)
                            <a href="{{ route('properties.show', $lease->property) }}" class="text-muted small">{{ __('Inventory #:id', ['id' => $lease->property_id]) }}</a>
                        @endif
                    </td>
                    <td>
                        <div>{{ $lease->contract_start_date ? \App\Helpers\TenantFormatHelper::date($lease->contract_start_date) . ' → ' : '' }}{{ \App\Helpers\TenantFormatHelper::date($lease->contract_end_date) }}</div>
                        <div class="text-muted small">
                            @if($lease->days_until_expiry < 0)
                                {{ __('Ended :ago', ['ago' => now()->startOfDay()->diffForHumans($lease->contract_end_date)]) }}
                            @else
                                {{ __('Ends in :days', ['days' => $lease->days_until_expiry . ' ' . ($lease->days_until_expiry === 1 ? __('day') : __('days'))]) }}
                            @endif
                        </div>
                    </td>
                    <td>
                        @if($lease->rent_price)
                            {{ \App\Helpers\TenantFormatHelper::currency($lease->rent_price) }}
                            <div class="text-muted small">{{ \App\Helpers\TenantFormatHelper::currencyCode() }} / {{ __('year') }}</div>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>{{ $lease->agent->name ?? '-' }}</td>
                    <td>
                        <span class="badge bg-{{ $statusBadges[$lease->expiry_status] ?? 'secondary' }}">{{ $lease->expiry_status_label }}</span>
                    </td>
                    <td class="text-end">
                        @if(auth()->user()->can('renew', $lease))
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#renewLease-{{ $lease->id }}">{{ __('Renew') }}</button>
                        @endif
                        @if(auth()->user()->can('update', $lease) && $lease->buyer)
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#moveLease-{{ $lease->id }}">{{ __('Move to New Unit') }}</button>
                        @endif
                    </td>
                </tr>

                {{-- Renew modal --}}
                @if(auth()->user()->can('renew', $lease))
                <div class="modal modal-blur fade" id="renewLease-{{ $lease->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <form method="POST" action="{{ route('leases.renew', $lease) }}" class="modal-content">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">{{ __('Renew Lease – :client', ['client' => $lease->buyer?->full_name ?? $lease->unit_label]) }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label required">{{ __('New Contract End Date') }}</label>
                                    <input type="date" name="contract_end_date" class="form-control" required value="{{ $lease->contract_end_date->format('Y-m-d') }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('New Contract Start Date') }}</label>
                                    <input type="date" name="contract_start_date" class="form-control" value="{{ $lease->contract_end_date->copy()->addDay()->format('Y-m-d') }}">
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">{{ __('Rent (:code / year)', ['code' => \App\Helpers\TenantFormatHelper::currencyCode()]) }}</label>
                                        <input type="number" step="0.01" min="0" name="rent_price" class="form-control" value="{{ $lease->rent_price }}">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">{{ __('Admin Fee') }}</label>
                                        <input type="number" step="0.01" min="0" name="admin_fee" class="form-control" value="{{ $lease->admin_fee }}">
                                    </div>
                                </div>
                                <div class="form-hint mt-2">{{ __('Renewing resets the 45-day reminder window for the new contract.') }}</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                <button type="submit" class="btn btn-primary ms-auto">{{ __('Renew Lease') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
                @endif

                {{-- Move to new unit modal --}}
                @if(auth()->user()->can('update', $lease) && $lease->buyer)
                <div class="modal modal-blur fade" id="moveLease-{{ $lease->id }}" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog" role="document">
                        <form method="POST" action="{{ route('leases.startNewSearch', $lease) }}" class="modal-content">
                            @csrf
                            <div class="modal-header">
                                <h5 class="modal-title">{{ __('Start New Search') }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>{{ __('The client wants to move to a different apartment. This creates a new rental lead assigned to the same agent — the regular pipeline will drive the next unit.') }}</p>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Client') }}</label>
                                    <input type="text" class="form-control" value="{{ $lease->buyer->full_name }}" disabled>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Current Unit') }}</label>
                                    <input type="text" class="form-control" value="{{ $lease->unit_label }}" disabled>
                                </div>
                                @if($agents->isNotEmpty())
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Assign To') }}</label>
                                    <select name="agent_id" class="form-select">
                                        <option value="">{{ __('Keep the same agent') }}</option>
                                        @foreach($agents as $agent)
                                            <option value="{{ $agent->id }}" {{ $lease->agent_id == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @endif
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Preferred Unit / Requirements (optional)') }}</label>
                                    <textarea name="requirements" class="form-control" rows="2" placeholder="{{ __('e.g. 2BR, Marina, furnished, budget 120k') }}"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                                <button type="submit" class="btn btn-primary ms-auto">{{ __('Create New Search') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
                @endif
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">{{ __('No leases found.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($leases->hasPages())
    <div class="card-footer d-flex align-items-center">
        {{ $leases->appends(request()->query())->links('vendor.pagination.tabler') }}
    </div>
    @endif
</div>
@endsection