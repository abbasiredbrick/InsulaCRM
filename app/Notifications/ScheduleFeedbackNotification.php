<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;

/**
 * Fired when feedback is logged on a schedule (viewing/task/meeting) for a
 * lead. The assigned agent and every manager in that agent's reporting chain
 * get an in-app + email notification so they can follow up with the client.
 *
 * Deliberately synchronous (no ShouldQueue): the production server has no
 * queue worker, so the database channel must persist immediately. Mirrors
 * TeamLeadActivity.
 */
class ScheduleFeedbackNotification extends Notification
{
    use Queueable;
    use DigestAwareNotification;

    public function __construct(
        protected Activity $activity,
        protected Lead $lead,
        protected string $summary,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'schedule_feedback',
            'icon' => 'message',
            'color' => 'teal',
            'title' => __('Schedule feedback'),
            'body' => $this->summary,
            'url' => url("/leads/{$this->lead->id}"),
            'lead_id' => $this->lead->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Keystone] ' . __('Schedule feedback: :lead', ['lead' => $this->lead->full_name]))
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary)
            ->line(__('Client: :name (:phone)', [
                'name' => $this->lead->full_name,
                'phone' => $this->lead->phone,
            ]))
            ->when($this->lead->property, fn ($mail) => $mail->line(__('Unit: :address', ['address' => $this->lead->property->address])))
            ->action(__('View Client'), url("/leads/{$this->lead->id}"));
    }
}
