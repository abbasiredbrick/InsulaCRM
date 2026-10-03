@extends('layouts.app')
@section('title', __('Recycled Leads'))

@section('page-title', __('Recycled Leads'))

@section('content')
@php
    $rStatuses = \App\Models\RecycledLead::STATUSES;
    $badge = [
        'pending' => 'secondary',
        'reached' => 'green',
        'not_reached' => 'yellow',
        'wrong_number' => 'red',
        'not_interested' => 'red',
        'call_back' => 'blue',
        'do_not_contact' => 'dark',
        'already_active' => 'cyan',
        'regenerated' => 'teal',
    ];
@endphp

<div class="row g-3 mb-3">
    <div class="col-6 col-md-2">
        <a href="{{ route('recycled.index') }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-secondary text-white">{{ $counts['total'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Pool Total') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="{{ route('recycled.index', ['status' => 'pending']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-yellow text-white">{{ $counts['pending'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Pending') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="{{ route('recycled.index', ['status' => 'call_back']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-blue text-white">{{ $counts['call_back'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Call Back') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="{{ route('recycled.index', ['status' => 'regenerated']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-teal text-white">{{ $counts['regenerated'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Regenerated') }}</div></div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-2">
        <a href="{{ route('recycled.index', ['status' => 'already_active']) }}" class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto"><span class="avatar bg-cyan text-white">{{ $counts['already_active'] }}</span></div>
                    <div class="col"><div class="font-weight-medium">{{ __('Already Active') }}</div></div>
                </div>
            </div>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Recycle Bank') }}</h3>
        <div class="card-actions">
            <form method="POST" action="{{ route('recycled.runRecycle') }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                    {{ __('Recycle now (60-day rule)') }}
                </button>
            </form>
            <a href="{{ route('recycled.portal-import.create') }}" class="btn btn-outline-primary">
                {{ __('Pull via API') }}
            </a>
            <a href="{{ route('recycled.create') }}" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                {{ __('Import File') }}
            </a>
        </div>
    </div>

    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('recycled.index') }}" class="row g-2 align-items-end" data-live-filter>
            <div class="col-md-3">
                <label class="form-label">{{ __('Search') }}</label>
                <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="{{ __('Name, phone, email, project…') }}">
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
                <label class="form-label">{{ __('Portal') }}</label>
                <select name="portal" class="form-select form-select-sm">
                    <option value="">{{ __('All Portals') }}</option>
                    @foreach($portals as $key => $label)
                        <option value="{{ $key }}" {{ request('portal') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Category') }}</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">{{ __('All Categories') }}</option>
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Type') }}</label>
                <select name="deal_type" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="rent" {{ request('deal_type') === 'rent' ? 'selected' : '' }}>{{ __('Rent') }}</option>
                    <option value="sale" {{ request('deal_type') === 'sale' ? 'selected' : '' }}>{{ __('Sales') }}</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Assignee') }}</label>
                <select name="agent" class="form-select form-select-sm">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $id => $name)
                        <option value="{{ $id }}" {{ request('agent') == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Per page') }}</label>
                <select name="per_page" class="form-select form-select-sm">
                    @foreach([10, 25, 50, 100] as $n)
                        <option value="{{ $n }}" {{ (int) request('per_page', 25) === $n ? 'selected' : '' }}>{{ $n }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Lead Date') }}</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm" placeholder="{{ __('From') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm" placeholder="{{ __('To') }}">
            </div>
        </form>
    </div>

    <div data-live-results>
    <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom small text-muted">
        <div>
            {{ __('Showing') }}
            <strong>{{ $recycled->firstItem() ?? 0 }}-{{ $recycled->lastItem() ?? 0 }}</strong>
            {{ __('of') }}
            <strong>{{ $recycled->total() }}</strong>
            {{ __('records') }}
        </div>
        @if($recycled->hasPages())
            <div>
                {{ $recycled->links() }}
            </div>
        @endif
    </div>
    <form method="POST" action="{{ route('recycled.bulkAssign') }}" data-bulk-form>
        @csrf
        <div class="d-flex align-items-center gap-2 p-2 border-bottom" data-bulk-toolbar>
            <input type="checkbox" class="form-check-input" data-bulk-select-all title="{{ __('Select all') }}">
            <span class="small text-muted" data-bulk-count>{{ __('Select rows to assign') }}</span>
            <span class="ms-auto d-flex align-items-center gap-2">
                <select name="agent_id" class="form-select form-select-sm w-auto" data-bulk-agent>
                    <option value="">{{ __('Assignee') }}</option>
                    @foreach($agents as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-success" data-bulk-submit disabled>{{ __('Assign to agent') }}</button>
            </span>
        </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
            <tr>
                <th class="w-1"></th>
                <th>{{ __('Contact') }}</th>
                <th>{{ __('Source / Type / Location') }}</th>
                <th>{{ __('Category') }}</th>
                <th>{{ __('Phone') }}</th>
                <th>{{ __('Email') }}</th>
                <th>{{ __('Lead Date') }}</th>
                <th>{{ __('Status') }}</th>
                <th>{{ __('Assignee') }}</th>
                <th class="w-1">{{ __('Actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($recycled as $r)
                <tr>
                    <td>
                        <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $r->id }}" data-bulk-id>
                    </td>
                    <td>
                        <div class="d-flex align-items-center">
                            <span class="avatar avatar-sm me-2 bg-primary-lt">{{ strtoupper(substr($r->full_name ?: ($r->phone ?: '?'), 0, 1)) }}</span>
                            <div>
                                <div class="font-weight-medium">{{ $r->full_name ?: '—' }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-primary-lt">{{ $r->portal_label }}</span>
                        <span class="badge bg-secondary-lt ms-1">{{ $r->deal_type_label }}</span>
                        @if($r->source === 'auto_recycle')
                            <div class="text-muted small mt-1">{{ __('Auto-recycled from a lead') }}</div>
                        @endif
                        @if($r->purchased_project)
                            <div class="text-muted small mt-1">{{ $r->purchased_project }}@if($r->unit_no) • {{ $r->unit_no }}@endif</div>
                        @endif
                    </td>
                    <td>
                        @if($r->category)
                            <span class="badge bg-primary-lt">{{ __($r->category_label) }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($r->phone)
                            <a href="tel:{{ $r->phone }}" class="text-reset">{{ $r->phone }}</a>
                            @if($r->whatsapp_phone)
                                <a href="https://wa.me/{{ $r->whatsapp_phone }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost-success" title="{{ __('WhatsApp') }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9z"/><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1"/></svg>
                                </a>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($r->email)
                            <a href="mailto:{{ $r->email }}" class="text-reset">
                                {{ $r->email }}
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" title="{{ __('Send email') }}"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="3 7 12 13 21 7"/></svg>
                            </a>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($r->lead_date)
                            <div>{{ $r->lead_date->format('M d, Y') }}</div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td><span class="badge bg-{{ $badge[$r->status] ?? 'secondary' }}">{{ __($rStatuses[$r->status] ?? $r->status) }}</span></td>
                    <td>{{ $r->assignee?->name ?: '—' }}</td>
                    <td>
                        <div class="btn-group">
                            <a href="{{ route('recycled.show', $r) }}" class="btn btn-sm btn-ghost-primary">{{ __('Open') }}</a>
                            @if(! in_array($r->status, ['regenerated', 'already_active'], true))
                                <a href="{{ route('recycled.show', $r) }}#regenerate" class="btn btn-sm btn-ghost-teal">{{ __('Regenerate') }}</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-muted py-4">
                        {{ __('No recycled leads yet.') }}
                        <a href="{{ route('recycled.create') }}">{{ __('Import your previous Bayut / Dubizzle / PropertyFinder leads') }}</a>
                        {{ __('or wait for the 60-day auto-recycle.') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    </form>

    </div>
</div>

@push('scripts')
<script>
    // Bulk assign toolbar — event delegation so it survives live-filter swaps.
    document.addEventListener('change', function (e) {
        const all = e.target.closest('[data-bulk-select-all]');
        if (all) {
            const form = all.closest('[data-bulk-form]');
            form.querySelectorAll('[data-bulk-id]').forEach((box) => {
                box.checked = all.checked;
            });
            if (typeof window.refreshBulkState === 'function') window.refreshBulkState(form);
        }

        const row = e.target.closest('[data-bulk-id]');
        if (row) {
            const form = row.closest('[data-bulk-form]');
            if (typeof window.refreshBulkState === 'function') window.refreshBulkState(form);
        }
    });

    window.refreshBulkState = function (form) {
        const count = form.querySelectorAll('[data-bulk-id]:checked').length;
        const submit = form.querySelector('[data-bulk-submit]');
        const selectAll = form.querySelector('[data-bulk-select-all]');
        const label = form.querySelector('[data-bulk-count]');
        const selected = form.querySelectorAll('[data-bulk-id]');

        submit.disabled = count === 0;
        selectAll.checked = selected.length > 0 && count === selected.length;
        label.textContent = count > 0
            ? (count + (count === 1 ? ' row selected' : ' rows selected'))
            : 'Select rows to assign';
    };
</script>
@endpush

@endsection