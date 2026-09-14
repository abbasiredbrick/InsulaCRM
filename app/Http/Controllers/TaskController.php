<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Task;
use App\Services\Cloud\CloudCalendarService;

class TaskController extends Controller
{
    public function __construct(protected CloudCalendarService $calendar) {}

    /**
     * Store a new task for a lead.
     */
    public function store(TaskRequest $request, Lead $lead)
    {
        $this->authorizeLead($lead);

        if (! $this->calendar->schedulerAllowed(auth()->user())) {
            return redirect()->route('leads.show', $lead)
                ->with('error', __('Connect a calendar (Google, Microsoft or iCal feed) in My Cloud before scheduling follow-ups or reminders.'));
        }

        $task = Task::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => auth()->id(),
            'title' => $request->title,
            'due_date' => $request->due_date,
        ]);

        AuditLog::log('task.created', $task);
        $this->calendar->sync($task, auth()->user());

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $task->id]);
        }

        return redirect()->route('leads.show', $lead)->with('success', 'Task created successfully.');
    }

    /**
     * Toggle task completion via AJAX.
     */
    public function toggleComplete(Task $task)
    {
        if (auth()->user()->isAgent() && $task->agent_id !== auth()->id()) {
            abort(403);
        }

        $task->update(['is_completed' => ! $task->is_completed]);

        AuditLog::log('task.toggled', $task);
        $this->calendar->sync($task, auth()->user());

        return response()->json(['success' => true, 'is_completed' => $task->is_completed]);
    }

    /**
     * Delete a task via AJAX.
     */
    public function destroy(Task $task)
    {
        if (auth()->user()->isAgent() && $task->agent_id !== auth()->id()) {
            abort(403);
        }

        $leadId = $task->lead_id;
        $this->calendar->removeEvent($task);
        $task->delete();

        AuditLog::log('task.deleted', $task);

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('leads.show', $leadId)->with('success', 'Task deleted.');
    }

    private function authorizeLead(Lead $lead): void
    {
        $this->authorize('update', $lead);
    }
}
