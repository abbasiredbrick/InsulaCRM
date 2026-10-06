<?php

namespace App\Notifications;

use App\Models\ServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ServiceProviderRejected extends Notification
{
    use Queueable;

    public function __construct(
        protected ServiceProvider $provider,
        protected string $comment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Keystone] Update on your registration — '.$this->provider->company_name)
            ->greeting('Hello '.$this->provider->representative_name.',')
            ->line('Thank you for applying to register as a service provider.')
            ->line($this->comment ? 'We are unable to approve your registration for the following reason: '.PHP_EOL.$this->comment : 'We are unable to approve your registration at this time.')
            ->line('You are welcome to register again with updated details in the future.');
    }
}