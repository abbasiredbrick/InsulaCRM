<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * Fired when a known client (matched by phone or email) shows interest in a
 * different listing. The client keeps their existing lead record; the new
 * property is attached as an additional opportunity and the owning agent is
 * told about it.
 *
 * Synchronous (no queue) so it always lands in the in-app bell on shared hosting.
 */
class ReturningClientInterest extends Notification
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
            'type' => 'returning_client_interest',
            'icon' => 'refresh',
            'color' => 'green',
            'title' => __('Returning client showed new interest'),
            'body' => __(':name is interested in another property. The new listing was added to their lead.', [
                'name' => $this->lead->full_name,
            ]),
            'url' => url("/leads/{$this->lead->id}"),
            'lead_id' => $this->lead->id,
        ];
    }
}