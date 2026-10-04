<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Showing;
use App\Models\Tenant;

/**
 * Keeps the deals pipeline fed from leads.
 *
 * A rent lead becomes a Deal when the client asks for an offer on a specific
 * unit — Lead::stage `offer_requested`, promoted from viewing feedback. Unit
 * viewings ("I don't like this one, show me another") are activity and must
 * never open a deal, which is what the old `viewing_done` trigger did. Sale
 * leads still convert at `offer_sent`.
 *
 * Creation is idempotent — one deal per lead, and only forward (existing deals
 * are never touched).
 */
class PipelineSyncService
{
    /**
     * The first rent lead stage that opens a deal. The unit the client asked for
     * is stamped onto the deal from the showing that promoted the lead.
     */
    public const RENT_TRIGGER_STAGE = 'offer_requested';

    /**
     * Lead sale-stage → wholesale deal-stage mapping.
     */
    public const LEAD_SALE_TO_WHOLESALE = [
        'offer_sent' => 'offer_presented',
        'negotiating' => 'offer_presented',
        'offer_accepted' => 'under_contract',
        'spa_signed' => 'under_contract',
        'deed_transfer' => 'closing',
        'closed' => 'closed_won',
    ];

    /**
     * Lead sale-stage → real-estate deal-stage mapping.
     */
    public const LEAD_SALE_TO_REALESTATE = [
        'offer_sent' => 'offer_received',
        'negotiating' => 'offer_received',
        'offer_accepted' => 'under_contract',
        'spa_signed' => 'under_contract',
        'deed_transfer' => 'closing',
        'closed' => 'closed_won',
    ];

    /**
     * The lead stages that mean revenue is in play and the lead belongs on the
     * pipeline board (rent: from offer requested; sale: from offer sent).
     */
    public function triggerStages(string $dealType, ?Tenant $tenant = null): array
    {
        if ($dealType === 'rent') {
            $keys = array_keys(Lead::LEASING_STAGES);
            $offset = array_search(self::RENT_TRIGGER_STAGE, $keys, true);

            if ($offset === false) {
                return [];
            }

            // closed_lost lives at the end of the leasing ladder so a lost lease
            // has somewhere to go, but a dead lead has no revenue in play and
            // must not drag a deal onto the board.
            return array_values(array_diff(array_slice($keys, $offset), Deal::LOST_STAGES));
        }

        return array_keys(Lead::SALES_STAGES);
    }

    public function shouldHaveDeal(Lead $lead, ?Tenant $tenant = null): bool
    {
        return $lead->stage !== null
            && in_array($lead->stage, $this->triggerStages($lead->dealType(), $tenant), true);
    }

    /**
     * Map a lead's stage to the deal stage key for the tenant's business mode.
     * Rent keys pass through directly (both vocabularies match); sale keys map
     * onto the mode-specific sale pipeline.
     */
    public function dealStageFor(Lead $lead, ?Tenant $tenant = null): ?string
    {
        if ($lead->dealType() === 'rent') {
            return $lead->stage;
        }

        $map = BusinessModeService::isRealEstate($tenant)
            ? self::LEAD_SALE_TO_REALESTATE
            : self::LEAD_SALE_TO_WHOLESALE;

        return $map[$lead->stage] ?? null;
    }

    /**
     * Create a Deal for a lead that has reached a revenue stage. Does nothing
     * (returns null) when the lead is not there yet or already has a deal.
     *
     * Pass the viewing that promoted the lead so the deal records which unit the
     * client actually asked for — it is frequently not the unit on the lead, and
     * "which unit did they sign" is unanswerable without it.
     */
    public function syncForLead(Lead $lead, ?Tenant $tenant = null, ?Showing $showing = null): ?Deal
    {
        if (! $this->shouldHaveDeal($lead, $tenant)) {
            return null;
        }

        $stage = $this->dealStageFor($lead, $tenant);
        if ($stage === null) {
            return null;
        }

        $existing = Deal::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->where('lead_id', $lead->id)
            ->first();

        if ($existing) {
            return null;
        }

        // The unit the offer was requested on, not just the unit on the lead.
        $unit = $showing?->property ?? $lead->property;

        $isRE = BusinessModeService::isRealEstate($tenant);
        $data = [
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'property_id' => $unit?->id,
            'agent_id' => $lead->agent_id,
            'deal_type' => $lead->dealType(),
            'title' => trim(($lead->full_name ?: "Lead #{$lead->id}")
                .($unit?->address ? ' — '.$unit->address : '')),
            'stage' => $stage,
            'stage_changed_at' => $lead->stage_changed_at ?? $lead->updated_at ?? now(),
        ];

        $feeColumn = $isRE ? 'total_commission' : 'assignment_fee';
        if ((float) $lead->commission_amount > 0) {
            $data[$feeColumn] = $lead->commission_amount;
        }

        return Deal::withoutGlobalScopes()->create($data);
    }
}
