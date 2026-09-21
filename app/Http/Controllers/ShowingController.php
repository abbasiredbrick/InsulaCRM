<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowingRequest;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Showing;
use App\Services\LeadViewingService;
use App\Services\ScheduleActivityService;

class ShowingController extends Controller
{
    public function create()
    {
        $this->authorize('create', Showing::class);

        $propertyOptions = Property::orderBy('community')->orderBy('sub_community')->orderBy('unit_no')
            ->get(Property::optionLabelColumns())
            ->map(fn (Property $p) => ['value' => $p->id, 'label' => $p->optionLabel()])
            ->values();
        $leadOptions = Lead::orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'phone', 'email'])
            ->map(fn (Lead $l) => ['value' => $l->id, 'label' => $l->pickerLabel()])
            ->values();
        $agents = \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin', 'agent', 'listing_agent', 'buyers_agent']))
            ->orderBy('name')
            ->get(['id', 'name']);

        $preselectedLeadId = request()->input('lead_id');

        return view('showings.create', compact('propertyOptions', 'leadOptions', 'agents', 'preselectedLeadId'));
    }

    public function store(ShowingRequest $request, \App\Services\Cloud\CloudCalendarService $calendar)
    {
        $this->authorize('create', Showing::class);

        $data = $request->validated();
        $data['tenant_id'] = auth()->user()->tenant_id;
        $data['agent_id'] = $data['agent_id'] ?? auth()->id();
        $data['created_by'] = auth()->id();

        $showing = Showing::create($data);

        // Advance the linked leasing lead's pipeline: arranging a viewing = the
        // client is going to see the unit, so the lead moves to "Viewing Scheduled".
        if ($showing->lead_id) {
            app(LeadViewingService::class)->advanceToStage($showing->lead, 'viewing_scheduled');
        }

        // Log activity on the lead if linked
        if ($showing->lead_id) {
            app(ScheduleActivityService::class)->log(
                $showing->lead,
                'viewing',
                __('Viewing scheduled'),
                __('Showing at :address on :date at :time', [
                    'address' => $showing->property->address ?? '',
                    'date' => $showing->showing_date->format('M j, Y'),
                    'time' => $showing->showing_time,
                ]),
                $showing,
            );
        }

        $calendar->sync($showing, auth()->user());

        return redirect()->route('showings.show', $showing)->with('success', __('Viewing scheduled successfully.'));
    }

    /**
     * Create a showing inline from the lead detail page (avoids the full
     * showing form) - mirrors store() but pins the lead to the route lead.
     */
    public function storeForLead(ShowingRequest $request, Lead $lead, \App\Services\Cloud\CloudCalendarService $calendar)
    {
        $this->authorize('update', $lead);

        $data = $request->validated();
        $data['tenant_id'] = auth()->user()->tenant_id;
        $data['agent_id'] = $data['agent_id'] ?? $lead->agent_id ?? auth()->id();
        $data['lead_id'] = $lead->id;
        $data['created_by'] = auth()->id();

        $showing = Showing::create($data);

        app(LeadViewingService::class)->advanceToStage($lead, 'viewing_scheduled');

        app(ScheduleActivityService::class)->log(
            $lead,
            'viewing',
            __('Viewing scheduled'),
            __('Showing at :address on :date at :time', [
                'address' => $showing->property->address ?? '',
                'date' => $showing->showing_date->format('M j, Y'),
                'time' => $showing->showing_time,
            ]),
            $showing,
        );

        $calendar->sync($showing, auth()->user());

        return back()->with('success', __('Viewing scheduled successfully.'));
    }

    public function show(Showing $showing)
    {
        $this->authorize('view', $showing);
        $showing->load(['property', 'lead', 'agent', 'deal']);

        return view('showings.show', compact('showing'));
    }

    public function edit(Showing $showing)
    {
        $this->authorize('update', $showing);

        $propertyOptions = Property::orderBy('community')->orderBy('sub_community')->orderBy('unit_no')
            ->get(Property::optionLabelColumns())
            ->map(fn (Property $p) => ['value' => $p->id, 'label' => $p->optionLabel()])
            ->values();
        $leadOptions = Lead::orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'phone', 'email'])
            ->map(fn (Lead $l) => ['value' => $l->id, 'label' => $l->pickerLabel()])
            ->values();
        $agents = \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin', 'agent', 'listing_agent', 'buyers_agent']))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('showings.edit', compact('showing', 'propertyOptions', 'leadOptions', 'agents'));
    }

    public function update(ShowingRequest $request, Showing $showing, \App\Services\Cloud\CloudCalendarService $calendar)
    {
        $this->authorize('update', $showing);

        $oldStatus = $showing->status;
        $showing->update($request->validated());

        // The viewing happened: move the leasing lead to "Unit Viewed".
        if ($oldStatus !== $showing->status && $showing->status === 'completed' && $showing->lead_id) {
            app(LeadViewingService::class)->advanceToStage($showing->lead, 'viewing_done');

            app(ScheduleActivityService::class)->log(
                $showing->lead,
                'viewing',
                __('Unit viewed'),
                __('Unit was shown to the client on :date', ['date' => $showing->showing_date->format('M j, Y')]),
                $showing,
            );
        } elseif ($oldStatus !== $showing->status && $showing->lead_id) {
            app(ScheduleActivityService::class)->log(
                $showing->lead,
                'viewing',
                __('Viewing :status', ['status' => \App\Models\Showing::statusLabel($showing->status)]),
                __('Showing status changed from :from to :to', [
                    'from' => \App\Models\Showing::statusLabel($oldStatus),
                    'to' => \App\Models\Showing::statusLabel($showing->status),
                ]),
                $showing,
            );
        }

        $calendar->sync($showing, auth()->user());

        if ($request->ajax()) {
            return response()->json(['success' => true, 'showing' => $showing->fresh()]);
        }

        return redirect()->route('showings.show', $showing)->with('success', __('Viewing updated successfully.'));
    }

    public function destroy(Showing $showing, \App\Services\Cloud\CloudCalendarService $calendar)
    {
        $this->authorize('delete', $showing);

        $calendar->removeEvent($showing);
        $showing->delete();

        return redirect()->route('schedules.index')->with('success', __('Viewing deleted successfully.'));
    }
}
