<?php

namespace App\Notifications;

use App\Models\ServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ServiceProviderApproved extends Notification
{
    use Queueable;

    public function __construct(protected ServiceProvider $provider) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Keystone] Your registration was approved — '.$this->provider->company_name)
            ->greeting('Hello '.$this->provider->representative_name.',')
            ->line("Congratulations. {$this->provider->company_name} is now an approved service provider.")
            ->line('Our team can now contact you for your services on behalf of our clients.')
            ->action('View Application', url('/service-providers/provider/'.$this->provider->edit_token));
    }
}