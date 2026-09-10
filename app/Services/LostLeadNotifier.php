<?php

namespace App\Services;

use App\Facades\Hooks;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadLostForReview;
use Illuminate\Support\Facades\Log;

/**
 * Flags lost/dead leads for management cross-check so agents cannot quietly
 * steer a deal to another company for a higher commission.
 */
class LostLeadNotifier
{
    public const LOST_STATUSES = ['closed_lost', 'dead'];

    /**
     * Whether a lead status is considered "lost" for the review flow.
     */
    public static function isLostStatus(?string $status): bool
    {
        return $status !== null && in_array($status, self::LOST_STATUSES, true);
    }

    /**
     * Notify every admin in the lead's tenant that a lead was marked lost/dead.
     */
    public static function notify(Lead $lead, string $newStatus, ?string $oldStatus = null): void
    {
        try {
            $actor = auth()->user();

            AuditLog::log('lead.lost_flagged', $lead, ['status' => $oldStatus], ['status' => $newStatus, 'flagged_for_review' => true]);

            Hooks::doAction('lead.lost_flagged', $lead, $oldStatus, $newStatus);

            $admins = User::where('tenant_id', $lead->tenant_id)
                ->where('is_active', true)
                ->whereHas('role', fn ($q) => $q->where('name', 'admin'))
                ->get();

            if ($admins->isEmpty()) {
                return;
            }

            $actorName = $actor?->name ?? __('System');

            foreach ($admins as $admin) {
                $admin->notify(new LeadLostForReview($lead, $newStatus, $actorName));
            }
        } catch (\Throwable $e) {
            // Notifications must never break the status change itself.
            Log::error("Lost-lead notification failed for lead #{$lead->id}: {$e->getMessage()}");
        }
    }
}