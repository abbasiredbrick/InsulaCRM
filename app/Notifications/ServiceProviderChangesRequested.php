<?php

namespace App\Notifications;

use App\Models\ServiceProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class ServiceProviderChangesRequested extends Notification
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
            ->subject('[Keystone] Changes requested for your registration — '.$this->provider->company_name)
            ->greeting('Hello '.$this->provider->representative_name.',')
            ->line('We reviewed your registration and need a few changes before it can be approved:')
            ->line($this->comment)
            ->line('Edit your application (including replacing any documents) and submit it again using the link below.')
            ->action('Edit Application', url('/service-providers/provider/'.$this->provider->edit_token.'/edit'));
    }
}