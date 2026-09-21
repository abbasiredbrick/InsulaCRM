@extends('layouts.app')

@section('title', __('Scheduling Hub'))
@section('page-title', __('Schedules'))

@section('content')
@php
    $tabCounts = [
        'viewings' => $items->where('type', 'viewing')->count(),
        'tasks' => $items->where('type', 'task')->count(),
        'meetings' => $items->where('type', 'meeting')->count(),
    ];
    $defaultType = in_array($filters['type'], ['viewings', 'tasks', 'meetings'], true) ? $filters['type'] : null;
    $preselectedLeadName = $preselectedLead ? trim($preselectedLead->first_name.' '.$preselectedLead->last_name) : '';
@endphp
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Scheduling Hub') }}</h3>
        <div class="card-actions">
            @if($filters['lead'] && $leadOptions->isNotEmpty())
            <span class="badge bg-blue-lt me-2">
                {{ __('Lead') }}: {{ $preselectedLeadName }}
                <a href="{{ route('schedules.index') }}" class="text-reset text-decoration-none ms-1" title="{{ __('Clear') }}">&times;</a>
            </span>
            @endif
            <a href="{{ route('showings.create') }}" class="btn btn-outline-orange btn-sm me-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><rect x="4" y="5" width="16" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="4" y1="11" x2="20" y2="11"/><line x1="11" y1="15" x2="12" y2="15"/><line x1="12" y1="15" x2="12" y2="18"/></svg>
                {{ __('Schedule Viewing') }}
            </a>
            <a href="#" class="btn btn-outline-purple btn-sm me-1" data-bs-toggle="modal" data-bs-target="#createMeetingModal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                {{ __('Schedule Meeting') }}
            </a>
            <a href="#" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTaskModal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>
                {{ __('Add Task') }}
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom py-3">
        <form method="GET" action="{{ route('schedules.index') }}" class="row g-2 align-items-end" data-live-filter>
            @if($filters['lead'])
            <input type="hidden" name="lead" value="{{ $filters['lead'] }}">
            @endif
            <input type="hidden" name="type" value="{{ $defaultType ?? 'all' }}">
            <div class="col-md-2">
                <label class="form-label" for="sched-filter-status">{{ __('Status') }}</label>
                <select name="status" id="sched-filter-status" class="form-select form-select-sm">
                    <option value="">{{ __('All Statuses') }}</option>
                    @foreach(['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No Show'] as $key => $label)
                        <option value="{{ $key }}" {{ $filters['status'] === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="sched-filter-from">{{ __('From') }}</label>
                <input type="date" name="from" id="sched-filter-from" class="form-control form-control-sm" value="{{ $filters['from'] }}">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="sched-filter-to">{{ __('To') }}</label>
                <input type="date" name="to" id="sched-filter-to" class="form-control form-control-sm" value="{{ $filters['to'] }}">
            </div>
            @if($agents->isNotEmpty())
            <div class="col-md-2">
                <label class="form-label" for="sched-filter-agent">{{ __('Agent') }}</label>
                <select name="agent" id="sched-filter-agent" class="form-select form-select-sm">
                    <option value="">{{ __('All Agents') }}</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" {{ (string) $filters['agent'] === (string) $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="col-md-3">
                <label class="form-label" for="sched-filter-search">{{ __('Search') }}</label>
                <input type="text" name="search" id="sched-filter-search" class="form-control form-control-sm" value="{{ $filters['search'] }}" placeholder="{{ __('Lead, phone, unit, location, source...') }}">
            </div>
            @if(request()->hasAny(['status', 'from', 'to', 'agent', 'lead', 'search']))
            <div class="col-auto">
                <a href="{{ route('schedules.index') }}{{ $filters['lead'] ? '?lead=' . $filters['lead'] : '' }}" class="btn btn-sm btn-outline-secondary">{{ __('Reset') }}</a>
            </div>
            @endif
        </form>
    </div>

    {{-- Tabs --}}
    <div data-live-results>
    <div class="card-body border-bottom p-0">
        <ul class="nav nav-tabs border-bottom-0" role="tablist" id="sched-tabs">
            <li class="nav-item" role="presentation">
                <a class="nav-link {{ $defaultType === null ? 'active' : '' }}" data-sched-tab="all" href="#" role="tab">{{ __('Upcoming & Recent') }} ({{ $items->count() }})</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link {{ $defaultType === 'viewings' ? 'active' : '' }}" data-sched-tab="viewing" href="#" role="tab">{{ __('Viewings') }} ({{ $tabCounts['viewings'] }})</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link {{ $defaultType === 'tasks' ? 'active' : '' }}" data-sched-tab="task" href="#" role="tab">{{ __('Tasks') }} ({{ $tabCounts['tasks'] }})</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link {{ $defaultType === 'meetings' ? 'active' : '' }}" data-sched-tab="meeting" href="#" role="tab">{{ __('Meetings') }} ({{ $tabCounts['meetings'] }})</a>
            </li>
        </ul>
    </div>

    <div class="list-group list-group-flush" id="sched-list">
        @forelse($items as $item)
        @php
            $lead = $item['lead'];
            $label = ['viewing' => __('Viewing'), 'task' => __('Task'), 'meeting' => __('Meeting')][$item['type']];
            $icon = $item['type'] === 'viewing'
                ? '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg>'
                : ($item['type'] === 'task'
                    ? '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2"/><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z"/><path d="M9 14l2 2l4 -4"/></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/></svg>');
            $typeColor = ['viewing' => 'bg-orange-lt', 'task' => 'bg-cyan-lt', 'meeting' => 'bg-purple-lt'][$item['type']];
            $subtitle = '';
            if ($item['type'] === 'viewing') {
                $subtitle = trim(implode(' · ', array_filter([$item['subtitle'], $item['agent']])));
            } else {
                $subtitle = trim(implode(' · ', array_filter([$item['subtitle'], $item['agent']])));
            }
        @endphp
        <div class="list-group-item" data-sched-row="{{ $item['type'] }}">
            <div class="row align-items-center g-2">
                <div class="col-auto">
                    <span class="avatar avatar-sm {{ $typeColor }}">{!! $icon !!}</span>
                </div>
                <div class="col">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <a href="{{ $item['view_url'] ?? '#' }}" class="fw-semibold text-reset text-truncate" style="max-width: 320px;" {{ ($item['view_url'] ?? null) ? '' : 'onclick="return false;"' }}>{{ $item['title'] }}</a>
                        @if($lead)
                        <a href="{{ route('leads.show', $lead) }}" class="badge bg-blue-lt text-decoration-none">{{ $lead->first_name }} {{ $lead->last_name }}</a>
                        @if($lead->phone)<span class="small text-secondary">{{ $lead->phone }}</span>@endif
                        @endif
                    </div>
                    <div class="small text-secondary text-truncate">{{ $subtitle }}</div>
                    @if($item['feedback'])
                    <div class="small text-secondary mt-1" style="white-space:pre-line;">{{ $item['feedback'] }}</div>
                    @endif
                </div>
                <div class="col-auto text-center d-none d-md-block" style="min-width: 170px;">
                    <div class="fw-semibold">{{ $item['at'] ? $item['at']->format('M d, Y') : '-' }}</div>
                    @if($item['at'] && $item['type'] !== 'task')
                    <div class="small text-secondary">{{ $item['at']->format('g:i A') }}</div>
                    @endif
                    @if($item['type'] === 'task')<div class="small text-secondary">{{ $item['at'] ? $item['at']->format('g:i A') : '' }}</div>@endif
                    @if($item['creator']) <div class="small text-muted">{{ __('by') }} {{ $item['creator'] }}</div> @endif
                </div>
                <div class="col-auto">
                    <span class="badge bg-{{ $item['status_color'] }}">{{ $item['status_label'] }}</span>
                    @if($item['outcome'])
                    <span class="badge bg-yellow-lt">{{ $item['outcome'] }}</span>
                    @endif
                </div>
                <div class="col-auto">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-ghost-secondary btn-icon" data-bs-toggle="dropdown" aria-expanded="false" title="{{ __('Actions') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/><circle cx="12" cy="5" r="1"/></svg>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            @if($item['view_url'])
                            <a class="dropdown-item" href="{{ $item['view_url'] }}">{{ __('View') }}</a>
                            @endif
                            @if($lead && in_array($item['type'], ['meeting', 'task'], true))
                            <a class="dropdown-item" href="{{ route('leads.show', $lead) }}#{{ $item['type'] === 'task' ? 'task-' . $item['id'] : 'meeting-' . $item['id'] }}">{{ __('Open on lead') }}</a>
                            @endif
                            @if($item['type'] === 'viewing')
                            <a class="dropdown-item" href="{{ $item['edit_url'] }}">{{ __('Edit') }}</a>
                            @elseif(in_array($item['type'], ['meeting', 'task'], true))
                            <a class="dropdown-item sched-edit-btn" href="#" data-type="{{ $item['type'] }}" data-id="{{ $item['id'] }}" data-edit-url="{{ route('schedules.edit', ['type' => $item['type'], 'id' => $item['id']]) }}">{{ __('Edit') }}</a>
                            @endif
                            <button type="button" class="dropdown-item sched-feedback-btn" data-feedback-url="{{ route($item['feedback_route'], $item['entity']) }}" data-feedback-type="{{ $item['type'] }}" data-feedback-title="{{ $item['title'] }}">{{ __('Log feedback') }}</button>

                            @php
                                $statusOptions = [
                                    'viewing' => ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No Show'],
                                    'meeting' => ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
                                    'task' => ['scheduled' => 'Scheduled', 'completed' => 'Completed', 'cancelled' => 'Cancelled'],
                                ];
                                $statusUrl = $item['type'] === 'meeting'
                                    ? route('meetings.update', $item['entity'])
                                    : ($item['type'] === 'task'
                                        ? route('schedules.task.status', $item['entity'])
                                        : route('schedules.showing.status', $item['entity']));
                                $deleteUrl = $item['type'] === 'meeting'
                                    ? route('schedules.meeting.delete', $item['entity'])
                                    : ($item['type'] === 'task'
                                        ? route('schedules.task.delete', $item['entity'])
                                        : route('schedules.showing.delete', $item['entity']));
                            @endphp
                            <div class="dropdown-header" style="font-size: 0.7rem;">{{ __('Mark status') }}</div>
                            @foreach($statusOptions[$item['type']] as $sKey => $sLabel)
                            @if($sKey !== $item['status'])
                            <form method="POST" action="{{ $statusUrl }}" class="sched-status-form">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ $sKey }}">
                                <button type="submit" class="dropdown-item text-capitalize">{{ $sLabel }}</button>
                            </form>
                            @endif
                            @endforeach

                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ $deleteUrl }}" class="sched-delete-form">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger">{{ __('Delete') }}</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @empty
        <div class="list-group-item text-center text-secondary py-5">{{ __('Nothing scheduled yet. Schedule a viewing, meeting or task to get started.') }}</div>
        @endforelse
    </div>
    </div>
