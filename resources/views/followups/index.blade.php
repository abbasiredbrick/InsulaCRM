@extends('layouts.app')

@section('title', __('Follow-ups'))
@section('page-title', __('Follow-ups'))

@section('breadcrumbs')
<li class="breadcrumb-item"><a href="{{ route('leads.show', $lead) }}">{{ $lead->full_name }}</a></li>
<li class="breadcrumb-item active" aria-current="page">{{ __('Follow-ups') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ __('Viewings · Tasks · Meetings') }}</h3>
                <div class="card-actions text-secondary small">
                    {{ $lead->name ?? $lead->full_name }}
                </div>
            </div>
            <div class="card-header p-0 border-bottom-0">
                <ul class="nav nav-tabs border-bottom-0" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active" data-bs-toggle="tab" href="#tab-viewings" role="tab">{{ __('Viewings') }} ({{ $lead->showings->count() }})</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" data-bs-toggle="tab" href="#tab-tasks" role="tab">{{ __('Tasks') }} ({{ $lead->tasks->count() }})</a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a class="nav-link" data-bs-toggle="tab" href="#tab-meetings" role="tab">{{ __('Meetings') }} ({{ $lead->meetings->count() }})</a>
                    </li>
                </ul>
            </div>

            <div class="tab-content">
                {{-- ── Viewings ─────────────────────────────────────── --}}
                <div class="tab-pane fade active show" id="tab-viewings" role="tabpanel">
                    <div class="card-body">
                        @forelse($lead->showings->sortByDesc('showing_date') as $showing)
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3 pb-3 border-bottom">
                            <div>
                                <a href="{{ route('showings.show', $showing) }}" class="fw-semibold text-reset">
                                    {{ $showing->property->address ?? __('Unit #:id', ['id' => $showing->property_id]) }}
                                </a>
                                <div class="small text-secondary">
                                    {{ $showing->showing_date->format('M d, Y') }} {{ $showing->showing_time }}
                                    @if($showing->agent) • {{ $showing->agent->name }} @endif
                                </div>
                                <div class="mt-1">
                                    <span class="badge {{ $showing->status === 'completed' ? 'bg-green-lt' : ($showing->status === 'scheduled' ? 'bg-orange-lt' : 'bg-secondary-lt') }}">{{ \App\Models\Showing::statusLabel($showing->status) }}</span>
                                    @if($showing->outcome)
                                    <span class="badge bg-blue-lt">{{ \App\Models\Showing::outcomeLabel($showing->outcome) }}</span>
                                    @endif
                                </div>
                                @if($showing->feedback)
                                <div class="small text-secondary mt-1" style="white-space:pre-line;">{{ $showing->feedback }}</div>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('followups.viewing.feedback', $showing) }}" class="flex-fill ms-3">
                                @csrf
                                <textarea name="feedback" class="form-control form-control-sm" rows="2" placeholder="{{ __('Client feedback...') }}" required>{{ old('feedback') }}</textarea>
                                <button type="submit" class="btn btn-sm btn-outline-primary mt-2">{{ __('Log feedback') }}</button>
                            </form>
                        </div>
                        @empty
                        <p class="text-secondary mb-0">{{ __('No viewings scheduled yet.') }}</p>
                        @endforelse
                    </div>
                </div>

                {{-- ── Tasks ───────────────────────────────────────── --}}
                <div class="tab-pane fade" id="tab-tasks" role="tabpanel">
                    <div class="card-body">
                        @forelse($lead->tasks->sortBy(fn($t) => $t->dueAt()) as $task)
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3 pb-3 border-bottom">
                            <div>
                                <div class="{{ $task->is_completed ? 'text-decoration-line-through text-secondary' : 'fw-semibold' }}">{{ $task->title }}</div>
                                <div class="small text-secondary">
                                    {{ __('Due') }}: {{ $task->due_label }}
                                    @if($task->agent) • {{ $task->agent->name }} @endif
                                </div>
                                @if($task->activities->isNotEmpty())
                                <div class="small text-secondary mt-1">
                                    @foreach($task->activities as $ta)
                                    <div>- {{ $ta->body }} <span class="text-muted">({{ $ta->agent?->name ?? __('System') }}, {{ $ta->created_at?->diffForHumans() }})</span></div>
                                    @endforeach
                                </div>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('followups.task.feedback', $task) }}" class="flex-fill ms-3">
                                @csrf
                                <textarea name="feedback" class="form-control form-control-sm" rows="2" placeholder="{{ __('Progress / feedback...') }}" required>{{ old('feedback') }}</textarea>
                                <button type="submit" class="btn btn-sm btn-outline-primary mt-2">{{ __('Log feedback') }}</button>
                            </form>
                        </div>
                        @empty
                        <p class="text-secondary mb-0">{{ __('No tasks yet.') }}</p>
                        @endforelse
                    </div>
                </div>

                {{-- ── Meetings ────────────────────────────────────── --}}
                <div class="tab-pane fade" id="tab-meetings" role="tabpanel">
                    <div class="card-body">
                        @forelse($lead->meetings->sortBy('scheduled_at') as $meeting)
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3 pb-3 border-bottom">
                            <div>
                                <div class="{{ $meeting->status === 'completed' ? 'text-decoration-line-through text-secondary' : 'fw-semibold' }}">{{ $meeting->title }}</div>
                                <div class="small text-secondary">
                                    {{ $meeting->scheduled_at->format('M d, Y g:i A') }}
                                    @if($meeting->agent) • {{ $meeting->agent->name }} @endif
                                </div>
                                <div class="mt-1">
                                    <span class="badge bg-secondary-lt">{{ \App\Models\Meeting::statusLabel($meeting->status) }}</span>
                                </div>
                                @if($meeting->feedback)
                                <div class="small text-secondary mt-1" style="white-space:pre-line;">{{ $meeting->feedback }}</div>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('followups.meeting.feedback', $meeting) }}" class="flex-fill ms-3">
                                @csrf
                                <textarea name="feedback" class="form-control form-control-sm" rows="2" placeholder="{{ __('Meeting outcome / feedback...') }}" required>{{ old('feedback') }}</textarea>
                                <button type="submit" class="btn btn-sm btn-outline-primary mt-2">{{ __('Log feedback') }}</button>
                            </form>
                        </div>
                        @empty
                        <p class="text-secondary mb-0">{{ __('No meetings scheduled.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">{{ __('Lead') }}</h3></div>
            <div class="card-body">
                <p class="mb-2">
                    <a href="{{ route('leads.show', $lead) }}" class="fw-semibold text-reset">{{ $lead->full_name }}</a>
                    <span class="d-block text-secondary small">{{ $lead->phone }} @if($lead->email) • {{ $lead->email }} @endif</span>
                </p>
                <p class="text-secondary small mb-0">
                    {{ __('Feedback saved here appears in the lead activity log, next to calls, SMS and emails.') }}
                </p>
            </div>
        </div>
    </div>
</div>
@endsection