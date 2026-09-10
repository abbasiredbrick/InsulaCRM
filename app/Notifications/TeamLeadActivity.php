<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * In-app notification for managers when a team member logs activity on a lead
 * assigned to their team, so managers can follow up.
 *
 * Deliberately synchronous (no ShouldQueue): the production server has no queue
 * worker, so the database channel must persist immediately.
 */
class TeamLeadActivity extends Notification
{
    public function __construct(
        protected Lead $lead,
        protected Activity $activity,
        protected User $agent,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $type = $this->activity->subject
            ? ($this->activity->type . ': ' . $this->activity->subject)
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
}