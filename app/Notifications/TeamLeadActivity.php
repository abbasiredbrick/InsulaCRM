<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In-app + email notification for managers when a team member logs activity on
 * a lead assigned to their team, so managers can follow up.
 *
 * Deliberately synchronous (no ShouldQueue): the production server has no queue
 * worker, so the database channel must persist immediately. Mirrors
 * ScheduleFeedbackNotification.
 */
class TeamLeadActivity extends Notification
{
    use DigestAwareNotification;
    use Queueable;

    public function __construct(
        protected Lead $lead,
        protected Activity $activity,
        protected User $agent,
    ) {}

    public function toArray(object $notifiable): array
    {
        $type = $this->activity->subject
            ? ($this->activity->type.': '.$this->activity->subject)
            : $this->activity->type;

        return [
            'type' => 'team_activity',
            'icon' => 'users',
            'color' => 'purple',
            'title' => __('New activity on :name', ['name' => $this->lead->full_name]),
            'body' => __(':agent logged :type.', [
                'agent' => $this->agent->name,
                'type' => ucfirst($type),
            ]),
            'url' => url("/leads/{$this->lead->id}"),
            'lead_id' => $this->lead->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = $this->activity->subject
            ? ($this->activity->type.': '.$this->activity->subject)
            : $this->activity->type;

        $mail = (new MailMessage)
            ->subject('[Keystone] '.__('New activity on :name', ['name' => $this->lead->full_name]))
            ->greeting("Hello {$notifiable->name},")
            ->line(__(':agent logged :type on this client.', [
                'agent' => $this->agent->name,
                'type' => ucfirst($type),
            ]))
            ->line(__('Client: :name (:phone)', [
                'name' => $this->lead->full_name,
                'phone' => $this->lead->phone,
            ]));

        if (filled($this->activity->body)) {
            $mail->line(__('Notes: :body', ['body' => \Illuminate\Support\Str::limit($this->activity->body, 300)]));
        }

        return $mail->action(__('View Client'), url("/leads/{$this->lead->id}"));
    }
}
