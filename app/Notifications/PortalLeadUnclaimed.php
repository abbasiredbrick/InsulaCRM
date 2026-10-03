<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired for admin/owner users when an inbound portal lead could not be matched
 * to an agent and the tenant's portal-lead setting keeps it unassigned.
 *
 * Land physically in the in-app bell, and email the Owner so the lead never
 * lingers silently. Mail only — no digest-batching.
 */
class PortalLeadUnclaimed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Lead $lead) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;

        return (new MailMessage)
            ->subject("[Keystone] Inbound lead awaiting assignment: {$lead->full_name}")
            ->greeting("Hello {$notifiable->name},")
            ->line('A new lead could not be matched to an agent and needs your attention.')
            ->line('**Name:** '.($lead->full_name ?: 'N/A'))
            ->line('**Phone:** '.($lead->phone ?: 'N/A'))
            ->line('**Email:** '.($lead->email ?: 'N/A'))
            ->line('**Source:** '.ucwords(str_replace('_', ' ', $lead->lead_source ?? 'N/A')))
            ->action('Review Lead', url("/leads/{$lead->id}"))
            ->line('Claim or assign this lead so it does not go unanswered.');
    }
}
