<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Services\Cloud\CloudCalendarService;
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
            'title' => $data['title'],
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? 60,
            'reminder_minutes' => $data['reminder_minutes'] ?? null,
            'status' => 'scheduled',
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLog::log('meeting.created', $meeting, ['lead_id' => $lead->id, 'scheduled_at' => $meeting->scheduled_at->toDateTimeString()]);
        $this->calendar->sync($meeting, auth()->user());

        return redirect()->route('leads.show', $lead)->with('success', __('Meeting scheduled successfully.'));
    }

    public function update(Request $request, Meeting $meeting)
    {
        if (auth()->user()->isAgent() && $meeting->agent_id !== auth()->id()) {
            abort(403);
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'scheduled_at' => 'sometimes|date',
            'duration_minutes' => 'nullable|integer|min:15|max:480',
            'reminder_minutes' => 'nullable|integer|min:1|max:10080',
            'status' => 'sometimes|in:scheduled,completed,cancelled',
            'notes' => 'nullable|string|max:5000',
        ]);

        unset($data['notes']); // kept via append_notes below to avoid wiping on toggle
        $meeting->update($data);

        if ($request->filled('notes')) {
            $meeting->update(['notes' => $request->notes]);
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
        if (auth()->user()->isAgent() && $meeting->agent_id !== auth()->id()) {
            abort(403);
        }

        $this->calendar->removeEvent($meeting);
        $leadId = $meeting->lead_id;
        $meeting->delete();

        AuditLog::log('meeting.deleted', $meeting);

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('leads.show', $leadId)->with('success', __('Meeting deleted.'));
    }
}
