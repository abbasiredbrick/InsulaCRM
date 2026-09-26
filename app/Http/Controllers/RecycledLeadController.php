<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\RecycledLead;
use App\Services\BusinessModeService;
use App\Services\LeadRecycleService;
use App\Services\RecycledLeadRegenerationService;
use App\Services\RecycledLeadSearchService;
use App\Services\RecycledLeadsImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RecycledLeadController extends Controller
{
    public function index(Request $request)
    {
        $search = app(RecycledLeadSearchService::class);
        $query = $search->pooledLeads()->with(['assignee', 'originalLead', 'linkedLead', 'regeneratedLead']);

        if ($term = trim((string) $request->query('search'))) {
            $search->applyTerm($query, $term);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('portal')) {
            $query->where('portal', $request->portal);
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('agent')) {
            $query->where('assignee_id', $request->agent);
        }

        if ($request->filled('deal_type')) {
            $query->where('original_deal_type', $request->deal_type);
        }

        $search->applyLeadDateRange($query, $request->query('date_from'), $request->query('date_to'));

        $perPage = in_array((int) $request->query('per_page', 25), [10, 25, 50, 100], true) ? (int) $request->query('per_page') : 25;

        $recycled = $query->latest('created_at')->paginate($perPage);

        $base = fn ($w = []) => RecycledLead::query()->where($w);

        $counts = [
            'total' => $base()->count(),
            'pending' => $base(['status' => 'pending'])->count(),
            'call_back' => $base(['status' => 'call_back'])->count(),
            'regenerated' => $base(['status' => 'regenerated'])->count(),
            'already_active' => $base(['status' => 'already_active'])->count(),
        ];

        return view('recycled.index', [
            'recycled' => $recycled,
            'counts' => $counts,
            'statuses' => RecycledLead::STATUSES,
            'portals' => RecycledLead::PORTALS,
            'categories' => RecycledLead::CATEGORIES,
            'sources' => RecycledLead::SOURCES,
            'intents' => RecycledLead::INTENTS,
            'agents' => $this->assignableAgents(),
        ]);
    }

    public function create()
    {
        return view('recycled.create', [
            'agents' => $this->assignableAgents(),
            'portals' => RecycledLead::PORTALS,
            'categories' => RecycledLead::CATEGORIES,
        ]);
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'portal' => 'required|in:'.implode(',', array_keys(RecycledLead::PORTALS)),
            'category' => 'nullable|in:'.implode(',', array_keys(RecycledLead::CATEGORIES)),
            'agent_id' => 'nullable|exists:users,id',
            'file' => 'required|file|mimes:xlsx,csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $path = Storage::disk('local')->putFile('recycled-imports', $file);

        try {
            $result = app(RecycledLeadsImportService::class)->import(
                Storage::disk('local')->path($path),
                $extension,
                auth()->user()->tenant_id,
                auth()->id(),
                $data['portal'],
                $data['agent_id'] ?? null,
                $data['name'] ?? null,
                $data['category'] ?? null,
            );
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete($path);

            return back()->with('error', $e->getMessage())->withInput();
        }

        Storage::disk('local')->delete($path);

        $audit = array_filter([
            'portal' => $data['portal'],
            'import_name' => $data['name'] ?? null,
            'imported' => $result['imported'],
            'already_active' => $result['already_active'],
            'duplicates' => $result['duplicates'],
            'dropped_internal' => $result['dropped_internal'],
            'dropped_agent' => $result['dropped_agent'],
            'flagged_review' => $result['flagged_review'],
            'flagged_no_purpose' => $result['flagged_no_purpose'],
            'skipped' => $result['skipped'],
            'skipped_no_contact' => $result['skipped_no_contact'],
        ]);

        AuditLog::log('recycled.imported', null, $audit);

        $message = "Import complete: {$result['imported']} added to the pool, "
            ."{$result['already_active']} already-active (linked), "
            ."{$result['duplicates']} duplicates skipped.";

        if ($result['dropped_internal'] > 0) {
            $message .= " {$result['dropped_internal']} dropped as internal / test — the buyer is on your own team (office email domain or tenant number), so they are not a real portal contact.";
        }

        if ($result['dropped_agent'] > 0) {
            $message .= " {$result['dropped_agent']} dropped — tagged from_agent, so the enquiry was created by your own agent rather than by a buyer.";
        }

        if ($result['flagged_review'] > 0) {
            $message .= " {$result['flagged_review']} flagged for review — those rows use masked portal emails like whatsapp.971501567554@id.bayut.com (actually a WhatsApp number) or Apple private-relay addresses, so they were imported but marked for an agent to double-check before outreach.";
        }

        if ($result['flagged_no_purpose'] > 0) {
            $message .= " {$result['flagged_no_purpose']} flagged — no Purpose column, so the lease/sale type could not be determined; the agent confirms it before regenerating.";
        }

        if ($result['skipped_no_contact'] > 0) {
            $message .= " {$result['skipped_no_contact']} dropped — no phone / WhatsApp / email on the row, so there is no way to reach that lead.";
        }

        if ($result['skipped'] > $result['skipped_no_contact']) {
            $message .= ' '.($result['skipped'] - $result['skipped_no_contact']).' blank rows skipped.';
        }

        return redirect()->route('recycled.index')->with('success', $message);
    }

    public function show(RecycledLead $recycledLead)
    {
        $recycledLead->load(['assignee', 'originalLead', 'linkedLead', 'regeneratedLead', 'creator']);

        return view('recycled.show', [
            'recycled' => $recycledLead,
            'statuses' => RecycledLead::STATUSES,
            'sources' => RecycledLead::SOURCES,
            'intents' => RecycledLead::INTENTS,
            'agents' => $this->assignableAgents(),
        ]);
    }

    public function updateStatus(Request $request, RecycledLead $recycledLead)
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(RecycledLead::STATUSES)),
            'agent_id' => 'nullable|exists:users,id',
            'call_notes' => 'nullable|string',
            'next_call_at' => 'nullable|date',
        ]);

        DB::transaction(function () use ($data, $recycledLead) {
            $changingOutcome = in_array($data['status'], ['reached', 'not_reached', 'not_interested', 'call_back', 'do_not_contact', 'wrong_number'], true);

            $note = trim((string) ($data['call_notes'] ?? ''));
            $existing = trim((string) $recycledLead->call_notes);

            $recycledLead->fill([
                'status' => $data['status'],
                'assignee_id' => $data['agent_id'] ?? $recycledLead->assignee_id,
                'last_contacted_at' => $changingOutcome ? now() : $recycledLead->last_contacted_at,
                'next_call_at' => $data['next_call_at'] ?? $recycledLead->next_call_at,
                'call_notes' => $note === '' ? $existing : trim($existing."\n[".now()->format('M d, H:i').'] '.(auth()->user()->name ?? 'user').': '.$note),
            ])->save();

            if ($data['status'] === 'do_not_contact' && $recycledLead->original_lead_id) {
                $recycledLead->originalLead?->update(['do_not_contact' => true]);
            }

            if ($data['status'] === 'already_active' && $data['agent_id'] && $recycledLead->linked_lead_id) {
                $recycledLead->linkedLead?->update(['agent_id' => $data['agent_id']]);
            }
        });

        AuditLog::log('recycled.status_changed', $recycledLead, ['status' => $data['status']]);

        return back()->with('success', __('Outcome logged.'));
    }

    public function assign(Request $request, RecycledLead $recycledLead)
    {
        $data = $request->validate([
            'agent_id' => 'required|exists:users,id',
        ]);

        $this->assertAssignableAgent($data['agent_id']);

        $recycledLead->update(['assignee_id' => $data['agent_id']]);

        AuditLog::log('recycled.assigned', $recycledLead, ['agent_id' => $data['agent_id']]);

        return back()->with('success', __('Assigned.'));
    }

    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:recycled_leads,id',
            'agent_id' => 'required|exists:users,id',
        ]);

        $this->assertAssignableAgent($data['agent_id']);

        $count = RecycledLead::whereKey($data['ids'])->where('tenant_id', auth()->user()->tenant_id)
            ->update(['assignee_id' => $data['agent_id']]);

        AuditLog::log('recycled.bulk_assigned', null, ['count' => $count, 'agent_id' => $data['agent_id']]);

        return back()->with('success', "{$count}".($count === 1 ? ' record' : ' records').' assigned to agent.');
    }

    public function regenerate(Request $request, RecycledLead $recycledLead)
    {
        $data = $request->validate([
            'intent' => 'required|in:'.implode(',', array_keys(RecycledLead::INTENTS)),
            'agent_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            'handover_date' => 'nullable|date',
        ]);

        $wasActiveLead = $recycledLead->status === 'already_active';

        try {
            $lead = app(RecycledLeadRegenerationService::class)->regenerate(
                $recycledLead,
                $data['intent'],
                $data['agent_id'] ?? null,
                $data['notes'] ?? null,
                $data['handover_date'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $wasActiveLead
            ? __('Intent added to the existing lead — no second lead created. Review the details and adjust anything that differs from the call.')
            : __('Lead regenerated and activated. Review the details and adjust anything that differs from the call.');

        return redirect()->route('leads.edit', $lead)->with('success', $message);
    }

    /**
     * Manual "recycle now" — runs the 60-day scan for this tenant.
     */
    public function runRecycle()
    {
        $count = app(LeadRecycleService::class)->recycleTenant(auth()->user()->tenant);

        return redirect()->route('recycled.index')->with(
            'success',
            $count > 0
                ? "{$count} lead(s) moved into the Recycled Leads pool (60-day rule)."
                : 'No overdue leads found — anything lost, dead or in nurture needs 60+ days untouched to be recycled.'
        );
    }

    public function destroy(RecycledLead $recycledLead)
    {
        if ($recycledLead->original_lead_id) {
            $recycledLead->originalLead?->update(['recycled_at' => null]);
        }

        AuditLog::log('recycled.deleted', $recycledLead);

        $recycledLead->delete();

        return redirect()->route('recycled.index')->with('success', __('Pool record removed.'));
    }

    // ── Helpers ──────────────────────────────────────────────

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
        if (! $this->assignableAgents()->has($agentId)) {
            abort(422, __('Select a valid agent.'));
        }
    }
}
