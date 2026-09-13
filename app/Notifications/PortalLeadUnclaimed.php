<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * Fired for admin users when an inbound portal lead could not be matched to an
 * agent and the tenant's portal-lead setting keeps it unassigned.
 *
 * Synchronous (no queue) so it always lands in the in-app bell on shared hosting.
 */
class PortalLeadUnclaimed extends Notification
{
    public function __construct(protected Lead $lead)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'portal_lead_unclaimed',
            'icon' => 'inbox',
            'color' => 'yellow',
            'title' => __('Inbound lead awaiting assignment'),
            'body' => __(':name (:source) lead is unassigned and ready for claim.', [
                'name' => $this->lead->full_name,
                'source' => $this->lead->lead_source,
            ]),
            'url' => url("/leads/{$this->lead->id}"),
            'lead_id' => $this->lead->id,
        ];
    }
}