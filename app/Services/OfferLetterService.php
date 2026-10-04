<?php

namespace App\Services;

use App\Events\DealStageChanged;
use App\Facades\Hooks;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\OfferLetter;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransactionChecklist;
use App\Models\User;
use App\Notifications\DealStageChanged as DealStageChangedNotification;
use App\Notifications\OfferLetterApprovalRequired;
use App\Notifications\OfferLetterApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Prepares, validates and renders brokerage offer letters.
 *
 * An offer letter is generated once a client likes a unit and the
 * PM / landlord finalises the price. It documents the final value (with any
 * manager-approved discount), the tenant's fees and the agency commission, and
 * is printable for the client to sign. A signed, uploaded copy is the
 * prerequisite for closing the transaction as Won.
 *
 * The offering party is the tenant itself (the brokerage / property management
 * company), so the tenant record's contact fields drive the letter header.
 */
class OfferLetterService
{
    public const OFFER_PREFIX = 'OL';

    /**
     * Default amounts/fees for an offer drafted against a deal, before the user
     * edits them.
     */
    public function buildDefaults(Deal $deal): array
    {
        $deal->loadMissing(['lead', 'lead.property', 'unit', 'tenant']);

        $commission = app(DealCommissionService::class);
        // The advertised price, not the negotiated one — the letter discounts it.
        $listed = $commission->listedPriceFor($deal);
        $gross = $commission->grossFor($deal);
        $rate = $commission->rateFor($deal);
        $tenants = $commission->rates($deal->tenant);
        // The offered unit wins: the client regularly asks for an offer on a unit that
        // is not the one linked on the lead.
        $property = $deal->dealUnit();
        $lead = $deal->lead;

        $start = $lead?->expected_move_in_date;
        $end = $start ? $start->copy()->addYear()->subDay() : null;

        $securityDeposit = $property?->deposit_amount;

        if ($deal->dealType() === 'rent' && ! $securityDeposit && $gross > 0) {
            $securityDeposit = round($gross * 0.05, 2);
        }

        $adminFee = (float) ($property?->admin_fee ?? 0);
        $contractFee = (float) ($property?->contract_fee ?? 0);

        return [
            'deal_type' => $deal->dealType(),
            // Unit price as listed.
            'original_amount' => $listed,
            // Contract value: the listed price, less whatever discount is typed in.
            'approved_amount' => $listed,
            'commission_basis' => 'percentage',
            'commission_rate_pct' => $rate,
            'commission_vat_pct' => $deal->tenant->effectiveVatRate(),
            'commission_amount' => $commission->commissionFor($deal, $listed),
            'commission_vat' => $deal->tenant->vatOn($commission->commissionFor($deal, $listed)),
            'commission_total' => round($commission->commissionFor($deal, $listed) + $deal->tenant->vatOn($commission->commissionFor($deal, $listed)), 2),
            'security_deposit' => $securityDeposit ?: null,
            'admin_fee' => $adminFee ?: null,
            'admin_fee_vat' => $adminFee ? $deal->tenant->vatOn($adminFee) : null,
            'admin_fee_total' => $adminFee ? round($adminFee + $deal->tenant->vatOn($adminFee), 2) : null,
            'contract_fee' => $contractFee ?: null,
            'contract_fee_vat' => $contractFee ? $deal->tenant->vatOn($contractFee) : null,
            'contract_fee_total' => $contractFee ? round($contractFee + $deal->tenant->vatOn($contractFee), 2) : null,
            'payment_period' => '1 '.__('Payment'), // "1 Payment Payable to the Landlord"
            'contract_start_date' => $start,
            'contract_end_date' => $end,
            'documents_required' => 'Passport, Residency Visa & Emirates ID Copy',
            'valid_until' => now()->addDay()->startOfDay(),
            'offer_no' => $this->nextOfferNo($deal->tenant),
        ];
    }

