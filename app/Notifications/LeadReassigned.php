<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * In-app notification when a lead is reassigned away from an agent (old agent)
 * or reassigned within a team (managers).
 *
 * Deliberately synchronous (no ShouldQueue): the production server has no queue
 * worker, so the database channel must persist immediately.
 */
class LeadReassigned extends Notification
{
    public function __construct(
        protected Lead $lead,
        protected ?User $from,
        protected User $to,
        protected ?string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $isToAgent = $notifiable->id === $this->to->id;
        $isFromAgent = $this->from !== null && $notifiable->id === $this->from->id;

        if ($isToAgent) {
            $title = __('New lead assigned to you');
            $body = __('Lead :name has been assigned to you.', ['name' => $this->lead->full_name]);
            if ($this->from !== null) {
                $body .= ' ' . __('It was moved from :from.', ['from' => $this->from->name]);
            }
        } elseif ($isFromAgent) {
            $title = __('Lead reassigned');
            $body = __('Your lead :name has been reassigned to :to.', [
                'name' => $this->lead->full_name,
                'to' => $this->to->name,
            ]);
        } else {
            $title = __('Lead reassigned');
            $body = __('Lead :name was reassigned from :from to :to.', [
                'name' => $this->lead->full_name,
                'from' => $this->from?->name ?? __('unassigned'),
                'to' => $this->to->name,
            ]);
        }

        if ($this->reason) {
            $body .= ' ' . __('Reason: :reason', ['reason' => $this->reason]);
        }

        return [
            'type' => 'lead_reassigned',
            'icon' => 'arrows-shuffle',
            'color' => 'orange',
            'title' => $title,
            'body' => $body,
            'url' => url("/leads/{$this->lead->id}"),
            'lead_id' => $this->lead->id,
        ];
    }
}