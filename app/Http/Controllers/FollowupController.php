<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Services\LeadViewingService;
use App\Services\PipelineSyncService;
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

        $data = $request->validate([
            'feedback' => 'required|string|max:4000',
            'offer_requested' => 'nullable|boolean',
        ]);

        $offerRequested = $request->boolean('offer_requested');

        $showing->update([
            'feedback' => $data['feedback'],
            'status' => in_array($request->input('status'), array_keys(Showing::STATUSES), true) ? $request->input('status') : $showing->status,
            // The checkbox sets the outcome. A later feedback save must not
            // silently erase it, so an unchecked save keeps whatever is there.
            'outcome' => $offerRequested ? 'offer_requested' : $showing->outcome,
        ]);

        app(ScheduleActivityService::class)->logFeedback($lead, 'viewing', __('Viewing feedback'), $data['feedback'], $showing);
        AuditLog::log('viewing.feedback', $showing);

        $deal = null;

        if ($offerRequested) {
            $deal = $this->promoteToOfferRequested($lead, $showing);
        }

        $message = __('Viewing feedback saved and logged on the lead.');

        if ($deal) {
            $message = __('Viewing feedback saved. Client requested an offer — deal :stage created.', [
                'stage' => Deal::stageLabels()[$deal->stage] ?? $deal->stage,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Promote a leasing lead to "offer requested" and open its deal.
     *
     * The promotion is forward-only (LeadViewingService::advanceToStage never
     * regresses), so logging a second viewing on another unit cannot drag a
     * lead that is already at offer_signed back to offer_requested. The viewing
     * is handed to the sync so the deal records the unit the client actually
     * asked for rather than the unit on the lead.
     *
     * @return \App\Models\Deal|null the newly created deal, or null when the
     *                               lead already had one or is not leasing
     */
    protected function promoteToOfferRequested(Lead $lead, Showing $showing): ?Deal
    {
        $tenant = auth()->user()?->tenant;

        app(LeadViewingService::class)->advanceToStage(
            $lead,
            PipelineSyncService::RENT_TRIGGER_STAGE,
            auth()->id()
        );

        $lead->refresh();

        return app(PipelineSyncService::class)->syncForLead($lead, $tenant, $showing);
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