    /**
     * Create an offer letter from validated input.
     *
     * Every offer letter requires manager/admin approval before it can be
     * printed or signed. A creator who is an admin (or a manager) approves it
     * themselves at creation time; a regular agent's letter is created as
     * 'pending_approval' until their manager (or, failing that, a tenant
     * admin) approves it — and that manager is notified to do so.
     */
    public function createFromValidated(Deal $deal, User $user, array $v): OfferLetter
    {
        $tenant = $deal->tenant;
        $amounts = $this->buildAmounts($deal, $v);

        $status = 'pending_approval';
        $approvedBy = null;
        $approvedAt = null;

        if ($this->canApproveOffer($user)) {
            $status = 'issued';
            $approvedBy = $user->id;
            $approvedAt = now();
        }

        $offer = DB::transaction(function () use ($deal, $tenant, $user, $v, $amounts, $status, $approvedBy, $approvedAt) {
            $offer = OfferLetter::create(array_merge([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'lead_id' => $deal->lead_id,
                'offer_no' => (string) ($v['offer_no'] ?? '') ?: $this->nextOfferNo($tenant),
                'status' => $status,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'issued_at' => now(),
                'valid_until' => $v['valid_until'] ?? now()->addDay()->startOfDay(),
                'contract_start_date' => $v['contract_start_date'] ?? null,
                'contract_end_date' => $v['contract_end_date'] ?? null,
                'payment_period' => $v['payment_period'] ?? null,
                'documents_required' => $v['documents_required'] ?? null,
                'notes' => $v['notes'] ?? null,
            ], $amounts, [
                'discount_approved_by' => $approvedBy,
                'discount_approved_at' => $approvedAt,
            ]));

            Activity::create([
                'tenant_id' => $tenant->id,
                'lead_id' => $deal->lead_id,
                'deal_id' => $deal->id,
                'agent_id' => $user->id,
                'type' => 'note',
                'subject' => $status === 'issued' ? __('Offer letter issued') : __('Offer letter awaiting approval'),
                'body' => __('Offer letter :no created for :amount (:status).', [
                    'no' => $offer->offer_no,
                    'amount' => \App\Helpers\TenantFormatHelper::currency((float) $amounts['approved_amount']),
                    'status' => __(OfferLetter::STATUSES[$status] ?? $status),
                ]),
                'logged_at' => now(),
            ]);

            return $offer;
        });

        if ($offer->status === 'pending_approval') {
            $this->notifyApprovers($offer, $user, $tenant);
        }

        return $offer;
    }

    /**
     * Same shape as buildDefaults() but sourced from an existing letter, so the
     * edit form is rendered by one shared partial.
     */
    public function editDefaults(OfferLetter $offer): array
    {
        $offer->loadMissing(['deal.lead', 'deal.tenant', 'tenant']);

        return [
            'deal_type' => $offer->deal?->dealType(),
            'offer_no' => $offer->offer_no,
            'valid_until' => $offer->valid_until,
            'contract_start_date' => $offer->contract_start_date,
            'contract_end_date' => $offer->contract_end_date,
            'original_amount' => (float) $offer->original_amount,
            'discount_amount' => (float) $offer->discount_amount,
            'approved_amount' => (float) $offer->approved_amount,
            'commission_basis' => $offer->commission_basis ?: 'percentage',
            'commission_rate_pct' => (float) $offer->commission_rate_pct,
            'commission_vat_pct' => (float) $offer->commission_vat_pct,
            'commission_amount' => (float) $offer->commission_amount,
            'commission_vat' => (float) $offer->commission_vat,
            'commission_total' => (float) $offer->commission_total,
            'security_deposit' => $offer->security_deposit,
            'admin_fee' => $offer->admin_fee,
            'admin_fee_vat' => $offer->admin_fee_vat,
            'admin_fee_total' => $offer->admin_fee_total,
            'contract_fee' => $offer->contract_fee,
            'contract_fee_vat' => $offer->contract_fee_vat,
            'contract_fee_total' => $offer->contract_fee_total,
            'payment_period' => $offer->payment_period,
            'documents_required' => $offer->documents_required,
            'notes' => $offer->notes,
        ];
    }

