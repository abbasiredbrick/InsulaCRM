<?php

namespace App\Services;

use App\Facades\Hooks;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;

/**
 * Keeps the lead's leasing pipeline stage in sync with viewings/showings.
 *
 * A showing (property viewing with the client) is the same concept for lease and
 * sale. Leasing leads have explicit viewing stages in their pipeline
 * (viewing_requested → viewing_scheduled → viewing_done); sales leads simply get
 * a showing record + calendar activity but no stage to advance.
 */
class LeadViewingService
{
    public const VIEWING_STAGES = ['viewing_requested', 'viewing_scheduled', 'viewing_done'];

    /**
     * Advance a leasing lead forward along its viewing pipeline stage.
     *
     * Only moves forward (never regresses) and never touches sales leads.
     */
    public function advanceToStage(Lead $lead, string $targetStage, ?int $actorId = null): bool
    {
        if ($lead->dealType() !== 'rent') {
            return false;
        }

        $order = array_keys(Lead::LEASING_STAGES);
        $targetIdx = array_search($targetStage, $order, true);
        if ($targetIdx === false) {
            return false;
        }

        $currentIdx = $lead->stage ? array_search($lead->stage, $order, true) : false;
        if ($currentIdx !== false && $currentIdx >= $targetIdx) {
            return false;
        }

        $oldStage = $lead->stage;
        $lead->stage = $targetStage;
        $lead->stage_changed_at = now();
        $lead->save();

        if ($oldStage !== $lead->stage) {
            AuditLog::log('lead.stage_changed', $lead, ['stage' => $oldStage], ['stage' => $lead->stage]);
            Hooks::doAction('lead.stage_changed', $lead, $oldStage);
        }

        return true;
    }

    /**
     * Create a calendar-visible "viewing" activity for the lead.
     *
     * Activities of type viewing represent viewings on the lead timeline, so the
     * manager sees that a viewing was requested/scheduled/completed even when no
     * full Showing record exists.
     */
    public function logViewingActivity(Lead $lead, string $viewingStage, ?string $scheduledAt = null): Activity
    {
        $labels = [
            'viewing_requested' => __('Viewing Requested'),
            'viewing_scheduled' => __('Viewing Scheduled'),
            'viewing_done' => __('Unit Viewed'),
        ];

        $subject = $labels[$viewingStage] ?? __('Viewing');
        $body = null;

        if ($scheduledAt) {
            $body = __('Viewing scheduled for :when', ['when' => $scheduledAt]);
        } elseif ($unit = $lead->property) {
            $body = __('Viewing at :address', ['address' => $unit->address ?? $unit->id]);
        }

        return Activity::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => auth()->id(),
            'type' => 'viewing',
            'subject' => $subject,
            'body' => $body,
            'logged_at' => $scheduledAt ? \Carbon\Carbon::parse($scheduledAt) : now(),
        ]);
    }

    /**
     * Whether the given stage key is a viewing pipeline stage.
     */
    public static function isViewingStage(?string $stage): bool
    {
        return $stage !== null && in_array($stage, self::VIEWING_STAGES, true);
    }
}
