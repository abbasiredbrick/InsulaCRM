@extends('layouts.app')

@section('title', __('Listed Units'))
@section('page-title', __('Listed Units'))

@php
    $tab = in_array($tab ?? null, ['units', 'renewals', 'calendar'], true) ? $tab : 'units';
    $permitWindow = $permitWindow ?? 7;
    $permitMonths = [__('January'), __('February'), __('March'), __('April'), __('May'), __('June'), __('July'), __('August'), __('September'), __('October'), __('November'), __('December')];
    $permitDows = [__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')];
    $tabUrl = fn (string $t) => route('listings.index', array_merge(request()->query(), ['tab' => $t, 'page' => null]));
    $permitBadge = function ($unit) use ($permitWindow): array {
        if (! $unit->rera_permit_no) {
            return ['class' => 'bg-secondary-lt', 'text' => __('No permit no.')];
        }
        if (! $unit->permit_expires_at) {
            return ['class' => 'bg-secondary-lt', 'text' => __('No expiry')];
        }
        $days = $unit->permitDaysLeft();
        if ($days < 0) {
            return ['class' => 'bg-danger-lt', 'text' => __('Expired').' · '.abs($days).'d'];
        }
        if ($days <= $permitWindow) {
            return ['class' => 'bg-orange-lt', 'text' => $days === 0 ? __('Due today') : __('Due in :n days', ['n' => $days])];
        }
        return ['class' => 'bg-green-lt', 'text' => $unit->permit_expires_at->format('d M Y')];
    };
@endphp

@section('content')
@if($tab === 'units')
{{-- KPI Cards (desktop/tablet only — hidden in the mobile PWA) --}}
<div class="row mb-3 d-none d-md-flex">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-primary text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0"/><path d="M9 8l1 0"/><path d="M9 12l1 0"/><path d="M9 16l1 0"/><path d="M14 8l1 0"/><path d="M14 12l1 0"/><path d="M14 16l1 0"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['listed'] }}</div>
                        <div class="text-muted">{{ __('Listed Units') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-green text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['live'] }}</div>
                        <div class="text-muted">{{ __('Live on a Portal') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-azure text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3v18"/><path d="M16 7l-4 4l-4 -4"/><path d="M4 21h16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['ready'] }}</div>
                        <div class="text-muted">{{ __('Ready to List') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="bg-orange text-white avatar">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3v18"/><path d="M16 7l-4 4l-4 -4"/><path d="M4 21h16"/></svg>
                        </span>
                    </div>
                    <div class="col">
                        <div class="font-weight-medium">{{ $kpis['avg_rent'] ? Fmt::currency($kpis['avg_rent']) : '-' }}</div>
                        <div class="text-muted">{{ __('Avg Rent / year') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ $tab === 'renewals' ? __('Permit renewals') : ($tab === 'calendar' ? __('Permit calendar') : __('Listed Units')) }}</h3>
        <div class="card-actions">
            <a href="{{ route('listings.mandates') }}" class="btn btn-sm btn-outline-secondary">{{ __('Sales Mandates') }}</a>
            <a href="{{ route('inventory.portal') }}" class="btn btn-sm btn-outline-secondary">{{ __('Portals') }}</a>
            @if(auth()->user()->isAdmin())
                <a href="{{ route('listings.readiness') }}" class="btn btn-sm btn-outline-secondary">{{ __('Bayut Readiness') }}</a>
            @endif
            <a href="{{ route('inventory.create') }}" class="btn btn-sm btn-primary">{{ __('New Unit') }}</a>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="px-3 pt-2">
        <ul class="nav nav-tabs border-bottom-0">
            <li class="nav-item">
                <a class="nav-link {{ $tab === 'units' ? 'active' : '' }}" href="{{ $tabUrl('units') }}">{{ __('Listed Units') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $tab === 'renewals' ? 'active' : '' }}" href="{{ $tabUrl('renewals') }}">{{ __('Permit renewals') }}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ $tab === 'calendar' ? 'active' : '' }}" href="{{ $tabUrl('calendar') }}">{{ __('Permit calendar') }}</a>
            </li>
        </ul>
    </div>

    @if($tab === 'units')
    {{-- Filters --}}
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('listings.index') }}" class="row g-2 align-items-end" data-live-filter>
            <div class="col-md-2">
                <label class="form-label">{{ __('Source') }}</label>
                <select name="source" class="form-select form-select-sm">
                    <option value="">{{ __('All Sources') }}</option>
                    @foreach($sources as $s)
                        <option value="{{ $s->id }}" {{ request('source') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Building') }}</label>
                <select name="building" class="form-select form-select-sm">
                    <option value="">{{ __('All Buildings') }}</option>
                    @foreach($buildings as $b)
                        <option value="{{ $b }}" {{ request('building') === $b ? 'selected' : '' }}>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Intent') }}</label>
                <select name="intent" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    @foreach(\App\Models\Property::INTENTS as $key => $label)
                        <option value="{{ $key }}" {{ request('intent') === $key ? 'selected' : '' }}>{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">{{ __('Permit status') }}</label>
                <select name="permit_status" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="expired" {{ request('permit_status') === 'expired' ? 'selected' : '' }}>{{ __('Expired') }}</option>
                    <option value="due" {{ request('permit_status') === 'due' ? 'selected' : '' }}>{{ __('Due within :n days', ['n' => $permitWindow]) }}</option>
                    <option value="ok" {{ request('permit_status') === 'ok' ? 'selected' : '' }}>{{ __('Valid') }}</option>
                    <option value="missing" {{ request('permit_status') === 'missing' ? 'selected' : '' }}>{{ __('Missing / no expiry') }}</option>
                </select>
            </div>
            @if(auth()->user()->isAdmin() && $agents->isNotEmpty())
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
                <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Title, unit, building, community...') }}">
            </div>
            <div class="col-auto">
                <a href="{{ route('listings.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
            </div>
        </form>
    </div>

    <div data-live-results>
    <x-record-count :records="$units" :label="__('units')" />
    <div class="table-responsive">
        <table class="table table-vcenter card-table mobile-cols-3">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th class="text-nowrap w-1">{{ __('Price') }}</th>
                    <th class="text-nowrap w-1">{{ __('Fees') }}</th>
                    <th>{{ __('Source') }}</th>
                    <th>{{ __('Portals') }}</th>
                    <th>{{ __('Permit') }}</th>
                    <th>{{ __('Listed Since') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $unit)
                @php
                    $portalDates = [$unit->bayut_listed_at, $unit->dubizzle_listed_at, $unit->propertyfinder_listed_at];
                    $listedOn = $unit->listed_at ?: collect($portalDates)->filter()->max();
                    $badge = $permitBadge($unit);
                @endphp
                <tr data-href="{{ route('inventory.show', $unit) }}">
                    <td>
                        <div class="fw-bold"><a href="{{ route('inventory.show', $unit) }}">{{ $unit->display_name }}</a></div>
                        @if($unit->unit_no)
                            <div class="text-muted small">{{ __('Unit') }} {{ $unit->unit_no }}</div>
                        @endif
                    </td>
                    <td class="text-nowrap">
                        <strong>{{ $unit->price_line }}</strong>
                    </td>
                    <td>
                        @if($unit->deposit_amount || $unit->admin_fee || $unit->contract_fee)
                            <div class="text-nowrap">
                                @if($unit->deposit_amount)<span class="text-muted">{{ __('Dep') }} {{ Fmt::currency($unit->deposit_amount) }}</span>@endif
                                @if($unit->admin_fee)<span class="text-muted ms-1">{{ __('Adm') }} {{ Fmt::currency($unit->admin_fee) }}</span>@endif
                                @if($unit->contract_fee)<span class="text-muted ms-1">{{ __('Contract Fee') }} {{ Fmt::currency($unit->contract_fee) }}</span>@endif
                            </div>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                    <td>{{ $unit->availabilitySource->name ?? '-' }}</td>
                    <td>
                        <span class="me-1" title="Bayut">
                            <span class="badge {{ $unit->bayut_status === 'live' ? 'bg-green' : ($unit->bayut_status === 'removed' ? 'bg-danger' : 'bg-secondary') }}" style="font-size:.65rem;">B</span>
                        </span>
                        <span class="me-1" title="Dubizzle">
                            <span class="badge {{ $unit->dubizzle_status === 'live' ? 'bg-green' : ($unit->dubizzle_status === 'removed' ? 'bg-danger' : 'bg-secondary') }}" style="font-size:.65rem;">D</span>
                        </span>
                        <span title="Property Finder">
                            <span class="badge {{ $unit->propertyfinder_status === 'live' ? 'bg-green' : ($unit->propertyfinder_status === 'removed' ? 'bg-danger' : 'bg-secondary') }}" style="font-size:.65rem;">PF</span>
                        </span>
                    </td>
                    <td class="text-nowrap">
                        <span class="badge {{ $badge['class'] }}">{{ $badge['text'] }}</span>
                        <div class="text-muted small">{{ $unit->rera_permit_no ?: $unit->permit_label }}</div>
                    </td>
                    <td class="text-nowrap">{{ $listedOn ? $listedOn->format('d M Y') : '-' }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('inventory.show', $unit) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                        <a href="{{ route('inventory.edit', $unit) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">{{ __('No listed units found. Set a unit to "Listed" in the inventory when it goes live on the portals.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($units->hasPages())
    <div class="card-footer d-flex align-items-center">
        {{ $units->appends(request()->query())->links('vendor.pagination.tabler') }}
    </div>
    @endif
    </div>

    @elseif($tab === 'renewals')
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('listings.index') }}" class="row g-2 align-items-end" data-live-filter>
            <input type="hidden" name="tab" value="renewals">
            <div class="col-md-3">
                <label class="form-label">{{ __('Permit status') }}</label>
                <select name="permit_status" class="form-select form-select-sm">
                    <option value="">{{ __('All') }}</option>
                    <option value="expired" {{ request('permit_status') === 'expired' ? 'selected' : '' }}>{{ __('Expired') }}</option>
                    <option value="due" {{ request('permit_status') === 'due' ? 'selected' : '' }}>{{ __('Due within :n days', ['n' => $permitWindow]) }}</option>
                    <option value="ok" {{ request('permit_status') === 'ok' ? 'selected' : '' }}>{{ __('Valid') }}</option>
                    <option value="missing" {{ request('permit_status') === 'missing' ? 'selected' : '' }}>{{ __('Missing / no expiry') }}</option>
                </select>
            </div>
            @if(auth()->user()->isAdmin() && $agents->isNotEmpty())
            <div class="col-md-3">
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
                <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="{{ __('Title, unit, building, community...') }}">
            </div>
            <div class="col-auto ms-auto">
                @if(request()->hasAny(['permit_status', 'agent', 'search']))
                    <a href="{{ route('listings.index', ['tab' => 'renewals']) }}" class="btn btn-sm btn-outline-secondary">{{ __('Clear') }}</a>
                @endif
                <a href="{{ route('listings.export.permits', request()->except(['tab', 'page'])) }}" class="btn btn-sm btn-outline-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M12 11v6"/><path d="M9 14l3 3l3 -3"/></svg>
                    {{ __('Export CSV') }}
                </a>
            </div>
        </form>
    </div>

    <div data-live-results>
    <x-record-count :records="$renewals" :label="__('permits')" />
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>{{ __('Unit') }}</th>
                    <th>{{ __('Permit') }}</th>
                    <th>{{ __('Expiry') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Agent') }}</th>
                    <th>{{ __('Owner') }}</th>
                    <th class="w-1"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($renewals as $unit)
                @php $badge = $permitBadge($unit); @endphp
                <tr>
                    <td>
                        <div class="fw-bold"><a href="{{ route('inventory.show', $unit) }}">{{ $unit->display_name }}</a></div>
                        <div class="text-muted small">{{ collect([$unit->sub_community, $unit->community, $unit->city])->filter()->implode(' · ') }}</div>
                    </td>
                    <td>
                        <div>{{ $unit->rera_permit_no ?: '—' }}</div>
                        <div class="text-muted small">{{ $unit->permit_label }}</div>
                    </td>
                    <td class="text-nowrap">
                        @if($unit->permit_expires_at)
                            <div>{{ $unit->permit_expires_at->format('d M Y') }}</div>
                        @endif
                        <span class="badge {{ $badge['class'] }}">{{ $badge['text'] }}</span>
                    </td>
                    <td class="text-nowrap">{{ $unit->price_line }}</td>
                    <td>{{ $unit->assignedAgent->name ?? '-' }}</td>
                    <td>{{ $unit->owner->name ?? ($unit->owner_name ?: '-') }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('inventory.edit', $unit) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">{{ __('No listed units found.') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($renewals->hasPages())
    <div class="card-footer d-flex align-items-center">
        {{ $renewals->appends(request()->query())->links('vendor.pagination.tabler') }}
    </div>
    @endif
    </div>

    @else
    <div class="card-body">
        <div class="d-flex flex-wrap gap-3 mb-3">
            <span class="d-flex align-items-center gap-1"><span class="pcal-dot" style="background:#d63939;"></span> <small class="text-muted">{{ __('Expired') }}</small></span>
            <span class="d-flex align-items-center gap-1"><span class="pcal-dot" style="background:#f59f00;"></span> <small class="text-muted">{{ __('Due within :n days', ['n' => $permitWindow]) }}</small></span>
            <span class="d-flex align-items-center gap-1"><span class="pcal-dot" style="background:#17a2b8;"></span> <small class="text-muted">{{ __('Valid') }}</small></span>
        </div>
        <div class="d-flex align-items-center justify-content-between mb-2">
            <button type="button" class="btn btn-ghost-secondary btn-icon" id="pcal-prev" aria-label="{{ __('Previous') }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 6l-6 6l6 6"/></svg>
            </button>
            <h3 class="card-title mb-0" id="pcal-title"></h3>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="pcal-today">{{ __('Today') }}</button>
                <button type="button" class="btn btn-ghost-secondary btn-icon" id="pcal-next" aria-label="{{ __('Next') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 6l6 6l-6 6"/></svg>
                </button>
            </div>
        </div>
        <table class="table table-bordered mb-0">
            <thead>
                <tr>
                    @foreach($permitDows as $dow)
                        <th class="text-center">{{ $dow }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody id="pcal-body"></tbody>
        </table>
    </div>
    @endif
</div>
@endsection

@if($tab === 'calendar')
@push('styles')
<style>
.pcal-dot { display:inline-block; width:.6rem; height:.6rem; border-radius:50%; }
.pcal-day { min-height:90px; vertical-align:top; }
.pcal-event { display:block; font-size:.72rem; line-height:1.2; padding:.1rem .3rem; border-radius:.25rem; margin-top:.15rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#fff; text-decoration:none; }
.pcal-event.red { background:#d63939; }
.pcal-event.orange { background:#f59f00; }
.pcal-event.cyan { background:#17a2b8; }
.pcal-today { background:var(--tblr-primary-lt, #e9f0ff); }
</style>
@endpush
@push('scripts')
<script>
(function () {
    var body = document.getElementById('pcal-body');
    var title = document.getElementById('pcal-title');
    if (!body || !title) return;

    var events = [];
    var cursor = new Date();
    cursor.setDate(1);
    var feed = '{{ route('listings.permits.events') }}';
    var months = @json($permitMonths);

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function key(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    function load() {
        fetch(feed, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (list) { events = Array.isArray(list) ? list : []; render(); })
            .catch(function () { events = []; render(); });
    }

    function render() {
        var year = cursor.getFullYear(), month = cursor.getMonth();
        title.textContent = months[month] + ' ' + year;

        var startDow = new Date(year, month, 1).getDay();
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var today = key(new Date());
        var html = '';
        var day = 1 - startDow;

        while (day <= daysInMonth) {
            html += '<tr>';
            for (var i = 0; i < 7; i++, day++) {
                if (day < 1 || day > daysInMonth) {
                    html += '<td class="pcal-day text-muted"></td>';
                    continue;
                }
                var dk = key(new Date(year, month, day));
                html += '<td class="pcal-day' + (dk === today ? ' pcal-today' : '') + '"><div class="text-muted small">' + day + '</div>';
                events.forEach(function (ev) {
                    if (ev.date === dk) {
                        html += '<a class="pcal-event ' + (ev.color || 'cyan') + '" href="' + ev.url + '" title="' + String(ev.title).replace(/"/g, '&quot;') + '">' + ev.title + '</a>';
                    }
                });
                html += '</td>';
            }
            html += '</tr>';
        }
        body.innerHTML = html;
    }

    document.getElementById('pcal-prev').addEventListener('click', function () { cursor.setMonth(cursor.getMonth() - 1); render(); });
    document.getElementById('pcal-next').addEventListener('click', function () { cursor.setMonth(cursor.getMonth() + 1); render(); });
    document.getElementById('pcal-today').addEventListener('click', function () { cursor = new Date(); cursor.setDate(1); render(); });
    document.addEventListener('insulacrm:live-updated', render);

    load();
})();
</script>
@endpush
@endif
