<?php

namespace App\Notifications;

use App\Models\Lease;
use App\Models\Tenant;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaseExpiringReminder extends Notification implements ShouldQueue
{
    use DigestAwareNotification;
    use Queueable;

    public function __construct(
        protected Lease $lease,
        protected int $daysLeft,
        protected Tenant $tenant,
    ) {}

    public function toArray(object $notifiable): array
    {
        $dateWord = $this->daysLeft === 0 ? __('today') : __('in :days days', ['days' => $this->daysLeft]);
        $client = $this->lease->buyer?->full_name ?? $this->lease->lead?->full_name ?? __('Client');

        return [
            'type' => 'lease_expiry_reminder',
            'icon' => 'calendar-exclamation',
            'color' => 'orange',
            'title' => __('Lease expiring :date', ['date' => $dateWord]),
            'body' => __('Lease for :client on :unit expires on :date. Contact them about renewing or moving to a different unit.', [
                'client' => $client,
                'unit' => $this->lease->unit_label,
                'date' => $this->lease->contract_end_date->format('M j, Y'),
            ]),
            'url' => url('/leases'),
            'lease_id' => $this->lease->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        \App\Helpers\TenantFormatHelper::setTenant($this->tenant);

        $client = $this->lease->buyer?->full_name ?? $this->lease->lead?->full_name ?? 'Unknown';
        $dateWord = $this->daysLeft === 0 ? 'today' : "in {$this->daysLeft} days";

        return (new MailMessage)
            ->subject("[{$this->tenant->name}] Lease expiring {$dateWord} — action needed")
            ->greeting("Hello {$notifiable->name},")
            ->line("A lease is approaching its expiry date and the client's contract will end on **{$this->lease->contract_end_date->format('M j, Y')}**.")
            ->line("**Client:** {$client}")
            ->line("**Unit:** {$this->lease->unit_label}")
            ->line('**Rent:** '.\App\Helpers\TenantFormatHelper::currency($this->lease->rent_price).' / year')
            ->line('Please contact the client to confirm whether they want to renew or move to a different unit. If they want to move, start a new search for them from the lease.')
            ->action('View Leases', url('/leases'))
            ->line('Thank you for using '.$this->tenant->name.' CRM.');
    }
}