</div>

{{-- ── Create Meeting Modal ─────────────────────────────────────────── --}}
<div class="modal fade" id="createMeetingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('schedules.meetings.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Schedule Meeting') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">{{ __('Lead') }} <span class="text-danger">*</span></label>
                    <x-searchable-select
                        name="lead_id"
                        :options="$leadOptions"
                        :selected="$filters['lead']"
                        :placeholder="__('Search lead...')"
                        :search-placeholder="__('Search by name, phone, email or reference...')"
                        :remote="route('schedules.leads.search')"
                    />
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Title') }} <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="{{ __('e.g. Kickoff call, Lease handover, Contract signing') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Date & time') }} <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="scheduled_at" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Duration (min)') }}</label>
                        <input type="number" name="duration_minutes" class="form-control" value="60" min="15" max="480" step="5">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Reminder (min)') }}</label>
                        <input type="number" name="reminder_minutes" class="form-control" placeholder="{{ __('Before the meeting') }}">
                    </div>
                    @if(auth()->user()->isAdmin() || auth()->user()->isManager())
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Assigned agent') }}</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">{{ __('Lead owner / me') }}</option>
                            @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                    <div class="col-12">
                        <label class="form-label">{{ __('Notes') }}</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Schedule Meeting') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Create Task Modal ───────────────────────────────────────────── --}}
