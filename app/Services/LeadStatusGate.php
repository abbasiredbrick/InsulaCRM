<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Status workflow gate: agents may not push a lead to a "lost" status
 * (dead / closed_lost) on their own until WhatsApp-communication proof has
 * been uploaded. The gate only guards *manual* lead-side transitions through
 * the controllers. The deal-side path — DealLifecycleService setting the lead
 * to closed_lost when its offer is declined — calls setLeadStatus() directly
 * on the model and never passes through here, so a declined offer remains a
 * sanctioned, screenshot-free route to closed_lost.
 */
class LeadStatusGate
{
    /**
     * Statuses that require a lead proof before an agent may set them.
     * closed_won is deal-driven only (TransactionCloseService) and never
     * appears here or in the assignable slugs.
     */
    public const PROOF_REQUIRED_STATUSES = ['dead', 'closed_lost'];

    public static function requiresProof(string $status): bool
    {
        return in_array($status, self::PROOF_REQUIRED_STATUSES, true);
    }

    /**
     * Gate error for a proposed status transition, or null when it may proceed.
     */
    public static function proofError(Lead $lead, string $status): ?string
    {
        if (! self::requiresProof($status)) {
            return null;
        }

        if ($lead->hasLostProof()) {
            return null;
        }

        $label = self::statusLabel($status);

        return __('Marking this lead :status requires a WhatsApp screenshot as proof. Upload it first.', [
            'status' => $label,
        ]);
    }

    /**
     * Status <option> list for a single lead: every manual (assignable) status
     * except the proof-gated ones the lead has not proven. Used by the inline
     * status select on the leads table, the Log Activity mover and the Kanban
     * move menu.
     */
    public static function statusOptionsFor(Lead $lead): array
    {
        $options = CustomFieldService::getAssignableOptions('lead_status');

        if (! $lead->hasLostProof()) {
            $options = array_filter($options, fn ($key) => ! self::requiresProof($key), ARRAY_FILTER_USE_KEY);
        }

        return $options;
    }

    /**
     * Status <option> list for bulk operations. A batch of leads cannot carry
     * per-lead proof, so the proof-gated statuses are never offered here even
     * though any single selected lead might already have uploaded proof.
     */
    public static function bulkStatusOptions(): array
    {
        return array_diff_key(
            CustomFieldService::getAssignableOptions('lead_status'),
            array_flip(self::PROOF_REQUIRED_STATUSES)
        );
    }

    protected static function statusLabel(string $status): string
    {
        return CustomFieldService::getOptions('lead_status')[$status] ?? $status;
    }
}
