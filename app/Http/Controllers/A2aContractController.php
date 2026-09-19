<?php

namespace App\Http\Controllers;

use App\Models\A2aContract;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Agent-to-Agent (A2A) commission sharing contracts with external agents and
 * freelancers. The flow is fully in-house: draft → mark sent → the counterparty
 * signs the printed PDF → the signed copy is uploaded and attached to leads.
 */
class A2aContractController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $contracts = A2aContract::with('agent')
            ->when(! $user->isAdmin(), fn ($q) => $q->where('agent_id', $user->id))
            ->latest()
            ->get();

        return view('a2a.index', compact('contracts'));
    }

    public function create()
    {
        return view('a2a.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'counterparty_name' => 'required|string|max:190',
            'counterparty_email' => 'nullable|email|max:190',
            'counterparty_company' => 'nullable|string|max:190',
            'counterparty_address' => 'nullable|string|max:255',
            'share_pct' => 'required|numeric|min:0|max:100',
            'funding_source' => 'required|in:from_agent,from_company,from_both',
            'terms' => 'nullable|string',
        ]);

        $contract = A2aContract::create([
            'tenant_id' => auth()->user()->tenant_id,
            'agent_id' => auth()->id(),
            'counterparty_name' => $data['counterparty_name'],
            'counterparty_email' => $data['counterparty_email'] ?? null,
            'counterparty_company' => $data['counterparty_company'] ?? null,
            'counterparty_address' => $data['counterparty_address'] ?? null,
            'share_pct' => $data['share_pct'],
            'funding_source' => $data['funding_source'],
            'terms' => $data['terms'] ?? null,
            'status' => A2aContract::STATUS_DRAFT,
        ]);

        AuditLog::log('a2a.contract_created', $contract, ['contract_number' => $contract->contract_number]);

        return redirect()->route('a2a.show', $contract)->with('success', __('Contract :number created.', ['number' => $contract->contract_number]));
    }

    public function show(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $attachLeads = $contract->agent_id
            ? Lead::where('agent_id', $contract->agent_id)->latest()->limit(50)->get()
            : collect();

        return view('a2a.show', compact('contract', 'attachLeads'));
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

    public function void(A2aContract $contract)
    {
        $this->authorizeOrOwn($contract);

        $contract->update(['status' => A2aContract::STATUS_VOID]);

        AuditLog::log('a2a.contract_voided', $contract);

        return back()->with('success', __('Contract voided.'));
    }

    /**
     * Add the counterparty of a signed contract as an external co-agent on a
     * lead, carrying the contract's share terms into the commission calc.
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

    protected function authorizeOrOwn(A2aContract $contract): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($contract->agent_id === $user->id) {
            return;
        }

        abort(403);
    }
}