    /**
     * Recalculate the money columns from the submitted figures. Shared by
     * create and edit so an edited letter can never be priced differently from
     * the way a new one is.
     */
    protected function buildAmounts(Deal $deal, array $v): array
    {
        $tenant = $deal->tenant;
        $commission = app(DealCommissionService::class);

        // Unit price as listed, then the discount off it, then the contract value
        // the client actually signs for. The discount is what makes the three
        // figures worth printing separately.
        $listed = (float) $v['original_amount'];
        $discount = min(max(0.0, (float) ($v['discount_amount'] ?? 0)), $listed);
        $contractValue = max(0.0, round($listed - $discount, 2));

        $basis = ($v['commission_basis'] ?? 'percentage') === 'value' ? 'value' : 'percentage';
        $rate = (float) ($v['commission_rate_pct'] ?? $commission->rateFor($deal));

        // A stated commission value is taken as given; a percentage is taken off
        // the contract value, falling back to the listed price when a discount
        // has consumed the whole contract value.
        if ($basis === 'value') {
            $commissionNet = max(0.0, round((float) ($v['commission_amount'] ?? 0), 2));
        } else {
            $commissionNet = round(($contractValue > 0 ? $contractValue : $listed) * ($rate / 100), 2);
        }

        // VAT belongs to the tenant, not to the form, and it attaches only to our
        // services. The residential lease or sale value is never VATable, so it
        // deliberately has no VAT line above it.
        $vatPct = $tenant->effectiveVatRate();
        $commissionVat = $tenant->vatOn($commissionNet);

        $adminFee = max(0.0, (float) ($v['admin_fee'] ?? 0));
        $contractFee = max(0.0, (float) ($v['contract_fee'] ?? 0));
        $adminFeeVat = $tenant->vatOn($adminFee);
        $contractFeeVat = $tenant->vatOn($contractFee);

        return [
            'original_amount' => $listed,
            'discount_amount' => $discount,
            'approved_amount' => $contractValue,
            'commission_basis' => $basis,
            'commission_rate_pct' => $rate,
            'commission_vat_pct' => $vatPct,
            'commission_amount' => $commissionNet,
            'commission_vat' => $commissionVat,
            'commission_total' => round($commissionNet + $commissionVat, 2),
            'security_deposit' => $v['security_deposit'] ?? null,
            'admin_fee' => $adminFee ?: null,
            'admin_fee_vat' => $adminFee ? $adminFeeVat : null,
            'admin_fee_total' => $adminFee ? round($adminFee + $adminFeeVat, 2) : null,
            'contract_fee' => $contractFee ?: null,
            'contract_fee_vat' => $contractFee ? $contractFeeVat : null,
            'contract_fee_total' => $contractFee ? round($contractFee + $contractFeeVat, 2) : null,
        ];
    }

    protected function notifyApprovers(OfferLetter $offer, User $user, Tenant $tenant): void
    {
        if (! $tenant->wantsNotification('offer_letter_approval')) {
            return;
        }

        $approvers = $this->approversFor($user);

        if ($approvers->isNotEmpty()) {
            Notification::send($approvers, new OfferLetterApprovalRequired($offer, $user, $tenant));
        }
    }

