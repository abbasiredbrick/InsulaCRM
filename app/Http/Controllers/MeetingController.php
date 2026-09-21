<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\Cloud\CloudCalendarService;
use App\Services\ScheduleActivityService;
use Illuminate\Http\Request;

class MeetingController extends Controller
{
    public function __construct(protected CloudCalendarService $calendar) {}

    public function store(Request $request, Lead $lead, CloudCalendarService $calendar)
    {
        $this->authorize('update', $lead);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'reminder_minutes' => 'nullable|integer|min:1|max:10080',
            'notes' => 'nullable|string|max:5000',
        ]);

        $meeting = Meeting::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'deal_id' => $request->input('deal_id'),
            'property_id' => null,
            'agent_id' => auth()->id(),
            'created_by' => auth()->id(),
            'title' => $data['title'],
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'reminder_minutes' => $data['reminder_minutes'] ?? null,
            'status' => 'scheduled',
            'notes' => $data['notes'] ?? null,
        ]);

        app(ScheduleActivityService::class)->log(
            $lead,
            'meeting',
            __('Meeting scheduled'),
            __('Meeting :title scheduled for :at', [
                'title' => $meeting->title,
                'at' => $meeting->scheduled_at->format('M d, Y g:i A'),
            ]),
            $meeting,
        );

        AuditLog::log('meeting.created', $meeting, ['lead_id' => $lead->id, 'scheduled_at' => $meeting->scheduled_at->toDateTimeString()]);
        $this->calendar->sync($meeting, auth()->user());

        return redirect()->route('leads.show', $lead)->with('success', __('Meeting scheduled successfully.'));
    }

    public function update(Request $request, Meeting $meeting)
    {
        $this->authorizeMeeting($meeting);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'scheduled_at' => 'sometimes|date',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'reminder_minutes' => 'nullable|integer|min:1|max:10080',
            'status' => 'sometimes|in:scheduled,completed,cancelled',
            'notes' => 'nullable|string|max:5000',
        ]);

        $oldStatus = $meeting->status;
        unset($data['notes']); // kept via append_notes below to avoid wiping on toggle
        $meeting->update($data);

        if ($request->filled('notes')) {
            $meeting->update(['notes' => $request->notes]);
        }

        if ($meeting->wasChanged('status') && $meeting->lead_id) {
            app(ScheduleActivityService::class)->log(
                $meeting->lead,
                'meeting',
                __('Meeting :status', ['status' => Meeting::statusLabel($meeting->status)]),
                __('Meeting status changed from :from to :to', [
                    'from' => Meeting::statusLabel($oldStatus),
                    'to' => Meeting::statusLabel($meeting->status),
                ]),
                $meeting,
            );
        }

        AuditLog::log('meeting.updated', $meeting, $data);
        $this->calendar->sync($meeting, auth()->user());

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', __('Meeting updated successfully.'));
    }

    public function destroy(Meeting $meeting)
    {
        $this->authorizeMeeting($meeting);

        $this->calendar->removeEvent($meeting);
        $leadId = $meeting->lead_id;
        $meeting->delete();

        AuditLog::log('meeting.deleted', $meeting);

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('leads.show', $leadId)->with('success', __('Meeting deleted.'));
    }

    protected function authorizeMeeting(Meeting $meeting): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isOwner()) {
            return;
        }

        if ($meeting->agent_id === $user->id || $meeting->created_by === $user->id) {
            return;
        }

        if ($meeting->lead_id && $meeting->lead?->agent_id === $user->id) {
            return;
        }

        abort(403);
    }
}
