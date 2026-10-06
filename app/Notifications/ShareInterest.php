<?php

namespace App\Notifications;

use App\Helpers\TenantFormatHelper;
use App\Models\Lead;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the agent assigned to a unit when a client browsing the shared
 * availability link registers interest in it. Sent synchronously (no queue) so
 * it always lands even on shared hosting.
 */
class ShareInterest extends Notification
{
    use Queueable;

    public function __construct(
        protected Lead $lead,
        protected Property $property,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $unit = $this->property;

        $rent = $unit->rent_price
            ? TenantFormatHelper::currency($unit->rent_price).' / '.__(\App\Models\Property::RENT_PERIODS[$unit->rent_period] ?? $unit->rent_period)
            : __('Price on request');

        return (new MailMessage)
            ->subject('[Keystone] New interest: '.$unit->display_name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('A client browsing the shared availability link just registered interest in one of your units.')
            ->line('**Unit:** '.$unit->display_name)
            ->line('**Location:** '.($unit->sub_community ?: ($unit->community ?: 'N/A')))
            ->line('**Rent:** '.$rent)
            ->line('**Client:** '.$lead->full_name)
            ->line('**Phone:** '.($lead->phone ?: 'N/A'))
            ->line('**Email:** '.($lead->email ?: 'N/A'))
            ->action('View Lead', url('/leads/'.$lead->id))
            ->line('Follow up with this client to turn their interest into a viewing.');
    }
}
