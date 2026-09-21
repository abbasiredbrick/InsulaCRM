<?php

namespace App\Http\Controllers;

use App\Events\ActivityLogged;
use App\Facades\Hooks;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\MotivationScoreService;
use App\Services\TeamNotifier;
use Illuminate\Http\Request;

/**
 * Unified follow-ups hub: scheduled viewings, tasks and meetings for a lead in
 * one place, with per-item feedback that is logged into the lead activity so it
 * shows on both the lead page and here.
 */
class FollowupController extends Controller
{
    public function index(Lead $lead)
    {
        $this->authorize('update', $lead);

        $lead->load([
            'showings.property',
            'showings.agent',
            'tasks.activities',
            'tasks.agent',
            'meetings.agent',
        ]);

        return view('followups.index', compact('lead'));
    }

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

        $this->logFeedback($lead, 'viewing', __('Viewing feedback'), $data['feedback'], $showing);
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

        $this->logFeedback($lead, 'task', __('Task feedback'), $data['feedback'], $task);
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

        $this->logFeedback($lead, 'meeting', __('Meeting feedback'), $data['feedback'], $meeting);
        AuditLog::log('meeting.feedback', $meeting);

        return back()->with('success', __('Meeting feedback saved and logged on the lead.'));
    }

    /**
     * Persist feedback as a lead activity and fan out the standard hooks.
     */
    protected function logFeedback(Lead $lead, string $type, string $subject, string $body, $entity): void
    {
        $activity = Activity::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => auth()->id(),
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'logged_at' => now(),
        ]);

        app(MotivationScoreService::class)->recalculate($lead);
        event(new ActivityLogged($activity));
        Hooks::doAction('activity.logged', $activity);

        app(TeamNotifier::class)->notifyScheduleFeedback($activity, $entity, "{$subject}: {$body}");
    }
}