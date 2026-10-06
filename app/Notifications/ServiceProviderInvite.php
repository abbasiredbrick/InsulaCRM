<?php

namespace App\Notifications;

use App\Models\ServiceProviderRegistrationLink;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ServiceProviderInvite extends Notification
{
    use Queueable;

    public function __construct(protected ServiceProviderRegistrationLink $link) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->link->tenant;

        return (new MailMessage)
            ->subject('[Keystone] Register your business as a service provider')
            ->greeting($this->link->provider_name ? 'Hello '.$this->link->provider_name.',' : 'Hello,')
            ->line("{$tenant->name} has invited your company to register as an approved service provider.")
            ->line('Register your company details, your representative Emirates ID and upload your supporting documents using the link below.')
            ->action('Complete Registration', $this->link->url())
            ->line('This link is valid until '.$this->link->expires_at->format('M j, Y').' and can only be used once.');

    }
}