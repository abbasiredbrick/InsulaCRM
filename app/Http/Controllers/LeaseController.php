<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Lease;
use App\Models\User;
use App\Services\LeadToClientService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lease::class);

        $query = Lease::with(['buyer', 'property', 'agent']);
        $user = auth()->user();

        if ($user->isAgent() && ! $user->isAdmin() && ! $user->isListingAgent() && ! $user->isBuyersAgent()) {
            if ($user->isManager()) {
                $query->where(fn ($q) => $q->whereIn('agent_id', $user->teamUserIds())->orWhere('agent_id', $user->id));
            } else {
                $query->where('agent_id', $user->id);
            }
        }

        if ($request->filled('status') && in_array($request->status, ['active', 'renewed', 'expired', 'moved_out', 'expiring'], true)) {
            if ($request->status === 'expiring') {
                $query->whereIn('status', ['active', 'renewed'])
                    ->whereBetween('contract_end_date', [now()->startOfDay(), now()->startOfDay()->addDays(Lease::REMINDER_WINDOW_DAYS)]);
            } else {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('agent')) {
            $query->where('agent_id', $request->agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('unit_address', 'like', "%{$search}%")
                    ->orWhere('community', 'like', "%{$search}%")
                    ->orWhere('unit_no', 'like', "%{$search}%")
                    ->orWhereHas('buyer', fn ($bq) => $bq->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"));
            });
        }

        $leases = $query->latest()->withCount('buyer')->paginate(20)->withQueryString();

        $agents = $user->isAdmin() || $user->isManager()
            ? User::assignable($user->tenant)->orderBy('name')->get(['id', 'name'])
            : collect();

        $counts = [
            'active' => Lease::whereIn('status', ['active', 'renewed'])->count(),
            'expiring' => Lease::whereIn('status', ['active', 'renewed'])
                ->whereBetween('contract_end_date', [now()->startOfDay(), now()->startOfDay()->addDays(Lease::REMINDER_WINDOW_DAYS)])
                ->count(),
            'expired' => Lease::whereIn('status', ['active', 'renewed'])
                ->where('contract_end_date', '<', now()->startOfDay())
                ->count(),
            'moved_out' => Lease::where('status', 'moved_out')->count(),
        ];

        return view('leases.index', compact('leases', 'agents', 'counts'));
    }

    public function create()
    {
        $this->authorize('create', Lease::class);

        $tenant = auth()->user()->tenant;

        $rentLeads = Lead::where(fn ($q) => $q->where('deal_type', 'rent')->orWhereNull('deal_type'))
            ->with(['agent', 'property', 'properties'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $agents = User::assignable($tenant)->orderBy('name')->get(['id', 'name']);

        return view('leases.create', compact('rentLeads', 'agents'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Lease::class);

        $data = $request->validate([
            'lead_id' => 'nullable|exists:leads,id',
            'agent_id' => 'nullable|exists:users,id',
            'first_name' => 'nullable|required_without:lead_id|string|max:255',
            'last_name' => 'nullable|required_without:lead_id|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'property_id' => 'nullable|exists:properties,id',
            'unit_address' => 'nullable|string|max:255',
            'community' => 'nullable|string|max:255',
            'unit_no' => 'nullable|string|max:100',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'required|date',
            'rent_price' => 'nullable|numeric|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $data = array_merge([
            'lead_id' => null,
            'property_id' => null,
            'unit_address' => null,
            'community' => null,
            'unit_no' => null,
            'contract_start_date' => null,
            'rent_price' => null,
            'admin_fee' => null,
            'notes' => null,
        ], $data);

        if ($data['contract_start_date'] && now()->parse($data['contract_end_date'])->lte(now()->parse($data['contract_start_date']))) {
            $errors = (new \Illuminate\Support\MessageBag)->add(
                'contract_end_date',
                'The contract end date must be after the contract start date.',
            );

            return back()->withErrors($errors)->withInput();
        }

        $tenant = auth()->user()->tenant;
        $lead = $request->filled('lead_id') ? Lead::findOrFail($request->lead_id) : null;

        $lease = DB::transaction(function () use ($request, $data, $lead, $tenant) {
            $service = app(LeadToClientService::class);
            $buyer = null;
            $agentId = $request->filled('agent_id') ? $request->agent_id : ($lead?->agent_id ?? auth()->id());

            if ($lead) {
                $buyer = $service->convertFromLead($lead, [
                    'agent_id' => $agentId,
                    'subject' => 'Lease recorded — lead converted to Client',
                    'body' => 'Lease recorded for '.$lead->full_name.'. Converted to Client #',
                    'meta' => [],
                ]);
            } elseif ($data['first_name'] ?? null) {
                $buyer = $service->convertFromContact([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? '',
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ], $tenant->id, [
                    'agent_id' => $agentId,
                    'subject' => 'Lease recorded — contact converted to Client',
                ]);
            }

            $lease = Lease::create([
                'tenant_id' => $tenant->id,
                'lead_id' => $lead?->id,
                'property_id' => $data['property_id'] ?: null,
                'buyer_id' => $buyer?->id,
                'agent_id' => $agentId ?: null,
                'unit_address' => $data['unit_address'] ?: null,
                'community' => $data['community'] ?: null,
                'unit_no' => $data['unit_no'] ?: null,
                'contract_start_date' => $data['contract_start_date'] ?: null,
                'contract_end_date' => $data['contract_end_date'],
                'rent_price' => $data['rent_price'] ?: null,
                'admin_fee' => $data['admin_fee'] ?: null,
                'notes' => $data['notes'] ?: null,
                'status' => 'active',
            ]);

            if ($lease->property_id && $lease->property_id > 0 && $lease->property->availability !== 'leased') {
                $lease->property->update(['availability' => 'leased']);
            }

            Activity::create([
                'tenant_id' => $tenant->id,
                'lead_id' => $lead?->id,
                'agent_id' => $agentId,
                'type' => 'lease',
                'subject' => 'Lease recorded',
                'body' => 'Lease #'.$lease->id.' for '.($buyer?->full_name ?? '—').' on '.$lease->unit_label.' until '.$lease->contract_end_date->format('M j, Y'),
                'logged_at' => now(),
            ]);

            AuditLog::log('lease.recorded', $lease, null, [
                'lead_id' => $lead?->id,
                'buyer_id' => $buyer?->id,
                'contract_end_date' => $lease->contract_end_date->toDateString(),
            ]);

            return $lease;
        });

        return redirect()
            ->route('leases.index')
            ->with('success', 'Lease #'.$lease->id.' recorded. The client will be reminded '.Lease::REMINDER_WINDOW_DAYS.' days before expiry.');
    }

    public function renew(Request $request, Lease $lease)
    {
        $this->authorize('renew', $lease);

        $data = $request->validate([
            'contract_end_date' => 'required|date',
            'contract_start_date' => 'nullable|date',
            'rent_price' => 'nullable|numeric|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
        ]);

        if (request('contract_start_date') && now()->parse($data['contract_end_date'])->lte(now()->parse(request('contract_start_date')))) {
            return back()->withErrors(['contract_start_date' => 'The contract start date must be before the end date.'])->withInput();
        }

        $lease->update([
            'contract_start_date' => ($data['contract_start_date'] ?? null) ?: $lease->contract_start_date,
            'contract_end_date' => $data['contract_end_date'],
            'rent_price' => $data['rent_price'] ?? $lease->rent_price,
            'admin_fee' => $data['admin_fee'] ?? $lease->admin_fee,
            'status' => 'renewed',
            'reminder_sent_at' => null,
        ]);

        AuditLog::log('lease.renewed', $lease, null, [
            'contract_end_date' => $lease->contract_end_date->toDateString(),
        ]);

        return redirect()->route('leases.index')->with('success', 'Lease #'.$lease->id.' renewed until '.$lease->contract_end_date->format('M j, Y').'. The 45-day reminder window has been reset.');
    }

    /**
     * The client wants to move to a different unit when their contract expires.
     *
     * Opens a new rental search for the same client under the same agent, so the
     * normal pipeline (new_lead → … → moved_in) drives the next unit.
     */
    public function startNewSearch(Request $request, Lease $lease)
    {
        $this->authorize('update', $lease);

        $buyer = $lease->buyer;
        if (! $buyer) {
            return redirect()->route('leases.index')->with('error', 'This lease has no linked client.');
        }

        $agentId = $request->filled('agent_id') ? $request->agent_id : ($lease->agent_id ?? auth()->id());

        $newLead = DB::transaction(function () use ($lease, $buyer, $agentId, $request) {
            $newLead = Lead::create([
                'tenant_id' => $lease->tenant_id,
                'agent_id' => $agentId,
                'first_name' => $buyer->first_name,
                'last_name' => $buyer->last_name,
                'phone' => $buyer->phone,
                'email' => $buyer->email,
                'deal_type' => 'rent',
                'stage' => 'new_lead',
                'lead_source' => 'renewal_move',
                'notes' => trim(($lease->notes ?? '')."\n".'Existing lease #'.$lease->id.' ends '.$lease->contract_end_date->format('M j, Y').' — client wants to move to a different unit.'),
                'motivation_score' => 90,
            ]);

            if ($lease->property_id) {
                $newLead->properties()->attach($lease->property_id, ['relation_type' => 'current_unit']);
            }

            $requirements = $request->input('requirements', '');
            if ($requirements !== '') {
                $newLead->custom_fields = array_merge($newLead->custom_fields ?? [], ['sought_unit' => $requirements]);
                $newLead->save();
            }

            Activity::create([
                'tenant_id' => $lease->tenant_id,
                'lead_id' => $newLead->id,
                'agent_id' => $agentId,
                'type' => 'note',
                'subject' => 'New rental search started',
                'body' => 'Client wants to move from lease #'.$lease->id.' before it expires on '.$lease->contract_end_date->format('M j, Y').'. Started a new rental search.',
                'logged_at' => now(),
            ]);

            AuditLog::log('lease.move_search_started', $lease, null, [
                'new_lead_id' => $newLead->id,
                'agent_id' => $agentId,
            ]);

            return $newLead;
        });

        return redirect()->route('leads.show', $newLead)
            ->with('success', 'New rental search created for '.$buyer->full_name.' and assigned to the same agent.');
    }
}
