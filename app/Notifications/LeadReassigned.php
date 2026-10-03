<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification when a lead is reassigned to a new agent (new agent), away from
 * an agent (old agent) or within a team (managers).
 *
 * The newly assigned agent receives an email (unless they opted into the daily
 * digest), matching the lead-assignment delivery convention. Deliberately
 * synchronous (no ShouldQueue): the production server has no queue worker, so
 * the database channel must persist immediately.
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
        $isNewAgent = $notifiable->id === $this->to->id;

        if ($isNewAgent && ($notifiable->notification_delivery ?? 'instant') !== 'daily_digest') {
            return ['database', 'mail'];
        }

        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('[Keystone] New lead assigned: :name', [
                'name' => $this->lead->full_name,
            ]))
            ->greeting(__('Hello :name,', ['name' => $notifiable->name]))
            ->line(__('A lead has been assigned to you.'))
            ->line('**'.__('Name').':** '.$this->lead->full_name)
            ->line('**'.__('Phone').':** '.($this->lead->phone ?: 'N/A'))
            ->line('**'.__('Email').':** '.($this->lead->email ?: 'N/A'));

        if ($this->reason) {
            $mail->line('**'.__('Reason').':** '.$this->reason);
        }

        return $mail
            ->action(__('View Lead'), url("/leads/{$this->lead->id}"))
            ->line(__('Please follow up with this lead promptly.'));
    }

    public function toArray(object $notifiable): array
    {
        $isToAgent = $notifiable->id === $this->to->id;
        $isFromAgent = $this->from !== null && $notifiable->id === $this->from->id;

        if ($isToAgent) {
            $title = __('New lead assigned to you');
            $body = __('Lead :name has been assigned to you.', ['name' => $this->lead->full_name]);
            if ($this->from !== null) {
                $body .= ' '.__('It was moved from :from.', ['from' => $this->from->name]);
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
            $body .= ' '.__('Reason: :reason', ['reason' => $this->reason]);
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
