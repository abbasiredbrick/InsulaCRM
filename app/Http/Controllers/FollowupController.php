<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Services\ScheduleActivityService;
use Illuminate\Http\Request;

/**
 * Feedback endpoints for viewings, tasks and meetings. Each save is logged as
 * a typed, subject-linked lead activity so it shows in the hub and on the lead
 * page timeline, and the assigned agent/manager is notified.
 */
class FollowupController extends Controller
{
    public function viewingFeedback(Request $request, Showing $showing)
    {
        $lead = $showing->lead;
        abort_unless($lead, 404);

        $this->authorize('update', $lead);

        $data = $request->validate(['feedback' => 'required|string|max:4000']);

        $showing->update([
            'feedback' => $data['feedback'],
            'status' => in_array($request->input('status'), array_keys(Showing::STATUSES), true) ? $request->input('status') : $showing->status,
        ]);

        app(ScheduleActivityService::class)->logFeedback($lead, 'viewing', __('Viewing feedback'), $data['feedback'], $showing);
        AuditLog::log('viewing.feedback', $showing);

        return back()->with('success', __('Viewing feedback saved and logged on the lead.'));
    }

    public function taskFeedback(Request $request, Task $task)
    {
        $lead = $task->lead;
        abort_unless($lead, 404);

        $this->authorize('update', $lead);

        $data = $request->validate(['feedback' => 'required|string|max:4000']);

        $task->activities()->create([
            'tenant_id' => auth()->user()->tenant_id,
            'agent_id' => auth()->id(),
            'body' => $data['feedback'],
        ]);

        app(ScheduleActivityService::class)->logFeedback($lead, 'task', __('Task feedback'), $data['feedback'], $task);
        AuditLog::log('task.feedback', $task);

        return back()->with('success', __('Task feedback saved and logged on the lead.'));
    }

    public function meetingFeedback(Request $request, Meeting $meeting)
    {
        $lead = $meeting->lead;
        abort_unless($lead, 404);

        $this->authorize('update', $lead);

        $data = $request->validate(['feedback' => 'required|string|max:4000']);

        $meeting->update([
            'feedback' => $data['feedback'],
            'status' => in_array($request->input('status'), Meeting::STATUSES, true) ? $request->input('status') : $meeting->status,
        ]);

        app(ScheduleActivityService::class)->logFeedback($lead, 'meeting', __('Meeting feedback'), $data['feedback'], $meeting);
        AuditLog::log('meeting.feedback', $meeting);

        return back()->with('success', __('Meeting feedback saved and logged on the lead.'));
    }
}
