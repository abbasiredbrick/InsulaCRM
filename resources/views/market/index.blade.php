@extends('layouts.app')

@section('title', __('Market / Cold Calls'))
@section('page-title', __('Market / Cold Calls'))

@section('content')
@php
    $mTypes = \App\Models\MarketContact::TYPES;
    $mStatuses = \App\Models\MarketContact::STATUSES;
    $badge = [
        'pending' => 'secondary',
        'reached' => 'green',
        'not_reached' => 'yellow',
        'wrong_number' => 'red',
        'not_interested' => 'red',
        'call_back' => 'blue',
        'do_not_contact' => 'dark',
        'converted' => 'teal',
    ];
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <a href="{{ route('market.index') }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-secondary text-white">{{ $counts['total'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Total Contacts') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('market.index', ['status' => 'pending']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-yellow text-white">{{ $counts['pending'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Pending') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('market.index', ['status' => 'reached']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-green text-white">{{ $counts['reached'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Reached') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('market.index', ['status' => 'converted']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-teal text-white">{{ $counts['converted'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Converted') }}</div></div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Market Contacts') }}</h3>
        <div class="card-actions">
            <a href="{{ route('market.create') }}" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ __('Import List') }}
            </a>
        </div>
    </div>

    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('market.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">{{ __('Search') }}</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="{{ __('Name, phone, email, community…') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Type') }}</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">{{ __('All Types') }}</option>
                    @foreach($types as $key => $label)
                        <option value="{{ $key }}" {{ request('type') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Status') }}</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">{{ __('All Statuses') }}</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Agent') }}</label>
                <select name="agent" class="form-select form-select-sm">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $id => $name)
                        <option value="{{ $id }}" {{ request('agent') == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary w-100">{{ __('Filter') }}</button>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
            <tr>
                <th>{{ __('Contact') }}</th>
                <th>{{ __('Type') }}</th>
                <th>{{ __('Unit / Interest') }}</th>
                <th>{{ __('Phone') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Agent') }}</th>
                <th class="w-1">{{ __('Actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($contacts as $contact)
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <span class="avatar avatar-sm me-2 bg-primary-lt">{{ strtoupper(substr($contact->full_name ?: ($contact->phone ?: '?'), 0, 1)) }}</span>
                            <div>
                                <div class="font-weight-medium">{{ $contact->full_name ?: '—' }}</div>
                                @if($contact->company)
                                    <div class="text-muted small">{{ $contact->company }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td><span class="badge bg-primary-lt">{{ __($mTypes[$contact->type] ?? $contact->type) }}</span></td>
                    <td class="text-muted">
                        @if($contact->type === 'landlord')
                            {{ $contact->unit_summary ?: '—' }}
                            @if($contact->rent_price)<div class="small">{{ \App\Helpers\TenantFormatHelper::currency((float) $contact->rent_price) }} {{ __('/ year') }}</div>@endif
                        @else
                            @if($contact->preferred_type){{ $contact->preferred_type }}@endif
                            @if($contact->budget)<div class="small">{{ \App\Helpers\TenantFormatHelper::currency((float) $contact->budget) }}</div>@endif
                        @endif
                    </td>
                    <td>
                        @if($contact->phone)
                            <a href="tel:{{ $contact->phone }}" class="text-reset">{{ $contact->phone }}</a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td><span class="badge bg-{{ $badge[$contact->status] ?? 'secondary' }}">{{ __($mStatuses[$contact->status] ?? $contact->status) }}</span></td>
                    <td>{{ $contact->caller?->name ?: '—' }}</td>
                    <td>
                        <div class="btn-group">
                            <a href="{{ route('market.show', $contact) }}" class="btn btn-sm btn-ghost-primary">{{ __('Open') }}</a>
                            @if($contact->status !== 'converted')
                                <a href="{{ route('market.show', $contact) }}#call" class="btn btn-sm btn-ghost-secondary">{{ __('Log Call') }}</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">{{ __('No market contacts yet.') }} <a href="{{ route('market.create') }}">{{ __('Import a list') }}</a>.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($contacts->hasPages())
        <div class="card-footer d-flex justify-content-center">
            {{ $contacts->links() }}
        </div>
    @endif
</div>

@endsection