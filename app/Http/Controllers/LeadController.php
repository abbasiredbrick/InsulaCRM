<?php

namespace App\Http\Controllers;

use App\Events\LeadStatusChanged;
use App\Facades\Hooks;
use App\Http\Requests\LeadRequest;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadClaim;
use App\Models\LeadPhoto;
use App\Models\Property;
use App\Models\User;
use App\Notifications\LeadAssigned;
use App\Services\AssignmentHistoryService;
use App\Services\CustomFieldService;
use App\Services\MotivationScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lead::class);

        $query = Lead::with('agent')->withCount('lists');

        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('source')) {
            $query->where('lead_source', $request->source);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('temperature')) {
            $query->where('temperature', $request->temperature);
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        // Stacked leads filter
        if ($request->filled('stacked') && $request->stacked) {
            $query->has('lists', '>=', 2)->orderByDesc('motivation_score');
        }

        // DNC filter
        if ($request->filled('dnc')) {
            $query->where('do_not_contact', true);
        }

        if ($request->filled('contact_type')) {
            $query->where('contact_type', $request->contact_type);
        }

        // Sorting
        if ($request->filled('sort')) {
            $allowedSorts = ['id', 'first_name', 'lead_source', 'status', 'temperature', 'motivation_score', 'created_at'];
            $col = $request->input('sort');
            $dir = strtolower($request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
            if (in_array($col, $allowedSorts)) {
                $query->reorder($col, $dir);
            }
        }

        $leads = $query->latest()->paginate(25);

        $agents = ! auth()->user()->isAgent() ? $this->getAgents() : collect();

        return view('leads.index', compact('leads', 'agents'));
    }

    public function bulkAction(Request $request)
    {
        $this->authorize('bulkUpdate', Lead::class);

        $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'action' => 'required|in:assign,status,delete',
            'agent_id' => 'required_if:action,assign|nullable|integer|exists:users,id',
            'status' => 'required_if:action,status|nullable|string',
        ]);

        $query = Lead::whereIn('id', $request->ids);

        // Agent scoping - agents can only bulk-act on their own leads
        if (auth()->user()->isAgent() && ! auth()->user()->isManager()) {
            $query->where('agent_id', auth()->id());
        }

        $leads = $query->get();
        $count = $leads->count();

        switch ($request->action) {
            case 'assign':
                // Verify target agent belongs to same tenant
                $targetAgent = User::where('id', $request->agent_id)
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->firstOrFail();
                foreach ($leads as $lead) {
                    $lead->update(['agent_id' => $targetAgent->id]);
                }
                $message = "{$count} lead(s) assigned to {$targetAgent->name}.";
                break;

            case 'status':
                $validStatuses = CustomFieldService::getValidSlugs('lead_status');
                if (! in_array($request->status, $validStatuses)) {
                    return redirect()->back()->with('error', 'Invalid status.');
                }
                foreach ($leads as $lead) {
                    $oldStatus = $lead->status;
                    $lead->update(['status' => $request->status]);
                    if ($oldStatus !== $request->status) {
                        event(new LeadStatusChanged($lead, $oldStatus));
                        Hooks::doAction('lead.status_changed', $lead, $oldStatus);
                    }
                }
                $message = "{$count} lead(s) status updated.";
                break;

            case 'delete':
                foreach ($leads as $lead) {
                    $lead->delete();
                    AuditLog::log('lead.deleted', $lead);
                }
                $message = "{$count} lead(s) deleted.";
                break;
        }

        return redirect()->route('leads.index')->with('success', $message);
    }

    public function create()
    {
        $this->authorize('create', Lead::class);

        $agents = $this->getAgents();
        $inventoryUnits = $this->visibleInventory();
        $selectedUnitIds = [];

        return view('leads.create', compact('agents', 'inventoryUnits', 'selectedUnitIds'));
    }

    public function store(LeadRequest $request)
    {
        $this->authorize('create', Lead::class);

        $data = $request->validated();
        $data['tenant_id'] = auth()->user()->tenant_id;

        // Handle custom fields — store as JSON, remove empty values
        if (isset($data['custom_fields'])) {
            $data['custom_fields'] = array_filter($data['custom_fields'], fn ($v) => $v !== null && $v !== '');
        }

        if (auth()->user()->isAgent()) {
            $data['agent_id'] = auth()->id();
        }

        $lead = Lead::create($data);
        app(MotivationScoreService::class)->recalculate($lead);

        $this->syncLinkedUnits($request, $lead);

        // AI auto-qualify temperature
        if (auth()->user()->tenant->ai_enabled) {
            \App\Jobs\AutoQualifyLead::dispatch($lead, auth()->user()->tenant);
        }

        AuditLog::log('lead.created', $lead);
        Hooks::doAction('lead.created', $lead);

        \App\Services\WebhookService::dispatch('lead.created', [
            'lead_id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'source' => $lead->lead_source,
            'status' => $lead->status,
        ], auth()->user()->tenant_id);

        // Notify assigned agent
        if ($lead->agent_id) {
            $tenant = auth()->user()->tenant;
            if ($tenant->wantsNotification('lead_assigned')) {
                $lead->agent->notify(new LeadAssigned($lead, $tenant));
            }
        }

        return redirect()->route('leads.show', $lead)->with('success', __('Lead created successfully.'));
    }

    public function show(Lead $lead)
    {
        $this->authorize('view', $lead);
        $lead->load(['agent', 'property', 'properties', 'activities', 'tasks', 'deals', 'lists', 'photos.uploader', 'sequenceEnrollments.sequence.steps', 'showings.property', 'meetings']);
        $sequences = \App\Models\Sequence::where('is_active', true)->get();
        $assignmentHistory = app(AssignmentHistoryService::class)->getHistory($lead);
        $reassignAgents = $this->getAgents($lead);
        $canReassign = auth()->user()->can('reassign', $lead);

        // Upcoming follow-ups: pending tasks, scheduled viewings and meetings.
        $upcoming = collect();

        $lead->tasks()->where('is_completed', false)->get()->each(function ($task) use ($upcoming) {
            $upcoming->push([
                'type' => 'task',
                'at' => $task->due_date ? \Illuminate\Support\Carbon::parse($task->due_date) : null,
                'title' => $task->title,
                'url' => null,
                'model' => $task,
            ]);
        });

        $lead->showings()->with('property')->get()->each(function ($showing) use ($upcoming) {
            $upcoming->push([
                'type' => 'showing',
                'at' => $showing->showing_date ? \Illuminate\Support\Carbon::parse($showing->showing_date->format('Y-m-d').' '.$showing->showing_time) : null,
                'title' => __('Showing').($showing->property?->address ? ': '.$showing->property->address : ''),
                'url' => route('showings.show', $showing),
                'model' => $showing,
            ]);
        });

        $lead->meetings()->where('status', 'scheduled')->get()->each(function ($meeting) use ($upcoming) {
            $upcoming->push([
                'type' => 'meeting',
                'at' => $meeting->scheduled_at,
                'title' => $meeting->title,
                'url' => null,
                'model' => $meeting,
            ]);
        });

        $upcoming = $upcoming
            ->filter(fn ($item) => $item['at'] && $item['at']->isFuture() || ($item['at'] && $item['at']->isToday()))
            ->sortBy('at')
            ->take(10)
            ->values();

        return view('leads.show', compact('lead', 'sequences', 'assignmentHistory', 'reassignAgents', 'canReassign', 'upcoming'));
    }

    /**
     * Manager/admin only: move a lead to another agent, record the reason and
     * notify the old agent, new agent and the managers of the new agent.
     */
    public function reassign(Request $request, Lead $lead)
    {
        $this->authorize('reassign', $lead);

        $data = $request->validate([
            'agent_id' => 'required|integer|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $target = User::where('tenant_id', auth()->user()->tenant_id)->findOrFail($data['agent_id']);
        $oldAgent = $lead->agent;
        $reason = trim((string) ($data['reason'] ?? ''));

        $lead->update(['agent_id' => $target->id]);

        if (! $lead->wasChanged('agent_id')) {
            return redirect()->route('leads.show', $lead)->with('info', __('Lead is already assigned to :name.', ['name' => $target->name]));
        }

        if ($reason) {
            $lead->activities()->create([
                'tenant_id' => $lead->tenant_id,
                'agent_id' => auth()->id(),
                'type' => 'note',
                'subject' => __('Reassigned to :name', ['name' => $target->name]),
                'body' => $reason,
                'logged_at' => now(),
            ]);
        }

        AuditLog::log('lead.reassigned', $lead, [
            'from' => $oldAgent?->id,
            'to' => $target->id,
            'reason' => $reason ?: null,
        ]);

        app(\App\Services\TeamNotifier::class)->notifyLeadReassigned($lead, $oldAgent, $target, $reason ?: null);

        return redirect()->route('leads.show', $lead)->with('success', __('Lead reassigned to :name.', ['name' => $target->name]));
    }

    public function edit(Lead $lead)
    {
        $this->authorize('update', $lead);
        $agents = $this->getAgents($lead);
        $inventoryUnits = $this->visibleInventory();
        $selectedUnitIds = $lead->properties->pluck('id')->all();

        return view('leads.edit', compact('lead', 'agents', 'inventoryUnits', 'selectedUnitIds'));
    }

    public function update(LeadRequest $request, Lead $lead)
    {
        $this->authorize('update', $lead);
        $oldStatus = $lead->status;

        $data = $request->validated();
        if (isset($data['custom_fields'])) {
            $data['custom_fields'] = array_filter($data['custom_fields'], fn ($v) => $v !== null && $v !== '');
        }

        $lead->update($data);

        // Reassigning an agent via the edit form also informs everyone involved.
        $oldAgentId = $lead->getOriginal('agent_id');
        if ($lead->wasChanged('agent_id') && $oldAgentId !== null) {
            app(\App\Services\TeamNotifier::class)->notifyLeadReassigned(
                $lead,
                User::find($oldAgentId),
                $lead->agent,
                null
            );
        }

        // A stage from the other pipeline no longer makes sense once the deal
        // type changes, so reset it and let the agent pick a fresh stage.
        if ($lead->wasChanged('deal_type') && $lead->stage && ! array_key_exists($lead->stage, $lead->stageOptions())) {
            $lead->update(['stage' => null, 'stage_changed_at' => null]);
        }

        // Moving the lead to a viewing stage via the edit form must surface on
        // the team calendar so the manager sees the arranged viewing.
        if (\App\Services\LeadViewingService::isViewingStage($lead->stage)
            && $lead->wasChanged('stage')
            && $lead->dealType() === 'rent') {
            app(\App\Services\LeadViewingService::class)->logViewingActivity($lead, $lead->stage);
        }

        app(MotivationScoreService::class)->recalculate($lead);

        $this->syncLinkedUnits($request, $lead);

        if ($oldStatus !== $lead->status) {
            event(new LeadStatusChanged($lead, $oldStatus));
            Hooks::doAction('lead.status_changed', $lead, $oldStatus);

            // Marking a lead lost/dead alerts management for cross-checking.
            if (\App\Services\LostLeadNotifier::isLostStatus($lead->status)) {
                \App\Services\LostLeadNotifier::notify($lead, $lead->status, $oldStatus);
            }
        }

        AuditLog::log('lead.updated', $lead);
        Hooks::doAction('lead.updated', $lead);

        \App\Services\WebhookService::dispatch('lead.updated', [
            'lead_id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'status' => $lead->status,
            'temperature' => $lead->temperature,
        ], auth()->user()->tenant_id);

        return redirect()->route('leads.show', $lead)->with('success', __('Lead updated successfully.'));
    }

    public function destroy(Lead $lead)
    {
        $this->authorize('delete', $lead);
        $lead->delete();
        AuditLog::log('lead.deleted', $lead);

        return redirect()->route('leads.index')->with('success', __('Lead deleted successfully.'));
    }

    /**
     * Link an inventory unit to a lead (leads are created/managed from the lead side).
     */
    public function linkProperty(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        $propertyId = $request->validate(['property_id' => 'required|integer'])['property_id'];

        $property = Property::where('tenant_id', $lead->tenant_id)->findOrFail($propertyId);

        $lead->properties()->syncWithoutDetaching([$property->id]);

        AuditLog::log('lead.property_linked', $lead, ['property_id' => $property->id]);

        return back()->with('success', __('Unit linked to this lead.'));
    }

    /**
     * Unlink an inventory unit from a lead.
     */
    public function unlinkProperty(Request $request, Lead $lead, Property $property)
    {
        $this->authorize('update', $lead);

        $lead->properties()->detach($property->id);

        AuditLog::log('lead.property_unlinked', $lead, ['property_id' => $property->id]);

        return back()->with('success', __('Unit unlinked from this lead.'));
    }

    /**
     * Units the current user may link to a lead (same visibility as the inventory screen).
     */
    protected function visibleInventory(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Property::where('tenant_id', auth()->user()->tenant_id)
            ->whereIn('availability', ['draft', 'ready_to_list', 'listed', 'reserved']);

        if (auth()->user()->isAgent()) {
            $query->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        return $query->latest('updated_at')->take(50)->get();
    }

    /**
     * Sync the inventory units selected on the lead create/edit form.
     */
    protected function syncLinkedUnits(Request $request, Lead $lead): void
    {
        if (! $request->exists('linked_units')) {
            return;
        }

        $propertyIds = Property::where('tenant_id', auth()->user()->tenant_id)
            ->whereIn('id', (array) $request->input('linked_units', []))
            ->pluck('id')
            ->all();

        $lead->properties()->sync($propertyIds);
    }

    public function updateStatus(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);
        $validStatuses = implode(',', \App\Services\CustomFieldService::getValidSlugs('lead_status'));
        $request->validate(['status' => "required|in:{$validStatuses}"]);

        $oldStatus = $lead->status;
        $lead->update(['status' => $request->status]);

        if ($oldStatus !== $lead->status) {
            event(new LeadStatusChanged($lead, $oldStatus));
            Hooks::doAction('lead.status_changed', $lead, $oldStatus);

            // Marking a lead lost/dead alerts management for cross-checking.
            if (\App\Services\LostLeadNotifier::isLostStatus($lead->status)) {
                \App\Services\LostLeadNotifier::notify($lead, $lead->status, $oldStatus);
            }
        }

        return response()->json(['success' => true]);
    }

    public function claim(Lead $lead)
    {
        $this->authorize('claim', $lead);

        // Only works for shark_tank or hybrid distribution
        $tenant = auth()->user()->tenant;
        if (! in_array($tenant->distribution_method, ['shark_tank', 'hybrid'])) {
            return response()->json(['error' => __('Claiming not enabled')], 422);
        }

        // Database-level locking to prevent race conditions
        $claimed = DB::transaction(function () use ($lead) {
            $locked = Lead::where('id', $lead->id)
                ->whereNull('agent_id')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return false;
            }

            $locked->update(['agent_id' => auth()->id()]);

            LeadClaim::create([
                'tenant_id' => auth()->user()->tenant_id,
                'lead_id' => $lead->id,
                'agent_id' => auth()->id(),
                'claimed' => true,
            ]);

            return true;
        });

        if ($claimed) {
            return response()->json(['success' => true, 'message' => __('Lead claimed successfully.')]);
        }

        return response()->json(['error' => __('Lead already claimed.')], 409);
    }

    public function uploadPhoto(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        $request->validate([
            'photos' => 'required|array|max:10',
            'photos.*' => 'image|mimes:jpg,jpeg,png,gif,webp|max:10240',
            'captions' => 'nullable|array',
            'captions.*' => 'nullable|string|max:255',
        ]);

        $uploaded = 0;
        foreach ($request->file('photos') as $i => $file) {
            $filename = uniqid('photo_').'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs("lead-photos/{$lead->id}", $filename, 'public');

            LeadPhoto::create([
                'tenant_id' => auth()->user()->tenant_id,
                'lead_id' => $lead->id,
                'uploaded_by' => auth()->id(),
                'filename' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'caption' => $request->input("captions.{$i}"),
            ]);
            $uploaded++;
        }

        return redirect()->route('leads.show', $lead)
            ->with('success', "{$uploaded} photo(s) uploaded.");
    }

    public function deletePhoto(Lead $lead, LeadPhoto $photo)
    {
        $this->authorize('update', $lead);

        if ($photo->lead_id !== $lead->id || $photo->tenant_id !== auth()->user()->tenant_id) {
            abort(404);
        }

        Storage::disk('public')->delete($photo->path);
        if ($photo->thumbnail_path) {
            Storage::disk('public')->delete($photo->thumbnail_path);
        }
        $photo->delete();

        return redirect()->route('leads.show', $lead)
            ->with('success', 'Photo deleted.');
    }

    public function export(Request $request)
    {
        $this->authorize('export', Lead::class);

        $query = Lead::with('agent');

        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('source')) {
            $query->where('lead_source', $request->source);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('temperature')) {
            $query->where('temperature', $request->temperature);
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->agent_id);
        }

        if ($request->filled('stacked') && $request->stacked) {
            $query->has('lists', '>=', 2)->orderByDesc('motivation_score');
        }

        if ($request->filled('dnc')) {
            $query->where('do_not_contact', true);
        }

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                __('First Name'), __('Last Name'), __('Phone'), __('Email'),
                __('Source'), __('Status'), __('Temperature'), __('Score'),
                __('Agent'), __('Created Date'),
            ]);
            foreach ($query->with('agent')->latest()->cursor() as $lead) {
                fputcsv($handle, [
                    $lead->first_name,
                    $lead->last_name,
                    $lead->phone,
                    $lead->email,
                    ucwords(str_replace('_', ' ', $lead->lead_source)),
                    ucwords(str_replace('_', ' ', $lead->status)),
                    ucfirst($lead->temperature),
                    $lead->motivation_score,
                    $lead->agent->name ?? '',
                    $lead->created_at?->format('Y-m-d'),
                ]);
            }
            fclose($handle);
        }, 'leads-export-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Users selectable in the "Assigned Agent" dropdown.
     *
     * Pass the lead being edited so its current owner stays selectable even
     * after being deactivated - otherwise saving the form would silently
     * reassign the lead to somebody else.
     */
    private function getAgents(?Lead $lead = null)
    {
        $user = auth()->user();

        // Managers assign within their team; plain agents only ever assign to themselves.
        if ($user->isManager()) {
            $teamIds = $user->teamUserIds();
            $teamIds[] = $user->id;

            $agents = User::assignable($user->tenant)
                ->whereIn('id', $teamIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        } elseif ($user->isAgent()) {
            return collect([$user]);
        } else {
            $agents = User::assignable($user->tenant)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        if ($lead?->agent_id && ! $agents->contains('id', $lead->agent_id)) {
            $current = User::where('tenant_id', $user->tenant_id)->find($lead->agent_id);

            if ($current) {
                $agents = $agents->push($current)->sortBy('name')->values();
            }
        }

        return $agents;
    }
}
