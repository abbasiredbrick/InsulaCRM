<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\Cloud\CloudCalendarService;
use App\Services\LeadViewingService;
use App\Services\ScheduleActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Scheduling Hub: a single place to view, monitor and manage viewings,
 * meetings and tasks across the user's leads, with feedback that lands in the
 * lead activity log and calendar events synced to every involved user.
 */
class ScheduleHubController extends Controller
{
    public function __construct(protected CloudCalendarService $calendar) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        $filters = [
            'type' => $request->query('type', 'all'),
            'status' => $request->query('status'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'agent' => $request->query('agent'),
            'lead' => $request->query('lead') ? (int) $request->query('lead') : null,
            'search' => trim((string) $request->query('search')),
        ];

        $scope = $this->scopeBuilder($user);

        $viewings = $this->query(Showing::with(['lead', 'property', 'agent', 'creator']), $scope, $filters, 'showing_date', 'showing_time')
            ->with(['property.availabilitySource'])
            ->orderBy('showing_date', 'desc')
            ->orderBy('showing_time', 'desc')
            ->limit(200)
            ->get();

        $tasks = $this->query(Task::with(['lead', 'agent', 'owner', 'activities']), $scope, $filters, 'due_date', 'due_time')
            ->orderBy('due_date', 'desc')
            ->orderBy('due_time', 'desc')
            ->limit(200)
            ->get();

        $meetings = $this->query(Meeting::with(['lead', 'agent', 'creator']), $scope, $filters, 'scheduled_at')
            ->orderBy('scheduled_at', 'desc')
            ->limit(200)
            ->get();

        $viewings->each(fn ($v) => $v->load('lead.coAgents'));
        $tasks->each(fn ($t) => $t->load('lead.coAgents'));
        $meetings->each(fn ($m) => $m->load('lead.coAgents'));

        $items = collect($this->normalizeViewings($viewings))
            ->merge($this->normalizeTasks($tasks))
            ->merge($this->normalizeMeetings($meetings))
            ->sortByDesc('at')
            ->values();

        $agents = $user->isAdmin() || $user->isManager()
            ? User::where('tenant_id', $user->tenant_id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin', 'agent', 'listing_agent', 'buyers_agent']))
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        return view('schedules.index', [
            'items' => $items,
            'filters' => $filters,
            'agents' => $agents,
            'leadOptions' => $filters['lead'] ? Lead::whereKey($filters['lead'])->get(['id', 'first_name', 'last_name', 'phone']) : collect(),
        ]);
    }

    /**
     * Search leads (for the hub's lead picker), scoped to what the user can see.
     */
    public function searchLeads(Request $request)
    {
        $term = trim((string) $request->query('q'));

        $query = $this->leadScope(auth()->user())
            ->select('id', 'first_name', 'last_name', 'phone', 'email')
            ->orderBy('updated_at', 'desc')
            ->limit(25);

        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%");
            });
        }

        return response()->json([
            'results' => $query->get()->map(fn (Lead $l) => [
                'id' => $l->id,
                'label' => trim($l->first_name.' '.$l->last_name).' · '.$l->phone,
            ]),
        ]);
    }

    public function storeMeeting(Request $request)
    {
        $data = $request->validate([
            'lead_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'reminder_minutes' => 'nullable|integer|min:1|max:10080',
            'notes' => 'nullable|string|max:5000',
            'assigned_to' => 'nullable|integer',
        ]);

        $lead = $this->leadScope(auth()->user())->findOrFail($data['lead_id']);
        $this->authorize('update', $lead);

        $assignee = $this->resolveAssignee($data['assigned_to'] ?? $lead->agent_id);

        $meeting = Meeting::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $assignee->id,
            'created_by' => auth()->id(),
            'title' => $data['title'],
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'reminder_minutes' => $data['reminder_minutes'] ?? null,
            'status' => 'scheduled',
            'notes' => $data['notes'] ?? null,
        ]);

        $this->logScheduleActivity($lead, 'meeting', __('Meeting scheduled'), $meeting, __('Meeting :title scheduled for :at', [
            'title' => $meeting->title,
            'at' => $meeting->scheduled_at->format('M d, Y g:i A'),
        ]));

        $this->calendar->sync($meeting, auth()->user());

        return $this->redirectBack(__('Meeting scheduled successfully.'));
    }

    public function storeTask(Request $request)
    {
        $data = $request->validate([
            'lead_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'due_date' => 'required|date',
            'due_time' => 'nullable',
            'reminder_minutes' => 'nullable|integer|min:1|max:10080',
            'assigned_to' => 'nullable|integer',
        ]);

        $lead = $this->leadScope(auth()->user())->findOrFail($data['lead_id']);
        $this->authorize('update', $lead);

        $assignee = $this->resolveAssignee($data['assigned_to'] ?? null);

        $task = Task::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $assignee->id,
            'created_by' => auth()->id(),
            'title' => $data['title'],
            'due_date' => $data['due_date'],
            'due_time' => $data['due_time'] ?: null,
            'status' => 'scheduled',
            'reminder_minutes' => $data['reminder_minutes'] ?? null,
        ]);

        $task->activities()->create([
            'tenant_id' => $task->tenant_id,
            'agent_id' => auth()->id(),
            'body' => $assignee->id === auth()->id()
                ? __('Task created and assigned to myself.')
                : __('Task created and assigned to :name.', ['name' => $assignee->name]),
        ]);

        $this->logScheduleActivity($lead, 'task', __('Task created'), $task, __('Task :title due :due', [
            'title' => $task->title,
            'due' => $task->due_label,
        ]));

        $this->calendar->sync($task, auth()->user());

        if ($assignee->id !== auth()->id()) {
            Notification::send($assignee, new TaskAssigned($task, auth()->user()->tenant, auth()->user()));
        }

        return $this->redirectBack(__('Task created successfully.'));
    }

    /**
     * Quick status/outcome update for a viewing straight from the hub.
     */
    public function showingStatus(Request $request, Showing $showing)
    {
        $this->authorize('update', $showing);

        $data = $request->validate([
            'status' => 'sometimes|in:scheduled,completed,cancelled,no_show',
            'outcome' => 'nullable|in:'.implode(',', array_keys(Showing::OUTCOMES)),
            'feedback' => 'nullable|string|max:4000',
        ]);

        $oldStatus = $showing->status;
        $showing->update($data);

        if ($showing->wasChanged('status') && $showing->status === 'completed' && $showing->lead_id) {
            app(LeadViewingService::class)->advanceToStage($showing->lead, 'viewing_done');
        }

        if ($showing->lead_id && ($showing->wasChanged('status') || filled($data['outcome'] ?? null))) {
            $subject = $showing->status === 'completed' ? __('Unit viewed') : __('Viewing :status', ['status' => Showing::statusLabel($showing->status)]);

            app(ScheduleActivityService::class)->log(
                $showing->lead,
                'viewing',
                $subject,
                __('Showing status changed from :from to :to', [
                    'from' => Showing::statusLabel($oldStatus),
                    'to' => Showing::statusLabel($showing->status),
                ]),
                $showing,
            );
        }

        $this->calendar->sync($showing, auth()->user());

        return back()->with('success', __('Viewing updated successfully.'));
    }

    /**
     * JSON payload for the inline edit modal (meetings and tasks).
     */
    public function edit(Request $request, string $type, int $id)
    {
        $entity = match ($type) {
            'meeting' => Meeting::with('lead')->findOrFail($id),
            'task' => Task::with('lead')->findOrFail($id),
            default => abort(404),
        };

        $this->authorize('update', $entity->lead ?? abort(404));

        return response()->json([
            'entity' => [
                'id' => $entity->id,
                'title' => $entity->title,
                'scheduled_at' => $entity->scheduled_at?->format('Y-m-d\TH:i'),
                'due_date' => $entity->due_date?->format('Y-m-d'),
                'due_time' => $entity->due_time,
                'duration_minutes' => $entity->duration_minutes,
                'reminder_minutes' => $entity->reminder_minutes,
                'notes' => $entity->notes,
                'agent_id' => $entity->agent_id,
            ],
        ]);
    }

    /**
     * Quick status update for a task straight from the hub.
     */
    public function taskStatus(Request $request, Task $task)
    {
        if (! auth()->user()->isAdmin()) {
            $allowed = $task->agent_id === auth()->id() || $task->created_by === auth()->id()
                || (auth()->user()->isManager() && in_array($task->agent_id, array_merge([auth()->id()], auth()->user()->teamUserIds()), true));

            abort_unless($allowed, 403);
        }

        $data = $request->validate(['status' => 'required|in:scheduled,completed,cancelled']);

        $task->update(['status' => $data['status']]);

        if ($task->lead_id) {
            app(ScheduleActivityService::class)->log(
                $task->lead,
                'task',
                __('Task :status', ['status' => Task::statusLabel($task->status)]),
                __('Task marked as :status.', ['status' => Task::statusLabel($task->status)]),
                $task,
            );
        }

        $this->calendar->sync($task, auth()->user());

        return back()->with('success', __('Task updated successfully.'));
    }

    protected function logScheduleActivity(Lead $lead, string $type, string $subject, $entity, string $body): void
    {
        app(ScheduleActivityService::class)->log($lead, $type, $subject, $body, $entity);
    }

    protected function scopeBuilder(User $user): \Closure
    {
        return function ($query) use ($user) {
            if ($user->isAdmin() || $user->isOwner()) {
                return;
            }

            $leadIds = $this->leadScope($user)->pluck('id');

            $agentIds = $user->isManager()
                ? array_merge([$user->id], $user->teamUserIds())
                : [$user->id];

            $query->where(function ($q) use ($leadIds, $agentIds) {
                $q->whereIn('lead_id', $leadIds)
                    ->orWhereIn('agent_id', $agentIds)
                    ->orWhereIn('created_by', $agentIds);
            });
        };
    }

    /**
     * Leads a user may see, mirroring LeadController's role scoping.
     */
    protected function leadScope(User $user)
    {
        $query = Lead::query();

        if ($user->isAdmin() || $user->isOwner()) {
            return $query;
        }

        $ids = $user->isManager() ? array_merge([$user->id], $user->teamUserIds()) : [$user->id];

        return $query->where(function ($q) use ($ids) {
            $q->whereIn('agent_id', $ids)
                ->orWhereNull('agent_id')
                ->orWhereHas('leadAgents', fn ($lq) => $lq->whereIn('agent_id', $ids)->where('status', \App\Models\LeadAgent::STATUS_ACTIVE));
        });
    }

    protected function query($query, \Closure $scope, array $filters, string $dateColumn, ?string $timeColumn = null)
    {
        $scope($query);

        if (filled($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['from'])) {
            $query->where($dateColumn, '>=', $filters['from']);
        }

        if (filled($filters['to'])) {
            $query->where($dateColumn, '<=', $filters['to']);
        }

        if (filled($filters['agent'])) {
            $query->where('agent_id', (int) $filters['agent']);
        }

        if ($filters['lead']) {
            $query->where('lead_id', $filters['lead']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('lead', function ($lq) use ($search) {
                    $lq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%");
                });

                if ($q->getModel() instanceof Showing) {
                    $q->orWhereHas('property', function ($pq) use ($search) {
                        $pq->where('address', 'like', "%{$search}%")
                            ->orWhere('unit_no', 'like', "%{$search}%")
                            ->orWhere('community', 'like', "%{$search}%")
                            ->orWhere('sub_community', 'like', "%{$search}%")
                            ->orWhereHas('availabilitySource', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                    });
                }
            });
        }

        return $query;
    }

    protected function normalizeViewings($viewings): array
    {
        return $viewings->map(fn (Showing $showing) => [
            'type' => 'viewing',
            'id' => $showing->id,
            'entity' => $showing,
            'lead' => $showing->lead,
            'agent' => $showing->agent?->name,
            'creator' => $showing->creator?->name,
            'at' => $this->at($showing->showing_date, $showing->showing_time),
            'status' => $showing->status,
            'status_label' => Showing::statusLabel($showing->status),
            'status_color' => $this->statusColor($showing->status),
            'outcome' => $showing->outcome ? Showing::outcomeLabel($showing->outcome) : null,
            'title' => $showing->property?->optionLabel() ?? __('Unit #:id', ['id' => $showing->property_id]),
            'subtitle' => $showing->property?->full_address ?? ($showing->property?->address ?? ''),
            'feedback' => $showing->feedback,
            'view_url' => route('showings.show', $showing),
            'edit_url' => route('showings.edit', $showing),
            'feedback_route' => 'followups.viewing.feedback',
        ])->all();
    }

    protected function normalizeTasks($tasks): array
    {
        return $tasks->map(fn (Task $task) => [
            'type' => 'task',
            'id' => $task->id,
            'entity' => $task,
            'lead' => $task->lead,
            'agent' => $task->agent?->name,
            'creator' => $task->owner?->name,
            'at' => $task->dueAt(),
            'status' => $task->status,
            'status_label' => Task::statusLabel($task->status),
            'status_color' => $this->statusColor($task->status),
            'outcome' => null,
            'title' => $task->title,
            'subtitle' => $task->due_label,
            'feedback' => $task->activities->pluck('body')->last(),
            'view_url' => optional($task->lead)->id ? route('leads.show', $task->lead).'#task-'.$task->id : null,
            'edit_url' => optional($task->lead)->id ? route('schedules.edit', ['type' => 'task', 'id' => $task->id]) : null,
            'feedback_route' => 'followups.task.feedback',
        ])->all();
    }

    protected function normalizeMeetings($meetings): array
    {
        return $meetings->map(fn (Meeting $meeting) => [
            'type' => 'meeting',
            'id' => $meeting->id,
            'entity' => $meeting,
            'lead' => $meeting->lead,
            'agent' => $meeting->agent?->name,
            'creator' => $meeting->creator?->name,
            'at' => $meeting->scheduled_at,
            'status' => $meeting->status,
            'status_label' => Meeting::statusLabel($meeting->status),
            'status_color' => $this->statusColor($meeting->status),
            'outcome' => null,
            'title' => $meeting->title,
            'subtitle' => $meeting->scheduled_at->format('M d, Y g:i A'),
            'feedback' => $meeting->feedback,
            'view_url' => optional($meeting->lead)->id ? route('leads.show', $meeting->lead).'#meeting-'.$meeting->id : null,
            'edit_url' => optional($meeting->lead)->id ? route('schedules.edit', ['type' => 'meeting', 'id' => $meeting->id]) : null,
            'feedback_route' => 'followups.meeting.feedback',
        ])->all();
    }

    protected function at($date, $time = null): ?\Carbon\Carbon
    {
        if (! $date) {
            return null;
        }

        if ($date instanceof \Carbon\Carbon) {
            return $date;
        }

        $value = $date?->format('Y-m-d') ?? $date;

        return $time ? \Carbon\Carbon::parse($value.' '.$time) : \Carbon\Carbon::parse($value);
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'green',
            'cancelled' => 'secondary',
            'no_show' => 'red',
            default => 'blue',
        };
    }

    protected function resolveAssignee(?int $userId): User
    {
        if ($userId) {
            $user = User::withoutGlobalScopes()->where('tenant_id', auth()->user()->tenant_id)->find($userId);
            if ($user) {
                return $user;
            }
        }

        return auth()->user();
    }

    protected function redirectBack(string $message)
    {
        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return redirect()->route('schedules.index')->with('success', __($message));
    }
}
