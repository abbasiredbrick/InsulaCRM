<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;

/**
 * Orchestrates closing a transaction as Won.
 *
 * In real estate mode a transaction may only be closed as Won once the client
 * has signed an offer letter and the signed copy has been uploaded back to the
 * CRM. Closing a deal in the pipeline and closing its lead stay in sync, the
 * standard agency commission is applied to the deal / lead, and the company vs
 * agent split is snapshotted so the agent sees exactly what they earned.
 */
class TransactionCloseService
{
    public function __construct(
        protected DealCommissionService $commission,
        protected CommissionCalculationService $splits,
        protected OfferLetterService $offers,
    ) {}

    /**
     * Whether the signed-offer gate applies (brokerage mode only).
     */
    public function requiresSignedOffer(): bool
    {
        return BusinessModeService::isRealEstate();
    }

    /**
     * Error message when the deal cannot be closed as Won, or null when it can.
     */
    public function gateError(Lead $lead): ?string
    {
        if (! $this->requiresSignedOffer()) {
            return null;
        }

        if ($lead->hasSignedOffer()) {
            return null;
        }

        return __('The deal cannot be closed as Won until a signed offer letter has been uploaded. Generate the offer letter, print it for the client, then upload the signed copy.');
    }

    /**
     * Synchronise lead status + deal stage and apply the standard commission
     * when the lead/its deal is marked Won.
     *
     * @param  Deal|null  $deal  the deal that was closed; defaults to the lead's
     *                           most recent deal. Rent deals keep their leasing
     *                           stage (they have no closed_won) but still carry
     *                           the commission.
     */
    public function closeAsWon(Lead $lead, ?Deal $deal = null, ?User $user = null): void
    {
        $deal ??= $lead->deals()->orderByDesc('id')->first();

        // Keep lead + deal in sync: whichever side was marked Won wins.
        if ($lead->status !== 'closed_won') {
            $lead->update(['status' => 'closed_won']);
        }

        if ($deal && $deal->dealType() !== 'rent' && ! in_array($deal->stage, ['closed_won', 'closed_lost'], true)) {
            $deal->update(['stage' => 'closed_won', 'stage_changed_at' => now()]);
        }

        $this->applyCommission($lead, $deal, $user);
    }

    /**
     * Fill the standard commission (from the signed offer's approved value, or
     * the standard rate on the contract value) and snapshot the agent split.
     */
    protected function applyCommission(Lead $lead, ?Deal $deal, ?User $user): void
    {
        $signed = $lead->signedOffer();
        $gross = $signed?->approved_amount;
        $commission = $this->commission->commissionFor($deal ?? $lead, $gross !== null ? (float) $gross : null);

        if ($commission <= 0) {
            return;
        }

        if ($deal && ! (float) $deal->total_commission) {
            $deal->update(['total_commission' => $commission]);
        }

        if (! $deal && $lead->commission_amount === null) {
            $lead->update(['commission_amount' => $commission]);
        }

        try {
            $this->splits->calculate($lead, $commission);
        } catch (\RuntimeException $e) {
            // The split is a nicety for the agent view; a lead without an agent
            // or without co-agent rows is still reported on the dashboard.
        }
    }
}
