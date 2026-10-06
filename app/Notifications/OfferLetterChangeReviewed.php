<?php

namespace App\Notifications;

use App\Models\OfferLetter;
use App\Models\OfferLetterChangeRequest;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tell the agent whether their change request was approved or turned down.
 *
 * Sent in both cases on purpose: a request that is quietly rejected leaves the
 * agent refreshing a deal page waiting for an unlock that is never coming.
 */
class OfferLetterChangeReviewed extends Notification
{
    public function __construct(
        public OfferLetter $offerLetter,
        public OfferLetterChangeRequest $changeRequest,
        public bool $approved,
        public User $reviewer
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->approved
                ? __('Change approved — offer letter :no', ['no' => $this->offerLetter->offer_no])
                : __('Change rejected — offer letter :no', ['no' => $this->offerLetter->offer_no]))
            ->greeting(__('Hello :name', ['name' => $notifiable->name]));

        if ($this->approved) {
            $mail->line(__(':name approved your change to offer letter :no.', [
                'name' => $this->reviewer->name,
                'no' => $this->offerLetter->offer_no,
            ]))
                ->line(__('The letter is editable again. Make your changes, then have it approved before sending it to the client.'));
        } else {
            $mail->line(__(':name rejected your change to offer letter :no.', [
                'name' => $this->reviewer->name,
                'no' => $this->offerLetter->offer_no,
            ]));

            if (filled($this->changeRequest->review_note)) {
                $mail->line(__('Their note:'))->line($this->changeRequest->review_note);
            }
        }

        return $mail->action(__('Open the offer letter'), route('deals.show', $this->offerLetter->deal_id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'offer_letter_id' => $this->offerLetter->id,
            'change_request_id' => $this->changeRequest->id,
            'approved' => $this->approved,
        ];
    }
}
