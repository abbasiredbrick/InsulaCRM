<?php

namespace App\Notifications;

use App\Models\OfferLetter;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Sends the client their own signed copy.
 *
 * The signing page promises a copy by email and invites them to reply to it if
 * anything needs changing, so this has to come from the agency (the tenant's own
 * from-address) and carries a reply-to pointing at the agent who wrote it —
 * otherwise the client replies to a noreply address and the correction goes
 * nowhere.
 *
 * Sent synchronously, like OfferSignatureRequest: the production box runs no
 * queue worker, so a queued copy would arrive never.
 */
class OfferLetterSignedCopy extends Notification
{
    public function __construct(protected OfferLetter $offerLetter) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $offer = $this->offerLetter;
        $tenant = $offer->tenant;
        $agent = $offer->deal?->lead?->agent;

        $mail = (new MailMessage)
            ->subject(__('[:company] Your signed offer letter :no', [
                'company' => $tenant?->name,
                'no' => $offer->offer_no,
            ]))
            ->greeting(__('Hello :name,', ['name' => $offer->occupant_signer_name ?: $offer->occupant_name ?: __('there')]))
            ->line(__('Thank you for signing. This is your copy of offer letter :no.', ['no' => $offer->offer_no]));

        if ($signedAt = $offer->signed_at) {
            $mail->line(__('Signed :when', ['when' => $signedAt->format('F j, Y \a\t H:i')]));
        }

        // "Reply to this email" is on the page the client just came from, so it
        // has to reach the person who can actually act on it.
        if ($agent?->email) {
            $mail->replyTo($agent->email, $agent->name);
        }

        $attachment = $this->signedCopy();

        if ($attachment !== null) {
            $mail->attach($attachment['path'], [
                'mime' => $attachment['mime'],
                'as' => __('Offer letter :no (signed).pdf', ['no' => $offer->offer_no]),
            ]);
        } else {
            // No file to hand over — a signature drawn on a phone is an image,
            // not a document. Saying so beats attaching a PNG of a squiggle.
            $mail->line(__('A PDF copy was not uploaded for this signature, so there is no file attached. If you need a printed copy, just reply to this email and we will send one.'));
        }

        return $mail
            ->line(__('If anything needs changing, please reply to this email.'));
    }

    /**
     * The signed document to attach, or null when there is none.
     *
     * A wet-signed upload is the document in its own right. A signature drawn on
     * a phone leaves only a PNG behind, which is not a letter.
     */
    protected function signedCopy(): ?array
    {
        $path = $this->offerLetter->signed_pdf_path;

        if (blank($path) || ! Storage::disk(config('filesystems.default'))->exists($path)) {
            return null;
        }

        return [
            'path' => Storage::disk(config('filesystems.default'))->path($path),
            'mime' => $this->mimeFor($path),
        ];
    }

    protected function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/pdf',
        };
    }
}
