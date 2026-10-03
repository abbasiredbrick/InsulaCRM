<?php

namespace App\Http\Controllers;

use App\Events\DealStageChanged;
use App\Facades\Hooks;
use App\Http\Requests\DealRequest;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\DealDocument;
use App\Models\DealOffer;
use App\Models\Role;
use App\Models\TransactionChecklist;
use App\Models\User;
use App\Notifications\BuyerMatchFound;
use App\Notifications\DealStageChanged as DealStageChangedNotification;
use App\Services\BuyerScoreService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class DealController extends Controller
{
    /**
     * Table (list) view of deals — a findable alternative to the kanban board.
     * Each row links to the deal detail page so the user can open a deal and
     * take further action (upload documents, generate offer letters, ...).
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Deal::class);

        $query = Deal::with(['lead.property', 'lease', 'agent']);

        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
        }

        // Deal type filter: rent (leasing) / sale / all.
        // Legacy deals have a NULL type and are treated as sales.
        if ($request->filled('deal_type')) {
            $dealType = in_array($request->deal_type, ['rent', 'sale'], true) ? $request->deal_type : null;
            if ($dealType) {
                $query->where($dealType === 'rent'
                    ? fn ($q) => $q->where('deal_type', 'rent')
                    : fn ($q) => $q->where('deal_type', 'sale')->orWhereNull('deal_type'));
            }
        }

        $validStages = array_keys(Deal::stages());
        if ($request->filled('stage') && in_array($request->stage, $validStages, true)) {
            $query->where('stage', $request->stage);
        }

        if ($request->filled('agent')) {
            $query->where('agent_id', $request->agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('lead', function ($lq) use ($search) {
                        $lq->where(function ($inner) use ($search) {
                            $inner->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('reference', 'like', "%{$search}%");
                        });
                    })
                    ->orWhereHas('lead.property', function ($pq) use ($search) {
                        $pq->where('address', 'like', "%{$search}%");
                    });
            });
        }

        // Lead "tags" — temperature / source filters (mirrors the board).
        if ($request->filled('temp') && in_array($request->temp, ['hot', 'warm', 'cold'], true)) {
            $query->whereHas('lead', fn ($q) => $q->where('temperature', $request->temp));
        }

        if ($request->filled('source')) {
            $query->whereHas('lead', fn ($q) => $q->where('lead_source', $request->source));
        }

        // Sorting (whitelist — never sort directly on user input).
        $sortable = [
            'title' => 'title',
            'deal_type' => 'deal_type',
            'stage' => 'stage',
            'contract_price' => 'contract_price',
            'fee' => auth()->user()->tenant->business_mode === 'realestate' ? 'total_commission' : 'assignment_fee',
            'agent' => 'agent_id',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];
        $sort = $request->input('sort', 'updated_at');
        $sort = array_key_exists($sort, $sortable) ? $sort : 'updated_at';
        $direction = strtolower((string) $request->input('direction', 'desc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'desc';

        $deals = $query
            ->orderBy($sortable[$sort], $direction)
            ->orderBy('id', 'desc')
            ->paginate((int) ($request->input('per_page', 25) ?: 25))
            ->withQueryString();

        // Agents for filter dropdown (admin only).
        $agents = collect();
        if (auth()->user()->isAdmin()) {
            $agents = \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin', 'agent', 'acquisition_agent', 'disposition_agent', 'listing_agent', 'buyers_agent']))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $businessMode = auth()->user()->tenant->business_mode ?? 'wholesale';
        $modeTerms = \App\Services\BusinessModeService::getTerminology(auth()->user()->tenant);
        $feeField = $businessMode === 'realestate' ? 'total_commission' : 'assignment_fee';
        $stageLabels = Deal::stageLabels();

        return view('deals.index', compact(
            'deals', 'agents', 'businessMode', 'modeTerms', 'feeField', 'stageLabels'
        ));
    }

    public function pipeline(Request $request)
    {
        $this->authorize('viewAny', Deal::class);

        $dealType = $request->input('deal_type');
        $dealType = in_array($dealType, ['rent', 'sale'], true) ? $dealType : null;

        $query = Deal::with(['lead.property', 'lease', 'agent']);

        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
        }

        // Deal type filter: rent (leasing) / sale / all.
        // Legacy deals have a NULL type and are treated as sales.
        if ($dealType) {
            $query->where($dealType === 'rent'
                ? fn ($q) => $q->where('deal_type', 'rent')
                : fn ($q) => $q->where('deal_type', 'sale')->orWhereNull('deal_type'));
        }

        // Filters
        if ($request->filled('agent')) {
            $query->where('agent_id', $request->agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('lead', function ($lq) use ($search) {
                        $lq->where(function ($inner) use ($search) {
                            $inner->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    })
                    ->orWhereHas('lead.property', function ($pq) use ($search) {
                        $pq->where('address', 'like', "%{$search}%");
                    });
            });
        }

        // Lead "tags" — temperature / source filters so the board focuses on
        // the leads worth chasing instead of everything.
        if ($request->filled('temp') && in_array($request->temp, ['hot', 'warm', 'cold'], true)) {
            $query->whereHas('lead', fn ($q) => $q->where('temperature', $request->temp));
        }

        if ($request->filled('source')) {
            $query->whereHas('lead', fn ($q) => $q->where('lead_source', $request->source));
        }

        $deals = $query->get();

        // Pipeline for the active tab only — never the merged "everything" board.
        $stages = $dealType
            ? Deal::stagesForType($dealType)
            : (Deal::leasingStages() + Deal::saleStages());

        $stageLabels = array_map(fn ($t) => __($t), $stages);

        $stageProbs = [];
        foreach ($stages as $key => $label) {
            $stageProbs[$key] = Deal::stageProbability($key);
        }

        // Agents for filter dropdown (admin only)
        $agents = collect();
        if (auth()->user()->isAdmin()) {
            $agents = \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin', 'agent', 'acquisition_agent', 'disposition_agent', 'listing_agent', 'buyers_agent']))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $countQuery = Deal::where('tenant_id', auth()->user()->tenant_id);
        if (auth()->user()->isAgent()) {
            $countQuery->where('agent_id', auth()->id());
        }
        $counts = [
            'all' => (clone $countQuery)->count(),
            'rent' => (clone $countQuery)->where('deal_type', 'rent')->count(),
            'sale' => (clone $countQuery)->where(fn ($q) => $q->where('deal_type', 'sale')->orWhereNull('deal_type'))->count(),
        ];

        $businessMode = auth()->user()->tenant->business_mode ?? 'wholesale';
        $modeTerms = \App\Services\BusinessModeService::getTerminology(auth()->user()->tenant);
        $feeField = $businessMode === 'realestate' ? 'total_commission' : 'assignment_fee';

        // Per-column aggregates (count, Σ value, Σ fees, weighted forecast).
        $grouped = $deals->groupBy('stage');
        $columns = [];
        foreach ($stages as $key => $label) {
            $stageDeals = $grouped[$key] ?? collect();
            $columns[$key] = [
                'label' => $stageLabels[$key],
                'deals' => $stageDeals,
                'count' => $stageDeals->count(),
                'value' => round((float) $stageDeals->sum('contract_price'), 2),
                'fees' => round((float) $stageDeals->sum($feeField), 2),
                'forecast' => round($stageDeals->sum(fn ($d) => (float) $d->contract_price * Deal::stageProbability($key)), 2),
                'stale' => $stageDeals->filter(fn ($d) => $d->stage_changed_at && (int) now()->diffInDays($d->stage_changed_at, true) > 5)->count(),
            ];
        }

        // Summary strip — answers "business generated" vs "upcoming business".
        $openDeals = $deals->filter(fn ($d) => ! in_array($d->stage, ['closed_won', 'closed_lost'], true));
        $wonDeals = $deals->filter(fn ($d) => $d->stage === 'closed_won');
        $lostDeals = $deals->filter(fn ($d) => $d->stage === 'closed_lost');
        $decided = $wonDeals->count() + $lostDeals->count();

        $wonThisMonth = $wonDeals->filter(fn ($d) => $d->stage_changed_at && $d->stage_changed_at->gte(now()->startOfMonth()));

        $summary = [
            'open_count' => $openDeals->count(),
            'pipeline_value' => round($openDeals->sum('contract_price'), 2),
            'forecast' => round($openDeals->sum(fn ($d) => (float) $d->contract_price * Deal::stageProbability($d->stage)), 2),
            'generated' => round($wonThisMonth->sum($feeField), 2),
            'generated_alltime' => round($wonDeals->sum($feeField), 2),
            'generated_value_month' => round($wonThisMonth->sum('contract_price'), 2),
            'won_count' => $wonDeals->count(),
            'won_count_month' => $wonThisMonth->count(),
            'win_rate' => $decided > 0 ? round($wonDeals->count() / $decided * 100) : null,
            'avg_deal' => $wonDeals->count() > 0 ? round($wonDeals->sum('contract_price') / $wonDeals->count(), 2) : 0,
        ];

        return view('deals.pipeline', compact(
            'deals', 'stages', 'stageLabels', 'agents', 'dealType', 'counts',
            'businessMode', 'modeTerms', 'feeField', 'columns', 'summary', 'stageProbs'
        ));
    }

    public function updateStage(Request $request, Deal $deal)
    {
        $this->authorize('changeStage', $deal);

        $request->validate([
            'stage' => 'required|in:'.implode(',', array_keys(Deal::stagesForType($deal->dealType()))),
        ]);

        $oldStage = $deal->stage;

        // Real estate mode: a signed offer letter must exist before a deal can
        // be closed as Won.
        if ($request->stage === 'closed_won' && $oldStage !== 'closed_won') {
            $deal->loadMissing('lead');
            if ($gateError = app(\App\Services\TransactionCloseService::class)->gateError($deal->lead)) {
                return response()->json(['success' => false, 'message' => $gateError], 422);
            }
        }

        $updateData = [
            'stage' => $request->stage,
            'stage_changed_at' => now(),
        ];

        $deal->update($updateData);

        Activity::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $deal->lead_id,
            'agent_id' => auth()->id(),
            'type' => 'stage_change',
            'subject' => 'Deal stage changed',
            'body' => 'Stage changed from "'.Deal::stageLabel($oldStage).'" to "'.Deal::stageLabel($request->stage).'"',
            'logged_at' => now(),
        ]);

        event(new DealStageChanged($deal, $oldStage));
        AuditLog::log('deal.stage_changed', $deal, ['stage' => $oldStage], ['stage' => $request->stage]);
        Hooks::doAction('deal.stage_changed', $deal, $oldStage);

        // Convert the deal's lead into a Client (Buyer) when the deal is won,
        // sync the lead status, and apply the standard commission + split.
        if ($request->stage === 'closed_won' && $oldStage !== 'closed_won') {
            app(\App\Services\LeadToClientService::class)->convertFromWonDeal($deal);
            $deal->loadMissing('lead');
            if ($deal->lead) {
                app(\App\Services\TransactionCloseService::class)->closeAsWon($deal->lead, $deal, auth()->user());
            }
        }

        \App\Services\WebhookService::dispatch('deal.stage_changed', [
            'deal_id' => $deal->id,
            'title' => $deal->title,
            'old_stage' => $oldStage,
            'new_stage' => $request->stage,
            'agent_id' => $deal->agent_id,
        ], auth()->user()->tenant_id);

        // Notify deal agent of stage change
        $tenant = auth()->user()->tenant;
        if ($tenant->wantsNotification('deal_stage_changed') && $deal->agent_id) {
            $deal->load('lead');
            $deal->agent->notify(new DealStageChangedNotification($deal, $oldStage, $tenant));
        }

        // Dispatch buyer matching when deal moves to the mode-appropriate trigger stage
        $matchTrigger = \App\Services\BusinessModeService::getBuyerMatchTriggerStage();
        if ($request->stage === $matchTrigger) {
            app(\App\Services\BuyerMatchService::class)->matchForDeal($deal);

            // Notify admins and relevant agents if buyer matches found
            if ($tenant->wantsNotification('buyer_matched')) {
                $deal->load('buyerMatches');
                $matches = $deal->buyerMatches;
                if ($matches->count() > 0) {
                    $topScore = $matches->max('score') ?? 0;
                    $adminRoleIds = Role::whereIn('name', ['owner', 'admin'])->pluck('id')->all();

                    $isRE = \App\Services\BusinessModeService::isRealEstate($tenant);
                    $notifyRoles = $isRE
                        ? ['listing_agent', 'buyers_agent']
                        : ['disposition_agent'];
                    $extraRoleIds = Role::whereIn('name', $notifyRoles)->pluck('id')->all();

                    $recipients = User::where('tenant_id', $tenant->id)
                        ->whereIn('role_id', array_merge($adminRoleIds, $extraRoleIds))
                        ->where('is_active', true)
                        ->get();
                    if ($recipients->isNotEmpty()) {
                        Notification::send($recipients, new BuyerMatchFound($deal, $matches->count(), $topScore, $tenant));
                    }
                }
            }
        }

        // Decrease buyer reliability if deal reverts back to match trigger stage (buyer backed out)
        if ($oldStage !== $matchTrigger && $request->stage === $matchTrigger) {
            $assignedMatch = $deal->buyerMatches()->where('status', 'interested')->first();
            if ($assignedMatch && $assignedMatch->buyer) {
                $assignedMatch->update(['status' => 'passed']);
                BuyerScoreService::recalculate($assignedMatch->buyer);
            }
        }

        // Auto-create transaction checklist when entering under_contract in realestate mode
        if ($request->stage === 'under_contract' && \App\Services\BusinessModeService::isRealEstate()) {
            if ($deal->checklistItems()->count() === 0) {
                foreach (TransactionChecklist::DEFAULT_ITEMS as $item) {
                    TransactionChecklist::create([
                        'tenant_id' => auth()->user()->tenant_id,
                        'deal_id' => $deal->id,
                        ...$item,
                    ]);
                }
            }
        }

        return response()->json(['success' => true]);
    }

    public function show(Deal $deal)
    {
        $this->authorize('view', $deal);
        $deal->load(['lead.property', 'agent', 'documents', 'buyerMatches.buyer', 'activities.agent']);

        if (\App\Services\BusinessModeService::isRealEstate()) {
            $deal->load(['offers', 'checklistItems', 'offerLetters.discountApprover', 'offerLetters.approver']);
        }

        if (request()->ajax()) {
            return response()->json($deal->append(['is_leasing', 'due_diligence_applies']));
        }

        return view('deals.show', compact('deal'));
    }

    public function update(DealRequest $request, Deal $deal)
    {
        $this->authorize('update', $deal);

        $deal->update($request->validated());

        return response()->json(['success' => true, 'deal' => $deal->fresh()->append(['is_leasing', 'due_diligence_applies'])]);
    }

    public function uploadDocument(Request $request, Deal $deal)
    {
        $this->authorize('uploadDocument', $deal);

        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('document');
        $path = $file->store("deals/{$deal->id}", 'local');

        DealDocument::create([
            'tenant_id' => $deal->tenant_id,
            'deal_id' => $deal->id,
            'filename' => basename($path),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
        ]);

        return redirect()->back()->with('success', 'Document uploaded successfully.');
    }

    public function downloadDocument(DealDocument $document)
    {
        $deal = Deal::findOrFail($document->deal_id);
        $this->authorize('view', $deal);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function notifyBuyer(Deal $deal, \App\Models\DealBuyerMatch $match)
    {
        $this->authorize('notifyBuyer', $deal);

        $match->update(['notified_at' => now()]);

        event(new \App\Events\BuyerNotified($match->buyer, $deal));
        Hooks::doAction('buyer.notified', $match->buyer, $deal);

        return redirect()->back()->with('success', 'Buyer notified successfully.');
    }

    public function export(Request $request)
    {
        $this->authorize('export', Deal::class);

        $query = Deal::with(['lead', 'agent']);

        if (auth()->user()->isAgent()) {
            $query->where('agent_id', auth()->id());
        }

        $dealType = $request->input('deal_type');
        if ($dealType === 'rent') {
            $query->where('deal_type', 'rent');
        } elseif ($dealType === 'sale') {
            $query->where(fn ($q) => $q->where('deal_type', 'sale')->orWhereNull('deal_type'));
        }

        if ($request->filled('agent')) {
            $query->where('agent_id', $request->agent);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('lead', function ($lq) use ($search) {
                        $lq->where(function ($inner) use ($search) {
                            $inner->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    });
            });
        }

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            $terms = \App\Services\BusinessModeService::getTerminology();
            $feeColumn = \App\Services\BusinessModeService::getDashboardKpiConfig()['fee_column'];
            fputcsv($handle, [
                __('Type'), __('Title'), __('Lead Name'), __('Stage'), __('Contract Price'),
                $terms['money_label'], __('Agent'), __('Days in Stage'), __('Created Date'),
            ]);
            foreach ($query->with(['lead', 'agent'])->latest()->cursor() as $deal) {
                $daysInStage = $deal->stage_changed_at
                    ? (int) now()->diffInDays($deal->stage_changed_at, true)
                    : '';
                fputcsv($handle, [
                    $deal->dealType() === 'rent' ? __('Leasing') : __('Sales'),
                    $deal->title,
                    $deal->lead ? $deal->lead->first_name.' '.$deal->lead->last_name : '',
                    Deal::stageLabel($deal->stage),
                    $deal->contract_price,
                    $deal->{$feeColumn},
                    $deal->agent->name ?? '',
                    $daysInStage,
                    $deal->created_at?->format('Y-m-d'),
                ]);
            }
            fclose($handle);
        }, 'deals-export-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ── Transaction Checklist ─────────────────────────────

    public function storeChecklist(Deal $deal)
    {
        $this->authorize('update', $deal);

        if ($deal->checklistItems()->count() > 0) {
            return response()->json(['message' => __('Checklist already exists.')], 422);
        }

        foreach (TransactionChecklist::DEFAULT_ITEMS as $item) {
            TransactionChecklist::create([
                'tenant_id' => auth()->user()->tenant_id,
                'deal_id' => $deal->id,
                ...$item,
            ]);
        }

        return redirect()->back()->with('success', __('Transaction checklist created.'));
    }

    public function updateChecklistItem(Request $request, TransactionChecklist $item)
    {
        $deal = Deal::findOrFail($item->deal_id);
        $this->authorize('update', $deal);

        $request->validate([
            'status' => 'nullable|in:'.implode(',', array_keys(TransactionChecklist::STATUSES)),
            'deadline' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $data = $request->only(['status', 'deadline', 'notes']);
        if (($data['status'] ?? null) === 'completed' && ! $item->completed_at) {
            $data['completed_at'] = now();
        }
        if (($data['status'] ?? null) !== 'completed') {
            $data['completed_at'] = null;
        }

        $item->update($data);

        return response()->json(['success' => true, 'item' => $item->fresh()]);
    }

    public function addChecklistItem(Request $request, Deal $deal)
    {
        $this->authorize('update', $deal);

        $request->validate([
            'label' => 'required|string|max:255',
            'deadline' => 'nullable|date',
        ]);

        $maxOrder = $deal->checklistItems()->max('sort_order') ?? 0;

        $item = TransactionChecklist::create([
            'tenant_id' => auth()->user()->tenant_id,
            'deal_id' => $deal->id,
            'item_key' => 'custom_'.time(),
            'label' => $request->label,
            'deadline' => $request->deadline,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json(['success' => true, 'item' => $item]);
    }

    public function removeChecklistItem(TransactionChecklist $item)
    {
        $deal = Deal::findOrFail($item->deal_id);
        $this->authorize('update', $deal);

        $item->delete();

        return response()->json(['success' => true]);
    }

    // ── Offer Management ──────────────────────────────────

    public function storeOffer(Request $request, Deal $deal)
    {
        $this->authorize('update', $deal);

        $request->validate([
            'buyer_name' => 'required|string|max:255',
            'buyer_agent_name' => 'nullable|string|max:255',
            'buyer_agent_phone' => 'nullable|string|max:50',
            'buyer_agent_email' => 'nullable|email|max:255',
            'offer_price' => 'required|numeric|min:0',
            'earnest_money' => 'nullable|numeric|min:0',
            'financing_type' => 'nullable|in:'.implode(',', array_keys(DealOffer::FINANCING_TYPES)),
            'contingencies' => 'nullable|array',
            'contingencies.*' => 'string',
            'expiration_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $offer = DealOffer::create([
            'tenant_id' => auth()->user()->tenant_id,
            'deal_id' => $deal->id,
            ...$request->only([
                'buyer_name', 'buyer_agent_name', 'buyer_agent_phone', 'buyer_agent_email',
                'offer_price', 'earnest_money', 'financing_type', 'contingencies',
                'expiration_date', 'notes',
            ]),
        ]);

        Activity::create([
            'tenant_id' => auth()->user()->tenant_id,
            'lead_id' => $deal->lead_id,
            'deal_id' => $deal->id,
            'agent_id' => auth()->id(),
            'type' => 'note',
            'subject' => __('Offer received'),
            'body' => __(':buyer offered :price', [
                'buyer' => $request->buyer_name,
                'price' => '$'.number_format($request->offer_price, 2),
            ]),
            'logged_at' => now(),
        ]);

        return redirect()->back()->with('success', __('Offer recorded successfully.'));
    }

    public function updateOffer(Request $request, DealOffer $offer)
    {
        $deal = Deal::findOrFail($offer->deal_id);
        $this->authorize('update', $deal);

        $request->validate([
            'status' => 'nullable|in:'.implode(',', array_keys(DealOffer::STATUSES)),
            'counter_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $oldStatus = $offer->status;
        $offer->update($request->only(['status', 'counter_price', 'notes']));

        if ($oldStatus !== $offer->status) {
            Activity::create([
                'tenant_id' => auth()->user()->tenant_id,
                'lead_id' => $deal->lead_id,
                'deal_id' => $deal->id,
                'agent_id' => auth()->id(),
                'type' => 'note',
                'subject' => __('Offer status changed'),
                'body' => __('Offer from :buyer changed from :old to :new', [
                    'buyer' => $offer->buyer_name,
                    'old' => __(DealOffer::STATUSES[$oldStatus] ?? $oldStatus),
                    'new' => __(DealOffer::STATUSES[$offer->status] ?? $offer->status),
                ]),
                'logged_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'offer' => $offer->fresh()]);
    }

    public function destroyOffer(DealOffer $offer)
    {
        $deal = Deal::findOrFail($offer->deal_id);
        $this->authorize('update', $deal);

        $offer->delete();

        return response()->json(['success' => true]);
    }
}
