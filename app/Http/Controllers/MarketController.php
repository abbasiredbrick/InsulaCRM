<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\MarketContact;
use App\Models\MarketImport;
use App\Models\Property;
use App\Services\BusinessModeService;
use App\Services\MarketImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketController extends Controller
{
    public function index(Request $request)
    {
        $query = MarketContact::with(['import', 'caller']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('agent')) {
            $query->where('called_by', $request->agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('community', 'like', "%{$search}%")
                    ->orWhere('building', 'like', "%{$search}%");
            });
        }

        $contacts = $query->latest('created_at')->paginate(25);

        $counts = [
            'total' => MarketContact::count(),
            'pending' => MarketContact::where('status', 'pending')->count(),
            'reached' => MarketContact::whereIn('status', ['reached', 'converted'])->count(),
            'converted' => MarketContact::where('status', 'converted')->count(),
        ];

        $agents = $this->assignableAgents();

        return view('market.index', [
            'contacts' => $contacts,
            'counts' => $counts,
            'types' => MarketContact::TYPES,
            'statuses' => MarketContact::STATUSES,
            'agents' => $agents,
        ]);
    }

    public function create()
    {
        return view('market.create', [
            'agents' => $this->assignableAgents(),
            'importTypes' => MarketImport::TYPES,
        ]);
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:landlords,investors',
            'file' => 'required|file|mimes:xlsx,csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = \Illuminate\Support\Facades\Storage::disk('local')->putFile('market-imports', $file);

        $result = app(MarketImportService::class)->import(
            \Illuminate\Support\Facades\Storage::disk('local')->path($path),
            $extension,
            $data['name'],
            $data['type'],
            auth()->user()->tenant_id,
            auth()->id()
        );

        if (isset($result['error'])) {
            return back()->with('error', $result['error'])->withInput();
        }

        AuditLog::log('market.imported', $result['import'], [
            'type' => $data['type'],
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);

        return redirect()->route('market.index')->with(
            'success',
            "Import complete: {$result['imported']} contacts added, {$result['skipped']} skipped."
        );
    }

    public function show(MarketContact $marketContact)
    {
        $marketContact->load(['import.user', 'caller']);

        return view('market.show', [
            'contact' => $marketContact,
            'statuses' => MarketContact::STATUSES,
            'agents' => $this->assignableAgents(),
        ]);
    }

    public function update(Request $request, MarketContact $marketContact)
    {
        $data = $request->validate([
            'type' => 'required|in:landlord,investor',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'company' => 'nullable|string|max:255',
            'unit_no' => 'nullable|string|max:100',
            'building' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'community' => 'nullable|string|max:255',
            'property_category' => 'nullable|string|max:40',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|integer|min:0',
            'rent_price' => 'nullable|numeric|min:0',
            'unit_status' => 'nullable|string|max:40',
            'budget' => 'nullable|numeric|min:0',
            'preferred_type' => 'nullable|string|max:100',
            'requirements' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $marketContact->update($data);

        AuditLog::log('market.contact_updated', $marketContact, ['old' => $marketContact->getOriginal()]);

        return redirect()->route('market.show', $marketContact)->with('success', __('Contact updated.'));
    }

    public function updateStatus(Request $request, MarketContact $marketContact)
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(MarketContact::STATUSES)),
            'agent_id' => 'nullable|exists:users,id',
            'call_notes' => 'nullable|string',
        ]);

        $callingAgent = $data['agent_id'] ?? $marketContact->called_by;

        $marketContact->update([
            'status' => $data['status'],
            'called_by' => $callingAgent,
            'last_called_at' => $data['status'] !== 'pending' ? now() : $marketContact->last_called_at,
            'call_notes' => $data['call_notes'] ?? $marketContact->call_notes,
        ]);

        AuditLog::log('market.status_changed', $marketContact, ['status' => $data['status']]);

        return redirect()->route('market.show', $marketContact)->with('success', __('Call logged.'));
    }

    /**
     * Leasing cold call succeeded: add/update the unit in inventory under the
     * calling agent and keep the landlord as an owner lead linked to the unit.
     */
    public function convertToProperty(Request $request, MarketContact $marketContact)
    {
        if ($marketContact->status === 'converted') {
            return back()->with('error', __('This contact was already converted.'));
        }

        $data = $request->validate([
            'agent_id' => 'required|exists:users,id',
            'availability' => 'nullable|in:draft,ready_to_list,listed',
        ]);

        $this->assertAssignableAgent($data['agent_id']);

        if (! $marketContact->has_unit_details) {
            return back()->with('error', __('Add a unit / building reference before converting to inventory.'));
        }

        $tenantId = auth()->user()->tenant_id;
        $agentId = $data['agent_id'];

        $property = DB::transaction(function () use ($marketContact, $tenantId, $agentId, $data) {
            $ownerLead = $this->findOrCreateOwnerLead($marketContact, $tenantId, $agentId);

            $address = trim((string) $marketContact->address);
            if ($address === '') {
                $address = trim(implode(' ', array_filter([
                    $marketContact->unit_no,
                    $marketContact->building,
                    $marketContact->community,
                ])));
            }
            if ($address === '') {
                $address = trim((string) ($marketContact->community ?: $marketContact->building));
            }

            $building = trim((string) $marketContact->building);
            $community = trim((string) $marketContact->community);
            $sourceLabel = $marketContact->import?->name ?: 'cold list';

            $record = [
                'tenant_id' => $tenantId,
                'lead_id' => $ownerLead->id,
                'assigned_agent_id' => $agentId,
                'intent' => 'rent',
                'market_class' => 'ready',
                'availability' => $data['availability'] ?? 'ready_to_list',
                'property_category' => $marketContact->property_category ?: 'apartment',
                'community' => $community ?: null,
                'sub_community' => $building ?: null,
                'unit_no' => $marketContact->unit_no ?: null,
                'bedrooms' => $marketContact->bedrooms,
                'bathrooms' => $marketContact->bathrooms,
                'rent_price' => $marketContact->rent_price,
                'address' => $address,
                'city' => $community ?: '',
                'state' => '',
                'zip_code' => '',
                'owner_name' => $marketContact->first_name.' '.($marketContact->last_name ?? ''),
                'owner_phone' => $marketContact->phone ?: null,
                'owner_email' => $marketContact->email ?: null,
                'notes' => "Source: Market import ({$sourceLabel}). Unit reference: [{$marketContact->unit_summary}].",
            ];

            $existing = Property::where('tenant_id', $tenantId)
                ->where('intent', 'rent')
                ->whereIn('availability', ['draft', 'ready_to_list', 'listed', 'reserved'])
                ->where(function ($q) use ($marketContact, $building, $community) {
                    if ($marketContact->unit_no) {
                        $q->where('unit_no', $marketContact->unit_no);
                    } else {
                        $q->whereNull('unit_no');
                    }
                    $q->where(function ($q2) use ($building, $community) {
                        $q2->where('sub_community', $building)
                            ->orWhere('community', $community);
                    });
                })
                ->first();

            if ($existing) {
                $existing->update($record);
                $property = $existing;
            } else {
                $property = Property::create($record);
            }

            // Keep the owner lead linked on the pivot so the unit shows its owner.
            if (! $property->leads()->where('lead_id', $ownerLead->id)->exists()) {
                $property->leads()->attach($ownerLead->id, [
                    'tenant_id' => $tenantId,
                    'relation_type' => 'owner',
                ]);
            }

            $this->logCallActivity($ownerLead, $agentId, 'Cold call succeeded — unit added to inventory.', $marketContact->unit_summary);

            return $property;
        });

        $marketContact->update([
            'status' => 'converted',
            'converted_type' => 'property',
            'converted_id' => $property->id,
            'called_by' => $data['agent_id'],
            'last_called_at' => $marketContact->last_called_at ?? now(),
        ]);

        AuditLog::log('market.converted_to_property', $marketContact, ['property_id' => $property->id]);

        return redirect()->route('inventory.show', $property)
            ->with('success', __('Unit added to inventory for :agent.', ['agent' => $property->assignedAgent?->name]));
    }

    /**
     * Sales cold call succeeded: create (or match) a sales lead prefilled with
     * the imported details so the agent can review and adjust anything wrong.
     */
    public function convertToLead(Request $request, MarketContact $marketContact)
    {
        if ($marketContact->status === 'converted') {
            return back()->with('error', __('This contact was already converted.'));
        }

        $data = $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);

        $this->assertAssignableAgent($data['agent_id']);

        $tenantId = auth()->user()->tenant_id;
        $agentId = $data['agent_id'];

        $lead = DB::transaction(function () use ($marketContact, $tenantId, $agentId) {
            $existing = $this->findLeadByContact($marketContact, $tenantId);

            if ($existing) {
                $this->logCallActivity($existing, $agentId, 'Cold call succeeded — existing lead matched. Review and update details.', $marketContact->requirements);

                return $existing;
            }

            $lead = Lead::create([
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'first_name' => $marketContact->first_name ?: 'Unknown',
                'last_name' => $marketContact->last_name,
                'phone' => $marketContact->phone,
                'email' => $marketContact->email,
                'lead_source' => 'cold_call',
                'status' => 'new',
                'contact_type' => 'buyer_lead',
                'temperature' => 'cold',
                'deal_type' => 'sale',
                'stage' => 'new_lead',
                'notes' => trim(implode(' ', array_filter([
                    $marketContact->company ? 'Company: '.$marketContact->company : null,
                    $marketContact->requirements ? 'Requirements: '.$marketContact->requirements : null,
                    'Source: Market import.',
                ]))),
                'custom_fields' => array_filter([
                    'sought_unit' => $marketContact->preferred_type ?: null,
                    'budget' => $marketContact->budget ?: null,
                ]),
            ]);

            $this->logCallActivity($lead, $agentId, 'Cold call succeeded — sales lead created from market import.', $marketContact->requirements);

            return $lead;
        });

        $marketContact->update([
            'status' => 'converted',
            'converted_type' => 'lead',
            'converted_id' => $lead->id,
            'called_by' => $agentId,
            'last_called_at' => $marketContact->last_called_at ?? now(),
        ]);

        AuditLog::log('market.converted_to_lead', $marketContact, ['lead_id' => $lead->id]);

        return redirect()->route('leads.edit', $lead)
            ->with('success', __('Lead created. Review the details and adjust anything that differs from the call.'));
    }

    public function destroy(MarketContact $marketContact)
    {
        AuditLog::log('market.contact_deleted', $marketContact);

        $marketContact->delete();

        return redirect()->route('market.index')->with('success', __('Contact removed.'));
    }

    // ── Helpers ──────────────────────────────────────────────

    protected function findOrCreateOwnerLead(MarketContact $contact, int $tenantId, int $agentId): Lead
    {
        $existing = $this->findLeadByContact($contact, $tenantId);

        if ($existing) {
            return $existing;
        }

        return Lead::create([
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'first_name' => $contact->first_name ?: 'Unknown',
            'last_name' => $contact->last_name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'lead_source' => 'cold_call',
            'status' => 'new',
            'contact_type' => 'seller_lead',
            'temperature' => 'cold',
            'deal_type' => 'rent',
            'stage' => 'new_lead',
            'notes' => trim(implode(' ', array_filter([
                $contact->company ? 'Company: '.$contact->company : null,
                $contact->requirements ? 'Requirements: '.$contact->requirements : null,
                'Unit owner from market import. Unit: '.$contact->unit_summary.'.',
            ]))),
        ]);
    }

    protected function findLeadByContact(MarketContact $contact, int $tenantId): ?Lead
    {
        if ($contact->phone) {
            $lead = Lead::where('tenant_id', $tenantId)->where('phone', $contact->phone)->first();
            if ($lead) {
                return $lead;
            }
        }

        if ($contact->email) {
            return Lead::where('tenant_id', $tenantId)->where('email', $contact->email)->first();
        }

        return null;
    }

    protected function logCallActivity(Lead $lead, int $agentId, string $subject, ?string $body): void
    {
        Activity::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $agentId,
            'type' => 'call',
            'subject' => $subject,
            'body' => $body,
            'logged_at' => now(),
        ]);
    }

    protected function assignableAgents()
    {
        return \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
            ->where(function ($query) {
                $query->whereHas('role', fn ($q) => $q->whereIn('name', BusinessModeService::getAssignableRoleNames()))
                    ->orWhereHas('secondaryRoles', fn ($q) => $q->whereIn('name', BusinessModeService::getAssignableRoleNames()));
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->pluck('name', 'id');
    }

    protected function assertAssignableAgent(int $agentId): void
    {
        $assignable = $this->assignableAgents()->has($agentId);

        if (! $assignable) {
            abort(422, __('Select a valid agent.'));
        }
    }
}
