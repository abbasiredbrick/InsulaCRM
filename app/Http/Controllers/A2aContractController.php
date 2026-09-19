<?php

namespace App\Http\Controllers;

use App\Models\A2aContract;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Agent-to-Agent (A2A) commission sharing contracts with external agents and
 * freelancers. The flow is fully in-house: draft → mark sent → the counterparty
 * signs the printed PDF → the signed copy is uploaded → the agent's manager
 * confirms completion → for lead-scoped contracts the external agent is
 * automatically attached as an external co-agent on the linked lead.
 *
 * Contracts can be scoped to a lead (an external agent collaborating on one of
 * our leads) or to a property (we share a unit with a colleague of another
 * agency and their client).
 */
class A2aContractController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $contracts = A2aContract::with('agent', 'lead', 'property')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('agent_id', $user->id))
            ->latest()
            ->get();

        return view('a2a.index', compact('contracts'));
    }

    public function create(Request $request)
    {
        $leadId = $request->integer('lead_id') ?: null;
        $propertyId = $request->integer('property_id') ?: null;

        $lead = $leadId ? Lead::find($leadId) : null;
        $property = $propertyId ? Property::find($propertyId) : null;

        $scopeType = old('scope_type', $lead ? A2aContract::SCOPE_LEAD : ($property ? A2aContract::SCOPE_PROPERTY : A2aContract::SCOPE_LEAD));

        $selectedItem = null;
        $selectedOptions = [];

        if ($scopeType === A2aContract::SCOPE_LEAD) {
            $selectedLeadId = (int) old('lead_id', $lead?->id);
            if ($selectedLeadId) {
                $selectedLead = $lead?->id === $selectedLeadId ? $lead : Lead::find($selectedLeadId);
                if ($selectedLead) {
                    $selectedOptions[] = ['value' => (string) $selectedLead->id, 'label' => trim($selectedLead->full_name ?: $selectedLead->phone ?: '#'.$selectedLead->id).($selectedLead->phone && $selectedLead->full_name ? ' — '.$selectedLead->phone : '')];
                }
            }
            $selectedItem = $selectedLeadId ?: '';
        } else {
            $selectedPropertyId = (int) old('property_id', $property?->id);
            if ($selectedPropertyId) {
                $selectedProperty = $property?->id === $selectedPropertyId ? $property : Property::find($selectedPropertyId);
                if ($selectedProperty) {
                    $selectedOptions[] = ['value' => (string) $selectedProperty->id, 'label' => $selectedProperty->optionLabel()];
                }
            }
            $selectedItem = $selectedPropertyId ?: '';
        }

        $defaultTx = match ($lead?->deal_type) {
            'sale' => 'sale',
            default => 'lease',
        };

        $transactionType = old('transaction_type', $defaultTx);

        return view('a2a.create', compact('scopeType', 'transactionType', 'selectedItem', 'selectedOptions'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'scope_type' => 'required|in:lead,property',
            'lead_id' => 'nullable|integer',
            'property_id' => 'nullable|integer',
            'transaction_type' => 'nullable|in:sale,lease',
            'counterparty_name' => 'required|string|max:190',
            'counterparty_email' => 'nullable|email|max:190',
            'counterparty_company' => 'required|string|max:190',
            'counterparty_address' => 'nullable|string|max:255',
            'share_pct' => 'required|numeric|min:0|max:100',
            'funding_source' => 'required|in:from_agent,from_company,from_both',
            'terms' => 'nullable|string',
        ]);

        if ($data['scope_type'] === A2aContract::SCOPE_LEAD) {
            if (blank($data['lead_id'] ?? null)) {
                return back()->withErrors(['lead_id' => __('Choose the lead this contract covers.')])->withInput();
            }
            $lead = Lead::find($data['lead_id']);
            if (! $lead) {
                return back()->withErrors(['lead_id' => __('Lead not found.')])->withInput();
            }
            if (! auth()->user()->isAdmin() && $lead->agent_id !== auth()->id() && ! auth()->user()->can('shareAgents', $lead)) {
                abort(403);
            }
            $propertyId = $lead->property?->id;
        } else {
            if (blank($data['property_id'] ?? null)) {
                return back()->withErrors(['property_id' => __('Choose the property this contract covers.')])->withInput();
            }
            $propertyId = $data['property_id'];
            $lead = null;
        }

        $derivedTx = match ($lead?->deal_type) {
            'sale' => 'sale',
            default => 'lease',
        };

        $transactionType = $data['transaction_type'] ?? $derivedTx;

        $contract = A2aContract::create([
            'tenant_id' => auth()->user()->tenant_id,
            'agent_id' => auth()->id(),
            'counterparty_name' => $data['counterparty_name'],
            'counterparty_email' => $data['counterparty_email'] ?? null,
            'counterparty_company' => $data['counterparty_company'],
            'counterparty_address' => $data['counterparty_address'] ?? null,
            'share_pct' => $data['share_pct'],
            'funding_source' => $data['funding_source'],
            'terms' => $data['terms'] ?? null,
            'scope_type' => $data['scope_type'],
            'lead_id' => $data['scope_type'] === A2aContract::SCOPE_LEAD ? $data['lead_id'] : null,
            'property_id' => $propertyId,
            'transaction_type' => $transactionType,
            'status' => A2aContract::STATUS_DRAFT,
        ]);

        AuditLog::log('a2a.contract_created', $contract, ['contract_number' => $contract->contract_number, 'scope' => $contract->scope_type]);

        return redirect()->route('a2a.show', $contract)->with('success', __('Contract :number created.', ['number' => $contract->contract_number]));
    }

    public function show(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $contract->load(['agent', 'lead', 'property', 'confirmer']);

        $attachLeads = Lead::with('property')
            ->where(function ($q) use ($contract) {
                $q->where('agent_id', $contract->agent_id);
                if ($contract->property_id) {
                    $q->orWhereHas('property', fn ($q2) => $q2->whereKey($contract->property_id));
                }
            })
            ->latest()
            ->limit(50)
            ->get();

        $user = auth()->user();
        $canConfirm = $user->isAdmin()
            || $user->isOwner()
            || ($contract->agent_id && $user->managesUser($contract->agent));

        return view('a2a.show', compact('contract', 'attachLeads', 'canConfirm'));
    }

    public function printContract(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $tenant = auth()->user()->tenant;

        return view('a2a.print', compact('contract', 'tenant'));
    }

    public function markSent(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $contract->update([
            'status' => A2aContract::STATUS_SENT,
            'sent_at' => $contract->sent_at ?? now(),
        ]);

        AuditLog::log('a2a.contract_sent', $contract);

        return back()->with('success', __('Contract marked as sent.'));
    }

    public function uploadSigned(Request $request, A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $request->validate([
            'signed_copy' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('signed_copy');
        $path = $file->store("a2a-contracts/{$contract->id}", 'local');

        $contract->update([
            'status' => A2aContract::STATUS_SIGNED,
            'signed_at' => $contract->signed_at ?? now(),
            'signed_file_path' => $path,
            'signed_original_name' => $file->getClientOriginalName(),
        ]);

        AuditLog::log('a2a.contract_signed', $contract, ['file' => $file->getClientOriginalName()]);

        return back()->with('success', __('Signed copy uploaded. Contract is now signed.'));
    }

    public function downloadSigned(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        if (! $contract->signed_file_path) {
            return back()->with('error', __('No signed copy uploaded yet.'));
        }

        return Storage::disk('local')->download($contract->signed_file_path, $contract->signed_original_name ?: 'signed-'.$contract->contract_number.'.pdf');
    }

    /**
     * Manager sign-off. Once a signed contract is confirmed by the agent's
     * manager (or an admin/owner), a lead-scoped contract automatically adds
     * the counterparty as an external co-agent on the linked lead.
     */
    public function confirmComplete(A2aContract $contract)
    {
        $this->authorizeConfirm($contract);

        if ($contract->status !== A2aContract::STATUS_SIGNED) {
            return back()->with('error', __('Only a signed contract can be confirmed.'));
        }

        $contract->update([
            'status' => A2aContract::STATUS_CONFIRMED,
            'confirmed_at' => $contract->confirmed_at ?? now(),
            'confirmed_by' => auth()->id(),
        ]);

        $autoAttached = false;

        if ($contract->isLeadScoped() && $contract->lead_id) {
            $lead = Lead::find($contract->lead_id);

            if ($lead) {
                $exists = $lead->leadAgents()
                    ->where(fn ($q) => $q->where('a2a_contract_id', $contract->id)->orWhere('external_email', $contract->counterparty_email))
                    ->where('status', LeadAgent::STATUS_ACTIVE)
                    ->exists();

                if (! $exists) {
                    $lead->leadAgents()->create([
                        'tenant_id' => $lead->tenant_id,
                        'agent_id' => null,
                        'external_name' => $contract->counterparty_name,
                        'external_email' => $contract->counterparty_email,
                        'external_company' => $contract->counterparty_company,
                        'commission_pct' => $contract->share_pct,
                        'share_funding' => $contract->funding_source,
                        'a2a_contract_id' => $contract->id,
                        'status' => LeadAgent::STATUS_ACTIVE,
                    ]);
                    $autoAttached = true;
                }
            }
        }

        AuditLog::log('a2a.contract_confirmed', $contract, ['lead_id' => $contract->lead_id, 'auto_attached' => $autoAttached]);

        $message = $autoAttached
            ? __('Contract confirmed. :name was automatically added as the external co-agent on the linked lead.', ['name' => $contract->counterparty_name])
            : __('Contract confirmed.');

        return back()->with('success', $message);
    }

    public function void(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $contract->update(['status' => A2aContract::STATUS_VOID]);

        AuditLog::log('a2a.contract_voided', $contract);

        return back()->with('success', __('Contract voided.'));
    }

    /**
     * Add the counterparty of a signed/confirmed contract as an external
     * co-agent on a lead, carrying the contract's share terms into the
     * commission calc. Mainly used for property-scoped contracts once the
     * client lead exists.
     */
    public function attachToLead(Request $request, A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $data = $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
        ]);

        $lead = Lead::where('id', $data['lead_id'])->first();

        if (! $lead) {
            return back()->with('error', __('Lead not found.'));
        }

        if (! auth()->user()->isAdmin() && $lead->agent_id !== auth()->id() && ! auth()->user()->can('shareAgents', $lead)) {
            abort(403);
        }

        if ($contract->status !== A2aContract::STATUS_SIGNED && $contract->status !== A2aContract::STATUS_CONFIRMED) {
            return back()->with('error', __('Only a signed or confirmed contract can be attached to a lead.'));
        }

        $exists = $lead->leadAgents()
            ->where(fn ($q) => $q->where('a2a_contract_id', $contract->id)->orWhere('external_email', $contract->counterparty_email))
            ->where('status', LeadAgent::STATUS_ACTIVE)
            ->exists();

        if ($exists) {
            return back()->with('error', __('This counterparty is already on the lead.'));
        }

        $lead->leadAgents()->create([
            'tenant_id' => $lead->tenant_id,
            'agent_id' => null,
            'external_name' => $contract->counterparty_name,
            'external_email' => $contract->counterparty_email,
            'external_company' => $contract->counterparty_company,
            'commission_pct' => $contract->share_pct,
            'share_funding' => $contract->funding_source,
            'a2a_contract_id' => $contract->id,
            'status' => LeadAgent::STATUS_ACTIVE,
        ]);

        AuditLog::log('a2a.contract_attached', $contract, ['lead_id' => $lead->id]);

        return redirect()->route('leads.show', $lead)->with('success', __('Counterparty added to the lead from contract :number.', ['number' => $contract->contract_number]));
    }

    /**
     * JSON search used by the scoped selects on the create form (lead searches).
     */
    public function searchLeads(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $user = auth()->user();

        $leads = Lead::with('property')
            ->when(! $user->isAdmin(), fn ($query) => $query->where('agent_id', $user->id))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('reference', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $leads->map(fn (Lead $lead) => [
                'value' => (string) $lead->id,
                'label' => trim($lead->full_name ?: $lead->phone ?: '#'.$lead->id)
                    .($lead->property ? ' — '.$lead->property->display_name : '')
                    .($lead->phone ? ' • '.$lead->phone : ''),
            ]),
        ]);
    }

    /**
     * JSON search used by the scoped selects on the create form (property searches).
     */
    public function searchProperties(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $properties = Property::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('address', 'like', "%{$q}%")
                        ->orWhere('community', 'like', "%{$q}%")
                        ->orWhere('sub_community', 'like', "%{$q}%")
                        ->orWhere('developer_name', 'like', "%{$q}%")
                        ->orWhere('title_deed_no', 'like', "%{$q}%")
                        ->orWhere('plot_no', 'like', "%{$q}%");
                });
            })
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $properties->map(fn (Property $property) => [
                'value' => (string) $property->id,
                'label' => $property->optionLabel(),
            ]),
        ]);
    }

    protected function authorizeOrOwn(A2aContract $contract): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isOwner()) {
            return;
        }

        if ($contract->agent_id === $user->id) {
            return;
        }

        abort(403);
    }

    /**
     * Only the agent's manager (up the reporting chain) or an admin/owner may
     * confirm that a signed contract is complete.
     */
    protected function authorizeConfirm(A2aContract $contract): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->isOwner()) {
            return;
        }

        if ($contract->agent_id && $user->managesUser($contract->agent)) {
            return;
        }

        abort(403);
    }
}