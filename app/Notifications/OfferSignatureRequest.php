<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\OfferLetter;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the occupant to sign an approved offer letter from their phone.
 *
 * Sent synchronously, not queued: the production box runs no queue worker (see
 * OfferLetterApproved), and a signing link that arrives a day late is worse than
 * useless — the client has usually moved on by then.
 */
class OfferSignatureRequest extends Notification
{
    public function __construct(
        protected OfferLetter $offerLetter,
        protected ?Lead $lead,
        protected string $signUrl,
        protected string $message,
        protected CarbonInterface $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->offerLetter->tenant;
        $name = $this->lead?->first_name ?: __('there');

        $mail = (new MailMessage)
            ->subject(__('[:company] Please sign your offer letter :no', [
                'company' => $tenant->name,
                'no' => $this->offerLetter->offer_no,
            ]))
            ->greeting(__('Hello :name,', ['name' => $name]))
            ->line(__(':company has approved an offer letter for you.', ['company' => $tenant->name]))
            ->line(__('Offer: :no', ['no' => $this->offerLetter->offer_no]));

        if (filled($this->message)) {
            $mail->line($this->message);
        }

        $mail->action(__('Review and Sign'), $this->signUrl)
            ->line(__('You can read the letter and sign it with your finger on your phone. No printing, no scanning.'))
            ->line(__('This link works until :time. If it expires, reply to this email and we will send a new one.', [
                'time' => $this->expiresAt->format('F j, Y \a\t H:i'),
            ]))
            ->line(__('If you did not expect this email, you can safely ignore it.'));

        return $mail;
    }
}