<div class="modal fade" id="createTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('schedules.tasks.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Add Task') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">{{ __('Lead') }} <span class="text-danger">*</span></label>
                    <x-searchable-select
                        name="lead_id"
                        :placeholder="__('Search lead...')"
                        :search-placeholder="__('Search by name, phone, email or reference...')"
                        :remote="route('schedules.leads.search')"
                    />
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Title') }} <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="{{ __('e.g. Follow up, Send documents, Confirm availability') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Due date') }} <span class="text-danger">*</span></label>
                        <input type="date" name="due_date" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Due time') }}</label>
                        <input type="time" name="due_time" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Reminder (min)') }}</label>
                        <input type="number" name="reminder_minutes" class="form-control" placeholder="{{ __('Before due') }}">
                    </div>
                    @if(auth()->user()->isAdmin() || auth()->user()->isManager())
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Assigned agent') }}</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">{{ __('Lead owner / me') }}</option>
                            @foreach($agents as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Add Task') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Feedback Modal ──────────────────────────────────────────────── --}}
<div class="modal fade" id="feedbackModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="#" class="modal-content" id="feedbackForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="feedbackModalTitle">{{ __('Log feedback') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">{{ __('Status') }}</label>
                    <select name="status" class="form-select" id="feedbackStatus">
                        <option value="">{{ __('Keep current') }}</option>
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label">{{ __('Feedback') }} <span class="text-danger">*</span></label>
                    <textarea name="feedback" class="form-control" rows="4" required placeholder="{{ __('Client feedback / notes...') }}"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Save feedback') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Meeting Modal ──────────────────────────────────────────── --}}
<div class="modal fade" id="editMeetingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('meetings.update', 0) }}" class="modal-content" id="editMeetingForm">
            @csrf
            @method('PATCH')
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Edit Meeting') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Title') }}</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Date & time') }}</label>
                        <input type="datetime-local" name="scheduled_at" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Duration (min)') }}</label>
                        <input type="number" name="duration_minutes" class="form-control" min="15" max="480">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ __('Status') }}</label>
                        <select name="status" class="form-select">
                            @foreach(\App\Models\Meeting::STATUSES as $sKey => $sLabel)
                            <option value="{{ $sKey }}">{{ $sLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ __('Notes') }}</label>
                        <textarea name="notes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Save changes') }}</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Task Modal ─────────────────────────────────────────────── --}}