    /**
     * Edit an unapproved letter. Because only 'draft' / 'pending_approval' are
     * editable there is no prior approval to invalidate, but the approval
     * columns are cleared anyway so a stale approver can never appear on a
     * figure they did not sign off.
     */
    public function updateFromValidated(OfferLetter $offer, User $user, array $v): OfferLetter
    {
        if (! $offer->isEditable()) {
            throw new \RuntimeException(__('An approved offer letter can no longer be edited. Withdraw it and raise a new one.'));
        }

        $deal = $offer->deal;
        $tenant = $offer->tenant;
        $amounts = $this->buildAmounts($deal, $v);

        $canApprove = $this->canApproveOffer($user);
        $approvedBy = $canApprove ? $user->id : null;
        $approvedAt = $canApprove ? now() : null;

        $offer->fill(array_merge([
            'offer_no' => (string) ($v['offer_no'] ?? '') ?: $offer->offer_no,
            'valid_until' => $v['valid_until'] ?? $offer->valid_until,
            'contract_start_date' => $v['contract_start_date'] ?? null,
            'contract_end_date' => $v['contract_end_date'] ?? null,
            'payment_period' => $v['payment_period'] ?? null,
            'documents_required' => $v['documents_required'] ?? null,
            'notes' => $v['notes'] ?? null,
            'status' => $canApprove ? 'issued' : 'pending_approval',
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
        ], $amounts, [
            'discount_approved_by' => $amounts['discount_amount'] > 0 ? $approvedBy : null,
            'discount_approved_at' => $amounts['discount_amount'] > 0 ? $approvedAt : null,
        ]))->save();

        Activity::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $offer->lead_id,
            'deal_id' => $offer->deal_id,
            'agent_id' => $user->id,
            'type' => 'note',
            'subject' => __('Offer letter updated'),
            'body' => __('Offer letter :no updated — :amount (:status).', [
                'no' => $offer->offer_no,
                'amount' => \App\Helpers\TenantFormatHelper::currency((float) $amounts['approved_amount']),
                'status' => __(OfferLetter::STATUSES[$offer->status] ?? $offer->status),
            ]),
            'logged_at' => now(),
        ]);

        if ($offer->status === 'pending_approval') {
            $this->notifyApprovers($offer, $user, $tenant);
        }

        return $offer->refresh();
    }

    /**
     * Pull a letter back before the client signed. Terminal, and deliberately
     * not the same thing as delete: an issued letter may already have reached
     * the client, so the record stays for the audit trail.
     */
    public function withdraw(OfferLetter $offer, User $user): OfferLetter
    {
        if (! $offer->canWithdraw()) {
            throw new \RuntimeException(__('This offer letter can no longer be withdrawn.'));
        }

        $wasPending = $offer->status === 'pending_approval';

        $offer->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
        ]);

        Activity::create([
            'tenant_id' => $offer->tenant_id,
            'lead_id' => $offer->lead_id,
            'deal_id' => $offer->deal_id,
            'agent_id' => $user->id,
            'type' => 'note',
            'subject' => __('Offer letter withdrawn'),
            'body' => __('Offer letter :no was withdrawn.', ['no' => $offer->offer_no]),
            'logged_at' => now(),
        ]);

        if ($wasPending) {
            $this->notifyApprovers($offer, $user, $offer->tenant);
        }

        return $offer->refresh();
    }

    /**
     * Hard delete, only while the letter is unapproved and therefore cannot
     * have been issued to anybody. Anything past that must be withdrawn.
     */
    public function destroy(OfferLetter $offer, User $user): void
    {
        if (! $offer->canDelete()) {
            throw new \RuntimeException(__('An issued offer letter cannot be deleted — withdraw it instead.'));
        }

        $tenant = $offer->tenant;

        Activity::create([
            'tenant_id' => $tenant->id,
            'lead_id' => $offer->lead_id,
            'deal_id' => $offer->deal_id,
            'agent_id' => $user->id,
            'type' => 'note',
            'subject' => __('Offer letter deleted'),
            'body' => __('Offer letter :no was deleted.', ['no' => $offer->offer_no]),
            'logged_at' => now(),
        ]);

        $offer->delete();
    }

    /**
     * Everyone who may approve this user's offer letters: their manager chain
     * (closest first, active members only) or, when there is no manager, the
     * tenant's admins/owners.
     */
    public function approversFor(User $user): \Illuminate\Support\Collection
    {
        $managers = collect($user->managerChain())
            ->filter(fn (User $m) => $m->is_active !== false)
            ->values();

        if ($managers->isNotEmpty()) {
            return $managers;
        }

        $roleIds = Role::whereIn('name', ['owner', 'admin'])->pluck('id')->all();

        return User::where('tenant_id', $user->tenant_id)
            ->whereIn('role_id', $roleIds)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Approve a pending offer (manager/admin only): it becomes issued and can
     * be printed and signed. The deal's agent is notified that approval landed.
     */
    public function approve(OfferLetter $offer, User $approver): OfferLetter
    {
        if ($offer->status !== 'pending_approval') {
            throw new \RuntimeException(__('This offer letter is not waiting for approval.'));
        }

        if (! $this->canApproveOffer($approver)) {
            throw new \RuntimeException(__('Only an admin or manager can approve an offer letter.'));
        }

        $offer->update([
            'status' => 'issued',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'discount_approved_by' => $offer->hasDiscount() ? $approver->id : $offer->discount_approved_by,
            'discount_approved_at' => $offer->hasDiscount() ? now() : $offer->discount_approved_at,
        ]);

        $deal = $offer->deal;
        $tenant = $offer->tenant;

        if ($deal?->agent && $tenant->wantsNotification('offer_letter_approved')) {
            Notification::send($deal->agent, new OfferLetterApproved($offer, $approver, $tenant));
        }

        return $offer->refresh();
    }

    /**
     * Record that the client signed the letter. The signed copy must have been
     * uploaded and the letter must be approved (issued) before it can be signed.
     *
     * Signing also auto-advances the deal through the pipeline (rent ->
     * deposit/commission, sale -> under contract) — forward only.
     */
    public function markSigned(OfferLetter $offer, ?string $signedPdfPath = null, ?User $user = null): OfferLetter
    {
        if (! $offer->isApproved()) {
            throw new \RuntimeException(__('The offer letter must be approved before it can be signed.'));
        }

        // Re-uploading over an already-signed letter only replaces a bad scan:
        // no second signature event and no second stage move.
        if ($offer->isSigned() && $signedPdfPath) {
            $offer->update(['signed_pdf_path' => $signedPdfPath]);

            if ($user) {
                Activity::create([
                    'tenant_id' => $offer->tenant_id,
                    'lead_id' => $offer->lead_id,
                    'deal_id' => $offer->deal_id,
                    'agent_id' => $user->id,
                    'type' => 'note',
                    'subject' => __('Signed offer copy replaced'),
                    'body' => __('The signed copy of offer letter :no was replaced.', ['no' => $offer->offer_no]),
                    'logged_at' => now(),
                ]);
            }

            return $offer->refresh();
        }

        $offer->update([
            'status' => 'signed',
            'signed_at' => now(),
            'signed_pdf_path' => $signedPdfPath ?: $offer->signed_pdf_path,
        ]);

        if ($user && $offer->deal) {
            Activity::create([
                'tenant_id' => $offer->tenant_id,
                'lead_id' => $offer->deal->lead_id,
                'deal_id' => $offer->deal_id,
                'agent_id' => $user->id,
                'type' => 'note',
                'subject' => __('Offer letter signed'),
                'body' => __('Offer letter :no was signed by the client on :date.', [
                    'no' => $offer->offer_no,
                    'date' => $offer->signed_at->format('M j, Y'),
                ]),
                'logged_at' => now(),
            ]);
        }

        if ($offer->deal) {
            $this->advanceDealAfterSigned($offer, $user);
        }

        return $offer->refresh();
    }

    /**
     * Whether a user may approve an offer letter (admin or manager).
     */
    public function canApproveOffer(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    /**
     * Auto-advance a deal whose offer letter was just signed: rent deals move
     * to 'deposit_received', sale deals to 'under_contract'. Only ever moves
     * forward — a deal already past the target stage (e.g. moved_in) stays put.
     *
     * A signature moves the deal to deposit_received, never to deal_won: the
     * signature is what makes the deal binding, not what makes it revenue.
     */
    protected function advanceDealAfterSigned(OfferLetter $offer, ?User $user = null): void
    {
        $deal = $offer->deal;
        $dealType = $deal->dealType();
        $stages = Deal::stagesForType($dealType);
        $target = $dealType === 'rent' ? 'deposit_received' : 'under_contract';

        if (! array_key_exists($target, $stages)) {
            return;
        }

        $keys = array_keys($stages);
        $currentIndex = array_search($deal->stage, $keys, true);
        $targetIndex = array_search($target, $keys, true);

        if ($currentIndex === false || $targetIndex === false || $currentIndex >= $targetIndex) {
            return;
        }

        $oldStage = $deal->stage;
        $deal->update(['stage' => $target, 'stage_changed_at' => now()]);

        Activity::create([
            'tenant_id' => $deal->tenant_id,
            'lead_id' => $deal->lead_id,
            'deal_id' => $deal->id,
            'agent_id' => $user?->id ?? $deal->agent_id,
            'type' => 'stage_change',
            'subject' => __('Deal stage changed'),
            'body' => __('Stage changed from ":from" to ":to".', [
                'from' => Deal::stageLabel($oldStage),
                'to' => Deal::stageLabel($target),
            ]),
            'logged_at' => now(),
        ]);

        event(new DealStageChanged($deal, $oldStage));
        AuditLog::log('deal.stage_changed', $deal, ['stage' => $oldStage], ['stage' => $target]);
        Hooks::doAction('deal.stage_changed', $deal, $oldStage);

        $tenant = $deal->tenant;
        if ($tenant->wantsNotification('deal_stage_changed') && $deal->agent_id) {
            $deal->load('lead');
            $deal->agent->notify(new DealStageChangedNotification($deal, $oldStage, $tenant));
        }

        if ($target === 'under_contract' && \App\Services\BusinessModeService::isRealEstate($tenant)) {
            if ($deal->checklistItems()->count() === 0) {
                foreach (TransactionChecklist::DEFAULT_ITEMS as $item) {
                    TransactionChecklist::create([
                        'tenant_id' => $deal->tenant_id,
                        'deal_id' => $deal->id,
                        ...$item,
                    ]);
                }
            }
        }
    }

    /**
     * Render the printable offer letter HTML.
     */
    public function render(OfferLetter $offer): string
    {
        $offer->loadMissing(['tenant', 'deal.lead', 'deal.lead.property', 'discountApprover']);
        $deal = $offer->deal;
        $lead = $deal?->lead;
        $property = $deal?->lead?->property;
        $tenant = $offer->tenant;

        return view('offers.letter', compact('offer', 'deal', 'lead', 'property', 'tenant'))->render();
    }

    /**
     * Next offer number for the tenant, e.g. OL-0007.
     */
    public function nextOfferNo(Tenant $tenant): string
    {
        $last = OfferLetter::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->value('offer_no');

        $seq = $last ? ((int) preg_replace('/\D+/', '', (string) $last)) + 1 : 1;

        return self::OFFER_PREFIX.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Amount in English words, e.g. "One Hundred Twelve Thousand".
     */
    public function amountInWords($number, string $currency = ''): string
    {
        $number = round((float) $number, 2);
        $whole = (int) floor($number);
        $decimal = (int) round(($number - $whole) * 100);

        $out = trim($this->convertNumber($whole));

        if ($decimal > 0) {
            $out .= ' and '.$this->convertNumber($decimal);
        }

        if ($currency) {
            $out .= rtrim(rtrim(sprintf(' %s', $currency), '0'), '.');
        }

        return $out;
    }

    protected function convertNumber(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $words = '';
        if ($number < 0) {
            return 'Minus '.$this->convertNumber(abs($number));
        }

        $units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen', ];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number < 20) {
            $words = $units[$number];
        } elseif ($number < 100) {
            $words = trim($tens[intdiv($number, 10)].' '.$units[$number % 10]);
        } elseif ($number < 1000) {
            $words = trim($units[intdiv($number, 100)].' Hundred '.$this->convertNumber($number % 100));
        } elseif ($number < 1000000) {
            $words = trim($this->convertNumber(intdiv($number, 1000)).' Thousand '.$this->convertNumber($number % 1000));
        } elseif ($number < 1000000000) {
            $words = trim($this->convertNumber(intdiv($number, 1000000)).' Million '.$this->convertNumber($number % 1000000));
        } else {
            $words = trim($this->convertNumber(intdiv($number, 1000000000)).' Billion '.$this->convertNumber($number % 1000000000));
        }

        return $words;
    }
}
