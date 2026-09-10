@extends('layouts.app')

@section('title', __('Team'))
@section('page-title', __('Team'))

@php
    $isAdmin = auth()->user()->isAdmin();
    $managerOptions = $members->where('id', '!=', auth()->user()->id);
@endphp

@section('content')
<div class="alert alert-info d-flex align-items-center">
    <div>
        {{ __('Everyone below reports up the chain. Managers see their team progress here and are notified whenever a team member logs activity on an assigned lead, so they can take further action.') }}
    </div>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <div class="datagrid">
            <div class="datagrid-item">
                <div class="datagrid-title">{{ __('Team members') }}</div>
                <div class="datagrid-content text-yellow">{{ $members->count() }}</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">{{ __('Open leads') }}</div>
                <div class="datagrid-content text-yellow">{{ $totals->open_leads }}</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">{{ __('Closed this month') }}</div>
                <div class="datagrid-content text-green">{{ $totals->closed_leads + $totals->closed_deals }}</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">{{ __('Open deal value') }}</div>
                <div class="datagrid-content">{{ \App\Helpers\TenantFormatHelper::currency($totals->open_deals_value) }}</div>
            </div>
            <div class="datagrid-item">
                <div class="datagrid-title">{{ __('Activities (7 days)') }}</div>
                <div class="datagrid-content">{{ $totals->activities_7d }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Team progress') }}</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Member') }}</th>
                    <th>{{ __('Manager') }}</th>
                    <th>{{ __('Leads') }}</th>
                    <th>{{ __('Closed (mo)') }}</th>
                    <th>{{ __('Deals (mo)') }}</th>
                    <th>{{ __('Deal value') }}</th>
                    <th>{{ __('Activity (7d)') }}</th>
                    <th>{{ __('Last activity') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($members as $member)
                <tr>
                    <td>
                        <div class="fw-bold">{{ $member->name }}</div>
                        <div class="text-muted small">{{ __(ucwords(str_replace('_', ' ', $member->role->name ?? '-'))) }}</div>
                    </td>
                    <td>
                        @if($isAdmin)
                        <form method="POST" action="{{ route('team.setManager') }}" class="d-flex gap-1">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                            <select name="reports_to" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">{{ __('No manager') }}</option>
                                @foreach($members->where('id', '!=', $member->id) as $candidate)
                                    <option value="{{ $candidate->id }}" {{ $member->reports_to == $candidate->id ? 'selected' : '' }}>{{ $candidate->name }}</option>
                                @endforeach
                            </select>
                        </form>
                        @else
                            {{ $member->manager?->name ?? '—' }}
                        @endif
                    </td>
                    <td><a href="{{ route('leads.index', ['agent_id' => $member->id]) }}" class="text-reset">{{ $member->open_leads }}</a></td>
                    <td class="text-green">{{ $member->closed_leads_this_month }}</td>
                    <td>{{ $member->closed_deals_this_month }}</td>
                    <td>{{ \App\Helpers\TenantFormatHelper::currency($member->open_deals_value) }}</td>
                    <td>{{ $member->activities_7d }}</td>
                    <td>
                        @if($member->last_activity_at)
                            {{ $member->last_activity_at->diffForHumans() }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('leads.index', ['agent_id' => $member->id]) }}" class="btn btn-sm btn-outline-primary">{{ __('Leads') }}</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">{{ __('No team members yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection