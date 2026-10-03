<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamMemberInvited extends Notification implements ShouldQueue
{
    use DigestAwareNotification;
    use Queueable;

    public function __construct(
        protected Tenant $tenant,
        protected string $roleName,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'team_member_invited',
            'icon' => 'user-check',
            'color' => 'cyan',
            'title' => __('Welcome to :name', ['name' => $this->tenant->name]),
            'body' => __('You have been added as :role.', ['role' => ucwords(str_replace('_', ' ', $this->roleName))]),
            'url' => url('/dashboard'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenantName = $this->tenant->name;
        $roleLabel = ucwords(str_replace('_', ' ', $this->roleName));

        return (new MailMessage)
            ->subject('[Keystone] Welcome to the team')
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been added as a team member on **{$tenantName}**.")
            ->line("**Role:** {$roleLabel}")
            ->line("**Email (username):** {$notifiable->email}")
            ->line('Please log in with the credentials provided by your admin.')
            ->action('Log In', url('/login'))
            ->line('Welcome to the team!');
    }
}