<div class="modal fade" id="editTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('tasks.update', 0) }}" class="modal-content" id="editTaskForm">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Edit Task') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Title') }}</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Due date') }}</label>
                        <input type="date" name="due_date" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ __('Due time') }}</label>
                        <input type="time" name="due_time" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Status') }}</label>
                        <select name="status" class="form-select">
                            @foreach(\App\Models\Task::STATUSES as $sKey => $sLabel)
                            <option value="{{ $sKey }}">{{ $sLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('Save changes') }}</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // ── Tabs: filter the single chronological feed client-side ──
    var currentTab = '{{ $defaultType ?? 'all' }}';
    var TABS_SEL = '#sched-tabs [data-sched-tab]';
    var ROWS_SEL = '[data-sched-row]';

    function applyTab(type) {
        currentTab = type;
        document.querySelectorAll(ROWS_SEL).forEach(function (row) {
            row.style.display = (type === 'all' || row.dataset.schedRow === type) ? '' : 'none';
        });
        document.querySelectorAll(TABS_SEL).forEach(function (tab) {
            tab.classList.toggle('active', tab.dataset.schedTab === type);
        });
    }

    // ── Feedback modal ──
    function openFeedback(btn) {
        var form = document.getElementById('feedbackForm');
        form.action = btn.dataset.feedbackUrl;
        document.getElementById('feedbackModalTitle').textContent =
            (btn.dataset.feedbackType.charAt(0).toUpperCase() + btn.dataset.feedbackType.slice(1)) + ': ' + btn.dataset.feedbackTitle;
        document.getElementById('feedbackStatus').value = '';
        form.querySelector('[name=feedback]').value = '';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('feedbackModal')).show();
    }

    // ── Edit modals (meeting / task) ──
    function openEdit(btn) {
        var type = btn.dataset.type;
        var modalEl = document.getElementById(type === 'meeting' ? 'editMeetingModal' : 'editTaskModal');
        var form = document.getElementById(type === 'meeting' ? 'editMeetingForm' : 'editTaskForm');
        fetch(btn.dataset.editUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var ent = data.entity;
                form.action = (type === 'meeting' ? '{{ route('meetings.update', 0) }}' : '{{ route('tasks.update', 0) }}').replace('/0', '/' + ent.id);
                form.querySelector('[name=title]').value = ent.title || '';
                if (type === 'meeting') {
                    form.querySelector('[name=scheduled_at]').value = ent.scheduled_at || '';
                    form.querySelector('[name=duration_minutes]').value = ent.duration_minutes || '';
                    form.querySelector('[name=notes]').value = ent.notes || '';
                    form.querySelector('[name=status]').value = 'scheduled';
                } else {
                    form.querySelector('[name=due_date]').value = ent.due_date || '';
                    form.querySelector('[name=due_time]').value = ent.due_time || '';
                    form.querySelector('[name=status]').value = 'scheduled';
                }
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
    }

    // Delegated handlers survive the live-filter swap of the results region.
    document.addEventListener('click', function (e) {
        if (!(e.target instanceof Element)) { return; }
        var tab = e.target.closest(TABS_SEL);
        if (tab) { e.preventDefault(); applyTab(tab.dataset.schedTab); return; }
        var fbtn = e.target.closest('.sched-feedback-btn');
        if (fbtn) { openFeedback(fbtn); return; }
        var ebtn = e.target.closest('.sched-edit-btn');
        if (ebtn) { e.preventDefault(); openEdit(ebtn); }
    });

    // ── Delete confirm ──
    document.addEventListener('submit', function (e) {
        if (!(e.target instanceof Element)) { return; }
        if (e.target.closest('form.sched-delete-form') && !window.confirm('{{ __('Delete this item? This cannot be undone.') }}')) {
            e.preventDefault();
        }
    });

    applyTab(currentTab);

    // Re-apply the active tab after the results region is swapped in.
    document.addEventListener('insulacrm:live-updated', function () {
        applyTab(currentTab);
    });
})();
</script>
@endpush