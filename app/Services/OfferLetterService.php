<?php

namespace App\Services;

use App\Events\DealStageChanged;
use App\Facades\Hooks;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\OfferLetter;
use App\Models\OfferLetterChangeRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TransactionChecklist;
use App\Models\User;
use App\Notifications\DealStageChanged as DealStageChangedNotification;
use App\Notifications\OfferLetterApprovalRequired;
use App\Notifications\OfferLetterApproved;
use App\Notifications\OfferLetterChangeRequested;
use App\Notifications\OfferLetterChangeReviewed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

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

        // Five per cent of the advertised rent, matching buildAmounts() in
        // percent_5 mode. The inventory deposit is deliberately not the default:
        // it is a stale number that does not follow a discount.
        $securityDeposit = $listed > 0
            ? round($listed * (self::DEPOSIT_RATE / 100), 2)
            : null;

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
            'security_deposit_mode' => 'percent_5',
            // The signatory is the lead's occupant until the letter says otherwise.
            // Emirates ID is deliberately not guessed from a custom field: there is
            // no agreed key for it, so it starts empty rather than half-invented.
            'occupant_name' => $lead?->full_name,
            'emirates_id' => null,
            'admin_fee' => $adminFee ?: null,
            'admin_fee_vat' => $adminFee ? $deal->tenant->vatOn($adminFee) : null,
            'admin_fee_total' => $adminFee ? round($adminFee + $deal->tenant->vatOn($adminFee), 2) : null,
            'contract_fee' => $contractFee ?: null,
            'contract_fee_vat' => $contractFee ? $deal->tenant->vatOn($contractFee) : null,
            'contract_fee_total' => $contractFee ? round($contractFee + $deal->tenant->vatOn($contractFee), 2) : null,
            'payment_period' => '1', // "1 Payment Payable to the Landlord"
            'contract_years' => 1,
            'contract_start_date' => $start,
            'contract_end_date' => $end,
            'documents_required' => 'Passport, Residency Visa & Emirates ID Copy',
            'issued_at' => now()->startOfDay(),
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
        // Re-asserted here, not only in the controller, for the same reason
        // DealLifecycleService re-checks its own gate: this method is the only
        // place an offer letter row is written, so it is the last point where
        // a letter that cannot be produced properly can be refused. Callers that
        // reach it directly (tests, future importers) get the same rule.
        //
        // Runs before applyChosenUnit() so a refusal leaves the deal untouched.
        $deal->loadMissing(['lead', 'tenant']);
        $gate = app(OfferLetterReadiness::class)->gateError($deal);
        abort_if($gate !== null, 422, $gate);

        $this->applyChosenUnit($deal, $v['unit_id'] ?? null);
        $deal->refresh()->loadMissing(['lead', 'lead.property', 'unit', 'tenant']);

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
                // Minted up front so the QR on the very first print resolves.
                'verification_token' => (string) \Illuminate\Support\Str::uuid(),
                'bank_details_source' => in_array($v['bank_details_source'] ?? null, ['upload', 'details', 'none'], true)
                    ? $v['bank_details_source']
                    : 'details',
                'bank_details' => $v['bank_details'] ?? null,
                'status' => $status,
                'approved_by' => $approvedBy,
                'approved_at' => $approvedAt,
                'issued_at' => $this->resolveOfferDate($v['issued_at'] ?? null),
                'valid_until' => $v['valid_until'] ?? now()->addDay()->startOfDay(),
                'payment_period' => $v['payment_period'] ?? null,
                'occupant_name' => $v['occupant_name'] ?? null,
                'emirates_id' => $v['emirates_id'] ?? null,
                ...$this->payableTo($v),
                'documents_required' => $v['documents_required'] ?? null,
                'notes' => $v['notes'] ?? null,
            ], $this->resolveContractDates($v), $amounts, [
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
            'issued_at' => $offer->issued_at,
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
            'contract_years' => (int) ($offer->contract_years ?? 1),
            'occupant_name' => $offer->occupant_name,
            'emirates_id' => $offer->emirates_id,
            'bank_details_source' => $offer->bank_details_source ?: 'details',
            'bank_details' => $offer->bank_details,
            ...$offer->payableToAttributes(),
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
            'security_deposit' => $this->resolveSecurityDeposit($v, $contractValue),
            'security_deposit_mode' => $this->securityDepositMode($v),
            'admin_fee' => $adminFee ?: null,
            'admin_fee_vat' => $adminFee ? $adminFeeVat : null,
            'admin_fee_total' => $adminFee ? round($adminFee + $adminFeeVat, 2) : null,
            'contract_fee' => $contractFee ?: null,
            'contract_fee_vat' => $contractFee ? $contractFeeVat : null,
            'contract_fee_total' => $contractFee ? round($contractFee + $contractFeeVat, 2) : null,
        ];
    }

    /**
     * UAE residential tenancy: the security deposit is five per cent of the rent
     * actually contracted for — the contract value, not the advertised price.
     *
     * Deriving it from the contract value is the whole point: type a discount and
     * the deposit has to move with it, or the letter asks for a deposit that no
     * longer matches the rent the client just agreed to. So in 'percent_5' mode a
     * posted figure is ignored and recomputed, and only 'custom' keeps it.
     */
    public const DEPOSIT_RATE = 5.0;

    /** The conventional split, used when a line is posted without one. */
    public const PAYABLE_DEFAULTS = [
        'rent' => 'landlord',
        'deposit' => 'landlord',
        'commission' => 'broker',
        'admin_fee' => 'broker',
        'contract_fee' => 'broker',
    ];

    /**
     * Who the tenant hands each line to.
     *
     * Any of the five can be flipped. The conventional default is that the
     * landlord takes the rent and the deposit directly while the agency takes
     * its own fees and commission, but a landlord who asks the agency to collect
     * everything — or who takes the Ejari and admin fees themselves and leaves
     * only the commission to be paid over — is the normal case, not the
     * exception. Nothing here is locked to a party.
     *
     * An unrecognised value falls back to the conventional default rather than
     * to null, so a letter can never come out with no party named.
     */
    protected function payableTo(array $v): array
    {
        $out = [];

        foreach (['rent', 'deposit', 'commission', 'admin_fee', 'contract_fee'] as $line) {
            $key = $line.'_payable_to';
            $out[$key] = ($v[$key] ?? self::PAYABLE_DEFAULTS[$line]) === 'broker' ? 'broker' : 'landlord';
        }

        return $out;
    }

    protected function securityDepositMode(array $v): string
    {
        return ($v['security_deposit_mode'] ?? 'percent_5') === 'custom' ? 'custom' : 'percent_5';
    }

    protected function resolveSecurityDeposit(array $v, float $contractValue): ?float
    {
        if ($this->securityDepositMode($v) === 'percent_5') {
            return $contractValue > 0 ? round($contractValue * (self::DEPOSIT_RATE / 100), 2) : null;
        }

        return isset($v['security_deposit']) && (float) $v['security_deposit'] > 0
            ? round((float) $v['security_deposit'], 2)
            : null;
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
     * Edit an offer letter.
     *
     * Three cases, decided by the letter's status and who is asking:
     *
     *  - **Unapproved** ('draft' / 'pending_approval'): open to whoever can
     *    update the deal, which is the long-standing rule.
     *  - **Approved, by a manager/admin**: allowed, and it re-approves on the
     *    spot exactly as creating it does — the same person authorised the new
     *    figures.
     *  - **Approved, by anyone else**: refused, except for the narrow
     *    offer-date correction below. The agent must ask instead
     *    ({@see requestChange()}).
     *
     * A *signed* letter is closed to everyone. The client has already signed
     * those terms; changing them afterwards is not a correction.
     */
    public function updateFromValidated(OfferLetter $offer, User $user, array $v): OfferLetter
    {
        if (! $offer->isEditable()) {
            // A signed letter is closed to everyone, manager included: the
            // client has already signed those exact terms.
            //
            // An unsigned *issued* letter is not closed to an approver. They
            // are the people who are allowed to authorise figures, so letting
            // them edit and re-approve in one step is the point of the
            // requirement — and it is exactly what creating a letter as a
            // manager already does.
            //
            // For anyone else the only door left is the offer-date correction,
            // which is deliberately narrow. Everything else needs
            // requestChange() first.
            $approverMayEditTerms = $offer->isApproved()
                && ! $offer->isSigned()
                && $this->canApproveOffer($user);

            if (! $approverMayEditTerms) {
                return $this->correctDateOnIssuedOffer($offer, $user, $v);
            }
        }

        $this->applyChosenUnit($offer->deal, $v['unit_id'] ?? null);

        $deal = $offer->deal->fresh(['lead', 'lead.property', 'unit', 'tenant']);
        $offer->deal()->associate($deal);
        $tenant = $offer->tenant;
        $amounts = $this->buildAmounts($deal, $v);

        $canApprove = $this->canApproveOffer($user);
        $approvedBy = $canApprove ? $user->id : null;
        $approvedAt = $canApprove ? now() : null;

        // Approving new figures on an already-issued letter invalidates any
        // signing link the client is holding — they were sent the old numbers.
        // Capture the state before this edit decides whether to revoke it.
        $wasIssued = $offer->status === 'issued';

        if ($wasIssued) {
            app(OfferSignatureRequestService::class)->revoke($offer);
        }

        $offer->fill(array_merge([
            'offer_no' => (string) ($v['offer_no'] ?? '') ?: $offer->offer_no,
            'issued_at' => $this->resolveOfferDate($v['issued_at'] ?? null, $offer->issued_at),
            'valid_until' => $v['valid_until'] ?? $offer->valid_until,
            'payment_period' => $v['payment_period'] ?? null,
            'occupant_name' => $v['occupant_name'] ?? null,
            'emirates_id' => $v['emirates_id'] ?? null,
            'bank_details_source' => $v['bank_details_source'] ?? $offer->bank_details_source,
            'bank_details' => $v['bank_details'] ?? null,
            ...$this->payableTo($v),
            'documents_required' => $v['documents_required'] ?? null,
            'notes' => $v['notes'] ?? null,
            'status' => $canApprove ? 'issued' : 'pending_approval',
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
        ], $this->resolveContractDates($v, [
            'contract_years' => $offer->contract_years,
            'contract_start_date' => $offer->contract_start_date,
            'contract_end_date' => $offer->contract_end_date,
        ]), $amounts, [
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

        // Done inside the service, not the controller, so every path that
        // withdraws a letter closes the signing link with it. A withdrawn letter
        // that is still signable through a link already in an inbox is the exact
        // failure this prevents.
        app(OfferSignatureRequestService::class)->revoke($offer);

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
     * Hard delete.
     *
     * For everyone except the Owner this only runs while the letter is
     * unapproved and therefore cannot have been issued to anybody — anything
     * past that must be withdrawn. The Owner may also delete a letter that has
     * already travelled; that deletion is a purge, removing the letter together
     * with the activity, audit trail, change requests and signature files that
     * name it, and logging nothing new. (A signed letter is exactly that case,
     * and is never deletable by anyone below the Owner.)
     */
    public function destroy(OfferLetter $offer, User $user): void
    {
        if (! $offer->canDeleteBy($user)) {
            throw new \RuntimeException(__('An issued offer letter cannot be deleted — withdraw it instead.'));
        }

        $tenant = $offer->tenant;

        if ($user->isOwner() && ! $offer->canDelete()) {
            $this->purgeFootprint($offer, $tenant);
        } else {
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

            AuditLog::log('offer_letter.deleted', $offer);
        }

        $offer->delete();
    }

    /**
     * Remove every artifact of a letter that already reached people: the
     * signature files on disk, any change requests, the activity feed rows
     * that name it, and the audit trail that points at it. Only ever called
     * for an Owner hard-delete.
     */
    protected function purgeFootprint(OfferLetter $offer, Tenant $tenant): void
    {
        foreach (['occupant_signature_path', 'signed_pdf_path'] as $column) {
            $path = $offer->{$column};

            if (! $path) {
                continue;
            }

            // Uploads land on the configured default disk, the signature
            // service writes to public; deleting from both is a no-op on the
            // disk that never held the file.
            Storage::disk('public')->delete($path);
            Storage::disk(config('filesystems.default'))->delete($path);
        }

        $needle = (string) $offer->offer_no;

        if ($needle !== '') {
            Activity::where(function ($q) use ($offer) {
                $q->where('deal_id', $offer->deal_id)
                    ->orWhere('lead_id', $offer->lead_id);
            })->where(function ($q) use ($needle) {
                $q->where('subject', 'like', '%'.$needle.'%')
                    ->orWhere('body', 'like', '%'.$needle.'%');
            })->delete();
        }

        OfferLetterChangeRequest::where('offer_letter_id', $offer->id)->delete();

        AuditLog::where('model_type', OfferLetter::class)
            ->where('model_id', $offer->id)
            ->delete();
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
        $offer->loadMissing(['tenant', 'deal.lead', 'deal.lead.property', 'deal.unit', 'discountApprover']);
        $deal = $offer->deal;
        $lead = $deal?->lead;
        // The unit the offer was written on, not the lead's default unit. Reading
        // lead->property here printed one unit's address and deposit against
        // another unit's terms on a letter raised on a chosen unit.
        $property = $deal?->dealUnit();
        $tenant = $offer->tenant;

        $verifier = app(OfferVerificationService::class);

        // Present on every letter: the point is to prove this document is ours,
        // and a letter the agency is still approving is still a document it issued.
        $verifyUrl = $verifier->urlFor($offer);
        $qrSvg = $verifier->qrSvgFor($offer);

        // Signature and seal are marks of authority, so they only appear on a
        // letter that has actually been approved.
        $approved = $offer->isApproved();
        $signatureUrl = $approved ? $this->assetUrl($tenant->signature_path ?? null) : null;
        $stampUrl = $approved ? $this->assetUrl($tenant->stamp_path ?? null) : null;

        // The bank page prints either the tenant's scanned IBAN letter or typed
        // details, whichever the letter was issued with.
        $source = in_array($offer->bank_details_source, ['upload', 'details', 'none'], true)
            ? $offer->bank_details_source
            : 'details';
        // 'none' must clear BOTH sources. Testing only against 'upload' left
        // 'none' printing the saved bank details, because it too is not 'upload'.
        $bankDetails = $source === 'details'
            ? (trim((string) ($offer->bank_details ?: $tenant->bank_details ?? '')) ?: null)
            : null;
        $ibanLetterUrl = $source === 'upload' ? $this->assetUrl($tenant->iban_letter_path ?? null) : null;

        // The occupant's own mark, printed only once it has actually been given.
        $occupantSignatureUrl = $offer->wasSignedByOccupant()
            ? $this->assetUrl($offer->occupant_signature_path)
            : null;

        return view('offers.letter', compact(
            'offer', 'deal', 'lead', 'property', 'tenant',
            'qrSvg', 'verifyUrl', 'signatureUrl', 'stampUrl', 'bankDetails', 'ibanLetterUrl',
            'occupantSignatureUrl'
        ))->render();
    }

    /**
     * A public URL for a stored upload, or null when the file is absent.
     *
     * Returns null rather than a broken <img> when the path is set but the file
     * is gone, so a missing upload cannot print a broken image on a legal
     * document — and cannot throw while printing one either.
     */
    protected function assetUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            return $disk->exists($path) ? $disk->url($path) : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The one field that may still be corrected after approval: the offer date.
     *
     * An issued letter is otherwise frozen because its price and terms are what
     * a manager approved and what the client may already have signed. The date is
     * different in kind - it records when the offer was made, and a letter
     * reconstructed after the fact or typed up late genuinely needs the earlier
     * one. Nothing commercial moves, and the approval is not re-struck.
     *
     * Still refused once the client has signed. At that point the date is part of
     * a signed document, and quietly moving it would be falsifying the client's
     * paperwork rather than fixing a typo.
     */
    protected function correctDateOnIssuedOffer(OfferLetter $offer, User $user, array $v): OfferLetter
    {
        if ($offer->status !== 'issued' || $offer->signed_at) {
            throw new \RuntimeException($this->lockedOfferMessage($offer));
        }

        // Only when a date was actually posted. Otherwise this would turn a
        // refused edit into a silent no-op: the request would appear to succeed
        // while quietly discarding the price change the caller believed it made.
        if (! isset($v['issued_at']) || trim((string) $v['issued_at']) === '') {
            throw new \RuntimeException($this->lockedOfferMessage($offer));
        }

        $offer->issued_at = $this->resolveOfferDate($v['issued_at'] ?? null, $offer->issued_at);
        $offer->save();

        AuditLog::log('offer_letter.offer_date_corrected', $offer, [
            'from' => (string) $offer->getOriginal('issued_at'),
            'to' => (string) $offer->issued_at,
        ]);

        return $offer;
    }

    /**
     * Why a letter cannot be edited, phrased for whoever is being refused.
     *
     * Telling an agent "withdraw it and raise a new one" when a manager could
     * have unlocked it in one click sends them down the slow path for no reason.
     */
    protected function lockedOfferMessage(OfferLetter $offer): string
    {
        if ($offer->isSigned()) {
            return __('This offer letter has been signed by the client and can no longer be edited. Raise a new letter instead.');
        }

        return __('This offer letter is already approved, so its price and terms are locked. Request a change from a manager, or withdraw it and raise a new one.');
    }

    /**
     * Whether this user may edit the letter's commercial terms right now.
     *
     * Deliberately about the *letter and the user's authority*, not about the
     * deal: being able to update the deal does not confer authority over terms
     * someone else approved.
     */
    public function canEditTerms(OfferLetter $offer, User $user): bool
    {
        if ($offer->isSigned()) {
            return false;
        }

        if ($offer->isEditable()) {
            return true;
        }

        // Approved but unsigned: only an approver touches the figures.
        return $offer->isApproved() && $this->canApproveOffer($user);
    }

    /**
     * Raise a request to change an approved letter.
     *
     * Managers and admins do not need this — they can edit directly — so it is
     * refused for them rather than letting a request queue up for someone to
     * approve who could have done it in one step.
     */
    public function requestChange(OfferLetter $offer, User $user, string $reason): OfferLetterChangeRequest
    {
        if ($offer->isSigned()) {
            throw new \RuntimeException($this->lockedOfferMessage($offer));
        }

        if (! $offer->isApproved()) {
            throw new \RuntimeException(__('This offer letter is not approved yet, so it can be edited directly.'));
        }

        if ($this->canApproveOffer($user)) {
            throw new \RuntimeException(__('You can edit this offer letter directly — no change request is needed.'));
        }

        // One live request per letter. A second would sit in the queue pointing
        // at terms that are no longer approved, and approving the first would
        // silently leave the second stranded.
        if ($existing = OfferLetterChangeRequest::pendingFor($offer)->latest('id')->first()) {
            throw new \RuntimeException(
                __('A change request for this offer letter is already waiting for a manager.'),
            );
        }

        $request = OfferLetterChangeRequest::create([
            'tenant_id' => $offer->tenant_id,
            'offer_letter_id' => $offer->id,
            'requested_by' => $user->id,
            'reason' => $reason,
            'status' => OfferLetterChangeRequest::STATUS_PENDING,
        ]);

        Activity::create([
            'tenant_id' => $offer->tenant_id,
            'lead_id' => $offer->lead_id,
            'deal_id' => $offer->deal_id,
            'agent_id' => $user->id,
            'type' => 'note',
            'subject' => __('Change requested on offer letter'),
            'body' => __('A change to offer letter :no was requested: :reason', [
                'no' => $offer->offer_no,
                'reason' => $reason,
            ]),
            'logged_at' => now(),
        ]);

        $this->notifyApproversOfChange($offer->refresh(), $request->refresh(), $user);

        return $request->refresh();
    }

    /**
     * Route a change request to the same people an approval request would go
     * to — the agent's manager chain, or the tenant's owners/admins.
     *
     * Shares the 'offer_letter_approval' opt-in on purpose: a tenant that
     * turned approval emails off did not ask for change-request emails either.
     */
    protected function notifyApproversOfChange(OfferLetter $offer, OfferLetterChangeRequest $changeRequest, User $user): void
    {
        $tenant = $offer->tenant;

        if (! $tenant || ! $tenant->wantsNotification('offer_letter_approval')) {
            return;
        }

        $approvers = $this->approversFor($user);

        if ($approvers->isNotEmpty()) {
            Notification::send($approvers, new OfferLetterChangeRequested($offer, $changeRequest, $user, $tenant));
        }
    }

    /**
     * Approve or reject a pending change request.
     *
     * Approving *unlocks the edit*, it does not perform it: the letter returns
     * to 'pending_approval' so the agent can make the change and a manager then
     * approves the new figures through the ordinary path. Approving without
     * clearing the approval would leave an issued letter carrying terms nobody
     * had approved — the exact gap this flow exists to close.
     */
    public function reviewChange(OfferLetterChangeRequest $request, User $reviewer, bool $approve, ?string $note = null): OfferLetterChangeRequest
    {
        $offer = $request->offerLetter;

        abort_unless($offer, 404);

        if (! $request->isPending()) {
            throw new \RuntimeException(__('This change request has already been reviewed.'));
        }

        if (! $this->canApproveOffer($reviewer)) {
            throw new \RuntimeException(__('Only an admin or manager can review a change request.'));
        }

        $request->fill([
            'status' => $approve ? OfferLetterChangeRequest::STATUS_APPROVED : OfferLetterChangeRequest::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        if ($approve) {
            // The client may be holding a signing link showing the *old* terms.
            // Kill it before the letter becomes editable again, or they could
            // sign a price that is no longer the approved one.
            app(OfferSignatureRequestService::class)->revoke($offer);

            $offer->update([
                'status' => 'pending_approval',
                'approved_by' => null,
                'approved_at' => null,
                'discount_approved_by' => null,
                'discount_approved_at' => null,
            ]);

            Activity::create([
                'tenant_id' => $offer->tenant_id,
                'lead_id' => $offer->lead_id,
                'deal_id' => $offer->deal_id,
                'agent_id' => $reviewer->id,
                'type' => 'note',
                'subject' => __('Change request approved'),
                'body' => __('The change to offer letter :no was approved. The letter is editable again and must be re-approved.', [
                    'no' => $offer->offer_no,
                ]),
                'logged_at' => now(),
            ]);
        } else {
            Activity::create([
                'tenant_id' => $offer->tenant_id,
                'lead_id' => $offer->lead_id,
                'deal_id' => $offer->deal_id,
                'agent_id' => $reviewer->id,
                'type' => 'note',
                'subject' => __('Change request rejected'),
                'body' => __('The change to offer letter :no was rejected.', ['no' => $offer->offer_no]),
                'logged_at' => now(),
            ]);
        }

        AuditLog::log('offer_letter.change_request_reviewed', $offer, [
            'status' => $request->status,
        ]);

        // Tell the agent either way; a silently-rejected request is worse than
        // a refusal, because they go on waiting for an unlock that never comes.
        if ($request->requested_by !== $reviewer->id && $offer->lead?->agent_id) {
            $agent = User::find($offer->lead->agent_id);
            if ($agent) {
                Notification::send($agent, new OfferLetterChangeReviewed($offer->refresh(), $request->refresh(), $approve, $reviewer));
            }
        }

        return $request->refresh();
    }

    /**
     * The units this client has actually been shown, offered as the choice of
     * unit to write the offer on.
     *
     * "Viewed" is assembled from the two places a unit gets attached to a lead:
     * the linked-units list an agent curates on the lead, and the units with a
     * viewing against it. Both are needed — an agent often links a unit before
     * the viewing is booked, and a viewing can exist without the link.
     *
     * The unit already stamped on the deal is always included and sorts first,
     * otherwise a letter being corrected on a unit that has since dropped out of
     * the list would show a picker that does not contain its own current unit.
     */
    public function viewedUnits(Deal $deal): Collection
    {
        $deal->loadMissing(['lead.properties', 'lead.showings']);

        $ids = $deal->lead?->properties->pluck('id')
            ->merge($deal->lead?->showings->whereNotNull('property_id')->pluck('property_id') ?? collect())
            ->push($deal->property_id)
            ->filter()
            ->unique()
            ->values();

        $units = Property::query()
            ->where('tenant_id', $deal->tenant_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $ids->map(fn ($id) => $units->get($id))->filter()->values();
    }

    /**
     * Stamp the chosen unit onto the deal before anything is priced.
     *
     * `Deal::property()` and `Deal::unit()` are two names for the same
     * `property_id` column, so setting it here steers the listed price, the
     * commission rate and the three fees together. Doing it inside the request
     * rather than trusting the posted figures is what stops a hand-crafted POST
     * from writing one unit's price onto another unit's letter.
     */
    public function applyChosenUnit(Deal $deal, mixed $unitId): void
    {
        if ($unitId === null || $unitId === '') {
            return;
        }

        $unit = Property::query()
            ->where('tenant_id', $deal->tenant_id)
            ->find($unitId);

        abort_unless($unit, 422, __('That unit is not available for this offer.'));

        $deal->property_id = $unit->id;
        $deal->save();
    }

    /**
     * The money and term figures a unit implies, for the picker to fill in.
     *
     * Discount and commission are deliberately absent: they belong to the
     * negotiation, not to the inventory row, and carrying them over from the
     * previous unit would silently re-price an offer the agent had already
     * negotiated.
     */
    public function defaultsForUnit(Deal $deal, mixed $unitId): array
    {
        $this->applyChosenUnit($deal, $unitId);
        $deal->refresh()->loadMissing(['lead', 'lead.property', 'unit', 'tenant']);

        $d = $this->buildDefaults($deal);

        return [
            'unit_id' => $deal->property_id,
            'original_amount' => $d['original_amount'],
            'contract_fee' => $d['contract_fee'],
            'admin_fee' => $d['admin_fee'],
            'security_deposit' => $d['security_deposit'],
            'commission_rate_pct' => $d['commission_rate_pct'],
            'commission_amount' => $d['commission_amount'],
            'commission_vat' => $d['commission_vat'],
            'commission_total' => $d['commission_total'],
            'admin_fee_vat' => $d['admin_fee_vat'],
            'contract_fee_vat' => $d['contract_fee_vat'],
        ];
    }

    /**
     * Resolve the contract start/end pair from the term in years.
     *
     * The end date is the start plus the term, less a day, so a tenancy beginning
     * 1 March runs to 28 February rather than ending on the same calendar day a
     * year later. That -1 day is why `buildDefaults` had it hardcoded to one
     * year; it is kept here so the picker, the form and the printer all agree.
     *
     * Only applied when the request actually carries `contract_years`. Callers
     * that post a start and an end with no term keep both exactly as given, so
     * this cannot quietly rewrite an existing letter's dates.
     *
     * A start with no term still gets the default one year, and a term with no
     * start leaves the end date alone — there is nothing to add it to.
     */
    protected function resolveContractDates(array $v, array $current = []): array
    {
        $start = $v['contract_start_date'] ?? ($current['contract_start_date'] ?? null);

        if ($start) {
            try {
                $start = Carbon::parse($start)->startOfDay();
            } catch (\Throwable $e) {
                return $current;
            }
        }

        $years = null;
        if (array_key_exists('contract_years', $v)) {
            $years = max(1, (int) $v['contract_years']);
        } elseif ($current !== []) {
            $years = max(1, (int) ($current['contract_years'] ?? 1));
        }

        if (! $start || ! $years) {
            return $current;
        }

        return [
            'contract_years' => $years,
            'contract_start_date' => $start,
            'contract_end_date' => $start->copy()->addYears($years)->subDay(),
        ];
    }

    /**
     * Resolve the offer date, defaulting to today.
     *
     * This is the date printed on the letter and shown in the list, and it is
     * editable so a letter issued from a reconstructed or imported deal can carry
     * the date it was really made rather than the date it was typed up. Normal
     * use never posts anything and gets today.
     *
     * A blank or unparseable value falls back rather than throwing: a bad date in
     * a form field must never stop an offer being issued. $fallback lets the edit
     * path keep the date already on the record when the field is left alone.
     */
    protected function resolveOfferDate($value, $fallback = null): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse(trim($value))->startOfDay();
            } catch (\Throwable $e) {
                // Fall through to the default.
            }
        }

        if ($fallback instanceof Carbon) {
            return $fallback->copy()->startOfDay();
        }

        return now()->startOfDay();
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
