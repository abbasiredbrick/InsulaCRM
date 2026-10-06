<?php

namespace App\Notifications;

use App\Models\ServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ServiceProviderSubmissionReceived extends Notification
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
            ->subject('[Keystone] We received your registration — '.$this->provider->company_name)
            ->greeting('Hello '.$this->provider->representative_name.',')
            ->line("Thank you. We have received the registration for {$this->provider->company_name} and it is now under review.")
            ->line('You can check the status of your application any time using the link below.')
            ->action('View Application Status', url('/service-providers/provider/'.$this->provider->edit_token));
    }
}