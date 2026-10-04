<?php

namespace App\Notifications;

use App\Models\OfferLetter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In-app + email notification to the agent once their offer letter has been
 * approved by the manager, so they know it can be printed / sent for signature.
 *
 * Deliberately synchronous (no ShouldQueue) so notifications persist
 * immediately on the production server (no queue worker).
 */
class OfferLetterApproved extends Notification
{
    public function __construct(
        protected OfferLetter $offerLetter,
        protected User $approver,
        protected Tenant $tenant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $deal = $this->offerLetter->deal;
        $leadName = $deal?->lead?->full_name ?? $this->offerLetter->lead?->full_name ?? '';

        return [
            'type' => 'offer_letter_approved',
            'icon' => 'check',
            'color' => 'green',
            'title' => __('Offer letter approved'),
            'body' => __('Offer :no was approved by :manager — you can now print it for the client.', [
                'no' => $this->offerLetter->offer_no,
                'manager' => $this->approver->name,
            ]),
            'url' => $deal ? url('/pipeline/'.$deal->id) : url('/pipeline'),
            'deal_id' => $deal?->id,
            'offer_letter_id' => $this->offerLetter->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deal = $this->offerLetter->deal;
        $leadName = $deal?->lead?->full_name ?? $this->offerLetter->lead?->full_name ?? $deal?->title ?? '';

        return (new MailMessage)
            ->subject('[Keystone] Offer letter '.$this->offerLetter->offer_no.' approved')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your offer letter has been approved by '.$this->approver->name.'.')
            ->line('**Offer:** '.$this->offerLetter->offer_no)
            ->line('**Lead / Transaction:** '.$leadName)
            ->action('View Transaction', $deal ? url('/pipeline/'.$deal->id) : url('/pipeline'))
            ->line('You can now print the offer letter and send it to the client for signature.');
    }
}
