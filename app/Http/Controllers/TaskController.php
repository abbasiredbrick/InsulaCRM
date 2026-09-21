<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskActivityNotification;
use App\Notifications\TaskAssigned;
use App\Services\Cloud\CloudCalendarService;
use App\Services\ScheduleActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class TaskController extends Controller
{
    public function __construct(protected CloudCalendarService $calendar) {}

    /**
     * Store a new task for a lead and assign it to an agent (default self).
     */
    public function store(TaskRequest $request, Lead $lead)
    {
        $this->authorizeLead($lead);

        $assignee = $this->resolveAssignee($request->input('assigned_to'), $request->input('assign_to_user'));
        $ownerId = auth()->id();

        $task = Task::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $assignee->id,
            'created_by' => $ownerId,
            'title' => $request->title,
            'due_date' => $request->due_date,
            'due_time' => $request->input('due_time') ?: null,
            'reminder_minutes' => $request->input('reminder_minutes') ?? null,
        ]);

        $task->activities()->create([
            'tenant_id' => $task->tenant_id,
            'agent_id' => $ownerId,
            'body' => $assignee->id === $ownerId
                ? __('Task created and assigned to myself.')
                : __('Task created and assigned to :name.', ['name' => $assignee->name]),
        ]);

        AuditLog::log('task.created', $task);
        $this->calendar->sync($task, auth()->user());

        app(ScheduleActivityService::class)->log(
            $lead,
            'task',
            __('Task created'),
            __('Task :title due :due', ['title' => $task->title, 'due' => $task->due_label]),
            $task,
        );

        // The assigned agent is emailed + in-app notified (skip when assigning to self).
        if ($assignee->id !== $ownerId) {
            Notification::send($assignee, new TaskAssigned($task, auth()->user()->tenant, auth()->user()));
        }

        if ($request->expectsJson() || $request->boolean('json')) {
            return response()->json(['success' => true, 'id' => $task->id]);
        }

        return redirect()->route('leads.show', $lead)->with('success', __('Task created successfully.'));
    }

    /**
     * Update an existing task (title, due date/time, assignee, reminder).
     */
    public function update(TaskRequest $request, Task $task)
    {
        $this->authorizeTask($task);

        $newAssignee = $this->resolveAssignee($request->input('assigned_to'), $request->input('assign_to_user'));
        $assigneeChanged = $newAssignee->id !== $task->agent_id;
        $oldStatus = $task->status;

        $task->update([
            'agent_id' => $newAssignee->id,
            'title' => $request->title,
            'due_date' => $request->due_date,
            'due_time' => $request->input('due_time') ?: null,
            'reminder_minutes' => $request->input('reminder_minutes') ?? null,
            'status' => $request->filled('status') ? $request->input('status') : $task->status,
        ]);

        $actorId = auth()->id();

        if ($task->wasChanged('status') && $oldStatus !== $task->status && $task->lead_id) {
            app(ScheduleActivityService::class)->log(
                $task->lead,
                'task',
                __('Task :status', ['status' => Task::statusLabel($task->status)]),
                __('Task status changed from :from to :to', [
                    'from' => Task::statusLabel($oldStatus),
                    'to' => Task::statusLabel($task->status),
                ]),
                $task,
            );
        }

        if ($assigneeChanged) {
            $task->activities()->create([
                'tenant_id' => $task->tenant_id,
                'agent_id' => $actorId,
                'body' => __('Task reassigned to :name.', ['name' => $newAssignee->name]),
            ]);
            if ($newAssignee->id !== $actorId) {
                Notification::send($newAssignee, new TaskAssigned($task, auth()->user()->tenant, auth()->user()));
            }
        }

        AuditLog::log('task.updated', $task);
        $this->calendar->sync($task, auth()->user());

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $task->id]);
        }

        return redirect()->back()->with('success', __('Task updated successfully.'));
    }

    /**
     * Log a progress note on a task and notify the task owner by email.
     */
    public function logActivity(Request $request, Task $task)
    {
        $this->authorizeTask($task);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $activity = $task->activities()->create([
            'tenant_id' => $task->tenant_id,
            'agent_id' => auth()->id(),
            'body' => $validated['body'],
        ]);

        $this->notifyOwner($task, __('New activity: :body', ['body' => $validated['body']]));

        AuditLog::log('task.activity', $task);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'id' => $activity->id,
                'agent' => auth()->user()->name,
                'body' => $validated['body'],
                'created_at' => $activity->created_at->diffForHumans(),
            ]);
        }

        return redirect()->back()->with('success', __('Activity logged.'));
    }

    /**
     * Toggle task completion. The owner and assignee are both allowed; the
     * owner is emailed whenever the status changes.
     */
    public function toggleComplete(Task $task)
    {
        $this->authorizeTask($task);

        $task->update(['is_completed' => ! $task->is_completed]);
        $nowCompleted = $task->is_completed;

        if ($task->lead_id) {
            app(ScheduleActivityService::class)->log(
                $task->lead,
                'task',
                $nowCompleted ? __('Task completed') : __('Task reopened'),
                __('Task :title :state', [
                    'title' => $task->title,
                    'state' => $nowCompleted ? __('completed') : __('reopened'),
                ]),
                $task,
            );
        }

        $this->notifyOwner(
            $task,
            $nowCompleted
                ? __('Task marked as complete by :name.', ['name' => auth()->user()->name])
                : __('Task reopened by :name.', ['name' => auth()->user()->name])
        );

        AuditLog::log('task.toggled', $task);
        $this->calendar->sync($task, auth()->user());

        return response()->json(['success' => true, 'is_completed' => $task->is_completed]);
    }

    /**
     * Delete a task.
     */
    public function destroy(Task $task)
    {
        $this->authorizeTask($task);

        $this->calendar->removeEvent($task);
        $task->activities()->delete();
        $task->delete();

        AuditLog::log('task.deleted', $task);

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', __('Task deleted.'));
    }

    /**
     * Resolve the assignee from the submitted user id (fall back to self).
     */
    protected function resolveAssignee(mixed $userId, mixed $userField = null): User
    {
        $candidate = $userId ?: $userField ?: auth()->id();

        if (! $candidate) {
            return auth()->user();
        }

        $user = User::withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->find((int) $candidate);

        return $user ?: auth()->user();
    }

    /**
     * Email + in-app notify the task owner (unless they caused the change).
     */
    protected function notifyOwner(Task $task, string $summary): void
    {
        $owner = $task->owner;

        if (! $owner || $owner->id === auth()->id()) {
            return;
        }

        Notification::send($owner, new TaskActivityNotification($task, auth()->user()->tenant, $summary, auth()->user()));
    }

    protected function authorizeLead(Lead $lead): void
    {
        $this->authorize('update', $lead);
    }

    protected function authorizeTask(Task $task): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->isAgent() && ($task->agent_id === $user->id || $task->created_by === $user->id)) {
            return;
        }

        abort(403);
    }
}
