<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Notifications\Notification;

/**
 * Alert management when a lead is marked as lost / dead so they can cross-check
 * with the client (guards against agents steering deals to another company for a
 * higher commission).
 */
class LeadLostForReview extends Notification
{
    public function __construct(
        protected Lead $lead,
        protected string $newStatus,
        protected string $actorName
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_lost_for_review',
            'icon' => 'alert-triangle',
            'color' => 'red',
            'title' => __('Lead lost — verify with client'),
            'body' => __(
                ':agent marked :lead (:status) — please cross-check with the client.',
                [
                    'agent' => $this->actorName,
                    'lead' => $this->lead->full_name,
                    'status' => __(\App\Services\CustomFieldService::getOptions('lead_status')[$this->newStatus] ?? ucwords(str_replace('_', ' ', $this->newStatus))),
                ]
            ),
            'url' => route('leads.show', $this->lead),
            'lead_id' => $this->lead->id,
            'new_status' => $this->newStatus,
        ];
    }
}