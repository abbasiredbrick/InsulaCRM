@extends('layouts.app')

@section('title', __('Lead Board'))
@section('page-title', __('Lead Kanban Board'))

@push('styles')
<style>
    /* ── Board shell ───────────────────────────────────────────── */
    .kanban-toolbar { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; margin-bottom: 1rem; }

    .kanban-board {
        display: flex; gap: 14px; align-items: flex-start;
        overflow-x: auto; padding: 6px 2px 18px;
        min-height: calc(100vh - 210px);
        scrollbar-width: thin;
    }
    .kanban-board::-webkit-scrollbar { height: 10px; }
    .kanban-board::-webkit-scrollbar-thumb { background: var(--tblr-border-color); border-radius: 999px; }
    .kanban-board::-webkit-scrollbar-track { background: transparent; }

    /* ── Column ────────────────────────────────────────────────── */
    .kanban-column {
        --c: #0054a6;
        width: 300px; flex: 0 0 300px; max-width: 300px;
        display: flex; flex-direction: column;
        background: var(--tblr-secondary-bg);
        border: 1px solid var(--tblr-border-color-translucent);
        border-radius: 16px; overflow: hidden;
        transition: box-shadow .2s ease, border-color .2s ease;
    }
    .kanban-column.drag-over {
        border-color: var(--tblr-primary);
        box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--tblr-primary) 28%, transparent),
                    0 18px 36px -18px rgba(var(--tblr-primary-rgb), .45);
    }

    .kanban-col-head {
        display: flex; align-items: center; gap: 8px;
        padding: 14px 14px 12px;
        background-image: linear-gradient(90deg, color-mix(in srgb, var(--c) 10%, transparent), transparent 72%);
        border-bottom: 1px solid var(--tblr-border-color-translucent);
    }
    .kanban-col-dot {
        width: 9px; height: 9px; border-radius: 50%; background: var(--c); flex: none;
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 18%, transparent);
    }
    .kanban-col-label { font-weight: 600; font-size: 13px; color: var(--tblr-body-color); }
    .kanban-col-count {
        margin-left: auto; min-width: 22px; height: 22px; padding: 0 7px;
        display: inline-flex; align-items: center; justify-content: center;
        border-radius: 999px; font-size: 11px; font-weight: 700;
        color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent);
    }

    .kanban-column-body {
        flex: 1; padding: 12px; overflow-y: auto; min-height: 120px;
        max-height: calc(100vh - 340px); scrollbar-width: thin;
    }
    .kanban-column-body::-webkit-scrollbar { width: 6px; }
    .kanban-column-body::-webkit-scrollbar-thumb { background: var(--tblr-border-color); border-radius: 999px; }

    /* ── Card ──────────────────────────────────────────────────── */
    .kanban-card {
        background: var(--tblr-bg-surface);
        border: 1px solid var(--tblr-border-color-translucent);
        border-radius: 12px; padding: 12px 12px 10px;
        margin-bottom: 10px; cursor: grab; position: relative;
        box-shadow: 0 1px 2px rgba(16, 24, 40, .05);
        transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease, opacity .18s ease;
    }
    .kanban-card:hover {
        transform: translateY(-2px);
        border-color: color-mix(in srgb, var(--c) 40%, var(--tblr-border-color));
        box-shadow: 0 14px 28px -18px rgba(16, 24, 40, .35);
    }
    .kanban-card:active { cursor: grabbing; }
    .kanban-card.dragging { opacity: .5; transform: rotate(1.5deg) scale(.97) translateY(-2px); }

    .kanban-card-top { display: flex; align-items: flex-start; gap: 10px; }
    .kanban-avatar {
        --a: #2563eb; width: 36px; height: 36px; flex: 0 0 36px; border-radius: 11px;
        display: inline-flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 13px; color: #fff; letter-spacing: .02em; user-select: none;
        background: linear-gradient(135deg, var(--a), color-mix(in srgb, var(--a) 72%, #000));
        box-shadow: 0 3px 8px -3px color-mix(in srgb, var(--a) 60%, transparent);
    }
    .kanban-card-title { min-width: 0; flex: 1; }
    .kanban-card-title .kanban-name {
        font-weight: 600; font-size: 14px; color: var(--tblr-body-color); line-height: 1.25;
        display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .kanban-card-title .kanban-sub { font-size: 11px; color: var(--tblr-muted); }
    .kanban-dnc {
        flex: none; font-size: 9px; font-weight: 800; letter-spacing: .04em; color: var(--tblr-red);
        border: 1px solid color-mix(in srgb, var(--tblr-red) 40%, transparent);
        padding: 1px 5px; border-radius: 999px; margin-top: 2px;
    }

    .kanban-phone {
        display: inline-flex; align-items: center; gap: 6px; margin-top: 8px;
        font-size: 12px; color: var(--tblr-muted); text-decoration: none;
    }
    .kanban-phone:hover { color: var(--tblr-body-color); }
    .kanban-phone svg { color: var(--tblr-muted); }

    .kanban-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 9px; }
    .kanban-temp {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 999px;
    }
    .kanban-temp svg { width: 13px; height: 13px; }
    .kanban-temp.hot   { color: var(--tblr-red);    background: color-mix(in srgb, var(--tblr-red) 12%, transparent); }
    .kanban-temp.warm  { color: var(--tblr-orange); background: color-mix(in srgb, var(--tblr-orange) 12%, transparent); }
    .kanban-temp.cold  { color: var(--tblr-blue);   background: color-mix(in srgb, var(--tblr-blue) 12%, transparent); }

    .kanban-tag {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: 10px; font-weight: 600; padding: 3px 8px; border-radius: 999px;
        color: var(--tag); background: color-mix(in srgb, var(--tag) 11%, transparent);
    }
    .kanban-tag-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

    .kanban-card-foot {
        display: flex; align-items: center; gap: 8px; margin-top: 10px; padding-top: 9px;
        border-top: 1px dashed var(--tblr-border-color);
    }
    .kanban-meta { font-size: 10.5px; color: var(--tblr-muted); }
    .kanban-score {
        font-size: 10px; font-weight: 700; color: var(--tblr-purple);
        background: color-mix(in srgb, var(--tblr-purple) 11%, transparent);
        padding: 2px 7px; border-radius: 999px;
    }
    .kanban-agent { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: var(--tblr-muted); }
    .kanban-agent .mini {
        width: 18px; height: 18px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 9px; font-weight: 700; color: #fff;
    }
    .kanban-card-foot .dropdown { margin-left: auto; }
    .move-dropdown .btn { padding: 2px 5px; height: auto; line-height: 1; }

    .kanban-empty {
        display: flex; flex-direction: column; align-items: center; gap: 6px;
        padding: 26px 10px; color: var(--tblr-muted); text-align: center; font-size: 12px;
        border: 1px dashed var(--tblr-border-color-translucent); border-radius: 12px;
    }
    .kanban-empty svg { opacity: .45; }
    .kanban-hint { font-size: 12px; color: var(--tblr-muted); }

    /* ── Dark mode overrides ───────────────────────────────────── */
    body[data-bs-theme="dark"] .kanban-column { background: rgba(255, 255, 255, .035); border-color: rgba(255, 255, 255, .09); }
    body[data-bs-theme="dark"] .kanban-card { border-color: rgba(255, 255, 255, .09); }
    body[data-bs-theme="dark"] .kanban-card-foot { border-color: rgba(255, 255, 255, .08); }
    body[data-bs-theme="dark"] .kanban-empty { border-color: rgba(255, 255, 255, .12); }

    /* ── Responsive & motion ───────────────────────────────────── */
    @media (max-width: 768px) {
        .kanban-board { min-height: auto; }
        .kanban-column { width: 82vw; flex: 0 0 82vw; }
        .kanban-hint { display: none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .kanban-card, .kanban-card:hover, .kanban-column { transition: none; }
        .kanban-card:hover { transform: none; }
    }
</style>
@endpush

@section('content')
@php $filters = request()->only(['agent_id', 'search', 'source', 'temperature']); @endphp
<div class="kanban-toolbar">
    <div class="btn-group" role="group" aria-label="{{ __('View') }}">
        <a href="{{ route('leads.table', $filters) }}" class="btn btn-outline-primary btn-sm {{ request()->routeIs('leads.table') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
            {{ __('Table') }}
        </a>
        <a href="{{ route('leads.index', $filters) }}" class="btn btn-outline-primary btn-sm {{ request()->routeIs('leads.index', 'leads.kanban') ? 'active' : '' }}">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><rect x="3" y="3" width="7" height="18" rx="1"/><rect x="10" y="3" width="4" height="12" rx="1"/><rect x="15" y="3" width="6" height="7" rx="1"/></svg>
            {{ __('Kanban') }}
        </a>
    </div>
    <span class="kanban-hint ms-auto">{{ __('Drag cards to change status') }}</span>
</div>

<div class="card mb-3">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('leads.index') }}" class="row g-2" data-live-filter>
            <div class="col-md-4 col-lg-5">
                <label for="kanban-search" class="visually-hidden">{{ __('Search') }}</label>
                <input type="text" name="search" id="kanban-search" class="form-control" placeholder="{{ __('Search name, phone, email, reference...') }}" value="{{ request('search') }}">
            </div>
            <div class="col-md-3 col-lg-2">
                <label for="kanban-source" class="visually-hidden">{{ __('Lead Source') }}</label>
                <select name="source" id="kanban-source" class="form-select">
                    <option value="">{{ __('All Sources') }}</option>
                    @foreach(\App\Services\CustomFieldService::getOptions('lead_source') as $val => $label)
                        <option value="{{ $val }}" {{ request('source') == $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label for="kanban-temperature" class="visually-hidden">{{ __('Temperature') }}</label>
                <select name="temperature" id="kanban-temperature" class="form-select">
                    <option value="">{{ __('All Temperatures') }}</option>
                    <option value="hot" {{ request('temperature') === 'hot' ? 'selected' : '' }}>{{ __('Hot') }}</option>
                    <option value="warm" {{ request('temperature') === 'warm' ? 'selected' : '' }}>{{ __('Warm') }}</option>
                    <option value="cold" {{ request('temperature') === 'cold' ? 'selected' : '' }}>{{ __('Cold') }}</option>
                </select>
            </div>
            @if($agents->isNotEmpty())
            <div class="col-md-3 col-lg-2">
                <label for="kanban-agent" class="visually-hidden">{{ __('Agent') }}</label>
                <select name="agent_id" id="kanban-agent" class="form-select">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ request('agent_id') == $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            @if(request()->hasAny(['search', 'source', 'temperature', 'agent_id']))
            <div class="col-auto">
                <a href="{{ route('leads.index') }}" class="btn btn-outline-secondary">{{ __('Clear') }}</a>
            </div>
            @endif
        </form>
    </div>
</div>

@php
$accents = ['#0054a6','#2fb344','#f76707','#d63939','#7a4ddc','#17a2b8','#db2777','#65a30d','#0ca678','#4f46e5','#f59e0b','#dc2626'];
$avatarColors = ['#2563eb','#7c3aed','#db2777','#059669','#d97706','#0891b2','#4f46e5','#c026d3','#0ca678','#dc2626'];
@endphp

<div data-live-results>
<div class="kanban-board">
    @foreach($statuses as $slug => $label)
    @php
        $accent = $accents[$loop->index % count($accents)];
        $statusLeads = $leads->get($slug, collect());
    @endphp
    <div class="kanban-column" style="--c: {{ $accent }};" data-status="{{ $slug }}" aria-label="{{ __('Column:') }} {{ $label }}">
        <div class="kanban-col-head">
            <span class="kanban-col-dot"></span>
            <span class="kanban-col-label">{{ $label }}</span>
            <span class="kanban-col-count kanban-count">{{ $statusLeads->count() }}</span>
        </div>
        <div class="kanban-column-body">
            @foreach($statusLeads as $lead)
            @php
                $avatarColor = $avatarColors[$lead->id % count($avatarColors)];
                $initials = collect(preg_split('/\s+/', trim($lead->full_name ?? '')))->filter()->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
            @endphp
            <div class="kanban-card" draggable="true" data-lead-id="{{ $lead->id }}" aria-roledescription="draggable item">
                <div class="kanban-card-top">
                    <span class="kanban-avatar" style="--a: {{ $avatarColor }};" aria-hidden="true">{{ $initials }}</span>
                    <div class="kanban-card-title">
                        <a class="kanban-name text-reset text-decoration-none" href="{{ route('leads.show', $lead) }}">{{ $lead->full_name }}</a>
                        @if($lead->created_at)
                        <span class="kanban-sub">{{ __('Added') }} {{ $lead->created_at->diffForHumans() }}</span>
                        @endif
                    </div>
                    @if($lead->do_not_contact)
                    <span class="kanban-dnc" title="{{ __('Do Not Contact') }}">DNC</span>
                    @endif
                </div>

                @if($lead->phone)
                <a class="kanban-phone" href="tel:{{ $lead->phone }}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2"/><line x1="15" y1="5" x2="18" y2="5"/><line x1="17" y1="3" x2="17" y2="7"/></svg>
                    {{ $lead->phone }}
                </a>
                @endif

                @if($lead->temperature || $lead->tags->count())
                <div class="kanban-chips">
                    @if($lead->temperature)
                    <span class="kanban-temp {{ $lead->temperature }}">
                        @if($lead->temperature === 'hot')<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12c2-2.96 0-7-1-8 0 3.038-1.773 4.741-3 6-1.226 1.26-2 3.24-2 5a6 6 0 1 0 12 0c0-1.532-1.056-3.94-2-5-1.786 3-2.791 3-4 2z"/></svg>@elseif($lead->temperature === 'warm')<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="4"/><path d="M3 12h1m8-9v1m8 8h1m-9 8v1m-6.4-15.4l.7.7m12.1-.7l-.7.7m0 11.4l.7.7m-12.1-.7l-.7.7"/></svg>@else<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 4l2 1l2-1"/><path d="M12 2v6.5l3 1.72"/><path d="M17.928 6.268l.134 2.232l1.866 1.232"/><path d="M20.66 7l-5.629 3.25l.01 3.458"/><path d="M19.928 14.268l-1.866 1.232l-.134 2.232"/><path d="M20.66 17l-5.629-3.25l-2.99 1.738"/><path d="M14 20l-2-1l-2 1"/><path d="M12 22v-6.5l-3-1.72"/><path d="M6.072 17.732l-.134-2.232l-1.866-1.232"/><path d="M3.34 17l5.629-3.25l-.01-3.458"/><path d="M4.072 9.732l1.866-1.232l.134-2.232"/><path d="M3.34 7l5.629 3.25l2.99-1.738"/></svg>@endif
                        {{ __(ucfirst($lead->temperature)) }}
                    </span>
                    @endif
                    @foreach($lead->tags as $tag)
                    <span class="kanban-tag" style="--tag: var(--tblr-{{ $tag->color }});">
                        <span class="kanban-tag-dot"></span>{{ $tag->name }}
                    </span>
                    @endforeach
                </div>
                @endif

                <div class="kanban-card-foot">
                    @if($lead->agent)
                    <span class="kanban-agent">
                        <span class="mini text-bg-dark">{{ mb_substr($lead->agent->name, 0, 1) }}</span>
                        {{ $lead->agent->name }}
                    </span>
                    @endif
                    @if(!is_null($lead->motivation_score))
                    <span class="kanban-score" title="{{ __('Motivation score') }}">{{ __('Score') }} {{ $lead->motivation_score }}</span>
                    @endif
                    <div class="dropdown move-dropdown">
                        <button class="btn btn-sm btn-ghost-secondary btn-icon" data-bs-toggle="dropdown" aria-label="{{ __('Move to') }}" title="{{ __('Move to') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12h14"/><path d="M15 16l4 -4"/><path d="M15 8l4 4"/></svg>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @foreach($statuses as $moveSlug => $moveLabel)
                                @if($moveSlug !== $slug)
                                <a href="#" class="dropdown-item move-lead-btn" data-lead-id="{{ $lead->id }}" data-status="{{ $moveSlug }}">{{ $moveLabel }}</a>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            @endforeach

            @if($statusLeads->isEmpty())
            <div class="kanban-empty empty-msg" data-empty-col="1">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="17" x2="14" y2="17"/><line x1="8" y1="7" x2="12" y2="7"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>
                {{ __('No leads yet') }}
            </div>
            @endif
        </div>
    </div>
    @endforeach
    </div>
    </div>

@push('scripts')
<script>
(function() {
    const emptyHtml = '<div class="kanban-empty empty-msg"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="17" x2="14" y2="17"/><line x1="8" y1="7" x2="12" y2="7"/><rect x="3" y="3" width="18" height="18" rx="3"/></svg>{{ __("No leads yet") }}</div>';
    let dragLeadId = null;
    let dragSourceCol = null;

    function closestOrNull(el, sel) {
        return el && el.closest ? el.closest(sel) : null;
    }

    document.addEventListener('dragstart', function(e) {
        const card = closestOrNull(e.target, '.kanban-card');
        if (!card) return;
        dragLeadId = card.dataset.leadId;
        dragSourceCol = card.closest('.kanban-column');
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });

    document.addEventListener('dragend', function(e) {
        const card = closestOrNull(e.target, '.kanban-card');
        if (!card) return;
        card.classList.remove('dragging');
        document.querySelectorAll('.kanban-column').forEach(c => c.classList.remove('drag-over'));
    });

    document.addEventListener('dragover', function(e) {
        const col = closestOrNull(e.target, '.kanban-column');
        if (!col) return;
        e.preventDefault();
        col.classList.add('drag-over');
    });

    document.addEventListener('dragleave', function(e) {
        const col = closestOrNull(e.target, '.kanban-column');
        if (!col) return;
        if (!col.contains(e.relatedTarget)) col.classList.remove('drag-over');
    });

    document.addEventListener('drop', function(e) {
        const col = closestOrNull(e.target, '.kanban-column');
        if (!col || !dragLeadId) return;
        e.preventDefault();
        col.classList.remove('drag-over');

        const newStatus = col.dataset.status;
        const card = document.querySelector('.kanban-card[data-lead-id="' + dragLeadId + '"]');
        if (!card) return;
        const body = col.querySelector('.kanban-column-body');
        const empty = body.querySelector('.empty-msg');
        if (empty) empty.remove();

        body.appendChild(card);
        updateCounts();

        const srcBody = dragSourceCol.querySelector('.kanban-column-body');
        if (!srcBody.querySelector('.kanban-card')) {
            srcBody.innerHTML = emptyHtml;
        }

        fetch('{{ url("/leads") }}/' + dragLeadId + '/status', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ status: newStatus })
        }).then(r => {
            if (!r.ok) {
                dragSourceCol.querySelector('.kanban-column-body').appendChild(card);
                const em = body.querySelector('.empty-msg');
                if (!body.querySelector('.kanban-card') && !em) {
                    body.innerHTML = emptyHtml;
                }
                updateCounts();
                showToast('{{ __("Failed to update lead status") }}', 'danger');
            }
        });

        dragLeadId = null;
        dragSourceCol = null;
    });

    function updateCounts() {
        document.querySelectorAll('.kanban-column').forEach(function(col) {
            const count = col.querySelectorAll('.kanban-card').length;
            col.querySelector('.kanban-count').textContent = count;
        });
    }

    function showToast(msg, type) {
        const toast = document.createElement('div');
        toast.className = 'alert alert-' + (type || 'info') + ' position-fixed bottom-0 end-0 m-3';
        toast.style.zIndex = 9999;
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    document.addEventListener('click', function(e) {
        const btn = closestOrNull(e.target, '.move-lead-btn');
        if (!btn) return;
        e.preventDefault();
        var leadId = btn.dataset.leadId;
        var status = btn.dataset.status;
        fetch('{{ url("/leads") }}/' + leadId + '/status', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ status: status })
        }).then(function(r) {
            if (r.ok) location.reload();
            else showToast('{{ __("Failed to update lead status") }}', 'danger');
        });
    });
})();
</script>
@endpush
@endsection