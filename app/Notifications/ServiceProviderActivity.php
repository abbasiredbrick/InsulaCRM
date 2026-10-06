<?php

namespace App\Notifications;

use App\Models\ServiceProvider;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceProviderActivity extends Notification implements ShouldQueue
{
    use DigestAwareNotification;
    use Queueable;

    protected const PALETTE = [
        'submitted' => ['icon' => 'plus', 'color' => 'blue', 'title' => 'New service provider application'],
        'changes_requested' => ['icon' => 'edit', 'color' => 'orange', 'title' => 'Changes requested for service provider'],
        'resubmitted' => ['icon' => 'refresh', 'color' => 'blue', 'title' => 'Service provider application resubmitted'],
        'approved' => ['icon' => 'check', 'color' => 'green', 'title' => 'Service provider approved'],
        'rejected' => ['icon' => 'x', 'color' => 'red', 'title' => 'Service provider rejected'],
    ];

    public function __construct(
        protected ServiceProvider $provider,
        protected string $type,
    ) {}

    public function toArray(object $notifiable): array
    {
        $palette = self::PALETTE[$this->type] ?? self::PALETTE['submitted'];

        return [
            'type' => 'service_provider_'.$this->type,
            'icon' => $palette['icon'],
            'color' => $palette['color'],
            'title' => __($palette['title']),
            'body' => __(':company has :status. View it in the review queue.', [
                'company' => $this->provider->company_name,
                'status' => $this->provider->statusLabel(),
            ]),
            'url' => url('/service-providers/review'),
            'service_provider_id' => $this->provider->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $palette = self::PALETTE[$this->type] ?? self::PALETTE['submitted'];

        return (new MailMessage)
            ->subject('[Keystone] '.$palette['title'].' — '.$this->provider->company_name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->provider->company_name.' is '.$this->provider->statusLabel().'.')
            ->action('Review Application', url('/service-providers/review'));
    }
}