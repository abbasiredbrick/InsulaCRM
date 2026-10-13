<?php

namespace App\Notifications;

use App\Models\Property;
use App\Models\Tenant;
use App\Traits\DigestAwareNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PermitExpiryReminder extends Notification implements ShouldQueue
{
    use DigestAwareNotification;
    use Queueable;

    public function __construct(
        protected Property $property,
        protected int $daysLeft,
        protected Tenant $tenant,
    ) {}

    public function toArray(object $notifiable): array
    {
        $dateWord = $this->dateWord();

        return [
            'type' => 'permit_expiry_reminder',
            'icon' => 'calendar-exclamation',
            'color' => $this->daysLeft <= 1 ? 'red' : 'orange',
            'title' => __('Listing permit expiring :date', ['date' => $dateWord]),
            'body' => __('The :permit (:no) for :unit expires on :date. Renew it so the unit stays live on Bayut, Dubizzle and Property Finder.', [
                'permit' => $this->property->permit_label,
                'no' => $this->property->rera_permit_no ?: '—',
                'unit' => $this->property->display_name,
                'date' => $this->property->permit_expires_at?->format('M j, Y') ?? '—',
            ]),
            'url' => url('/inventory/'.$this->property->id),
            'property_id' => $this->property->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        \App\Helpers\TenantFormatHelper::setTenant($this->tenant);

        $dateWord = $this->dateWord();

        return (new MailMessage)
            ->subject("[Keystone] Listing permit for {$this->property->display_name} expiring {$dateWord} — renew to stay listed")
            ->greeting("Hello {$notifiable->name},")
            ->line('A listing permit is approaching its expiry date and the unit will drop off the portals once it lapses.')
            ->line('**Permit:** '.$this->property->permit_label.' — '.($this->property->rera_permit_no ?: '—'))
            ->line('**Unit:** '.$this->property->display_name)
            ->line('**Expires:** '.($this->property->permit_expires_at?->format('M j, Y') ?? '—'))
            ->line('Please renew the permit so the listing stays live on Bayut, Dubizzle and Property Finder. Re-issuing a lapsed permit is more costly and the unit is unpublished in the meantime.')
            ->action('View Unit', url('/inventory/'.$this->property->id))
            ->line('Thank you for using '.$this->tenant->name.' CRM.');
    }

    protected function dateWord(): string
    {
        if ($this->daysLeft < 0) {
            return __(':days days ago', ['days' => abs($this->daysLeft)]);
        }

        return $this->daysLeft === 0
            ? __('today')
            : __('in :days days', ['days' => $this->daysLeft]);
    }
}
