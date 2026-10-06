<?php

namespace App\Notifications;

use App\Models\OfferLetter;
use App\Models\OfferLetterChangeRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tell a manager that an agent wants to change an already-approved letter.
 */
class OfferLetterChangeRequested extends Notification
{
    public function __construct(
        public OfferLetter $offerLetter,
        public OfferLetterChangeRequest $changeRequest,
        public User $requester,
        public Tenant $tenant
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Change requested on offer letter :no', ['no' => $this->offerLetter->offer_no]))
            ->greeting(__('Hello :name', ['name' => $notifiable->name]))
            ->line(__(':name has asked to change an offer letter that is already approved.', ['name' => $this->requester->name]))
            ->line(__('Offer letter: :no (:amount)', [
                'no' => $this->offerLetter->offer_no,
                'amount' => \App\Helpers\TenantFormatHelper::currency((float) $this->offerLetter->approved_amount),
            ]))
            ->line(__('Reason given:'))
            ->line($this->changeRequest->reason)
            ->action(__('Review the request'), route('deals.show', $this->offerLetter->deal_id))
            ->line(__('Approving unlocks the edit; the letter then has to be approved again before it can be sent.'));

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'offer_letter_id' => $this->offerLetter->id,
            'change_request_id' => $this->changeRequest->id,
            'offer_no' => $this->offerLetter->offer_no,
        ];
    }
}
