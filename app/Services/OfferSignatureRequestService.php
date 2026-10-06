<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\OfferLetter;
use App\Models\User;
use App\Notifications\OfferLetterSignedCopy;
use App\Notifications\OfferSignatureRequest;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends an approved offer letter to the occupant to sign from their phone.
 *
 * The whole flow is built around one idea: the signing link is a *capability*,
 * not a receipt. It is signed (so it cannot be edited into a link for a
 * different letter), it expires, and every resend rotates it — so an old email
 * that gets forwarded does not hand a stranger a live signing right.
 *
 * That is why this token is not the verification token used by
 * OfferVerificationService. Those two have deliberately opposite lifecycles:
 * verification must survive forever, signing must die quickly.
 */
class OfferSignatureRequestService
{
    public const ROUTE = 'offers.sign';

    /** How long a signing link stays open. Long enough for a weekend. */
    public const EXPIRY_HOURS = 72;

    /**
     * A drawn signature is posted as a data URL. This caps both the payload and
     * the decoded file, so a hostile client cannot post a 50MB "PNG".
     */
    public const MAX_DRAWN_BYTES = 512 * 1024;

    public function __construct(private OfferLetterService $offers) {}

    /**
     * Issue (or re-issue) a signing link and email it to the occupant.
     *
     * Returns the URL so the agent can copy it into WhatsApp when the lead has
     * no email on file — the link is identical either way.
     */
    public function request(OfferLetter $offer, User $actor, array $options = []): string
    {
        if (! $offer->canRequestSignature()) {
            throw ValidationException::withMessages([
                'signature' => $offer->isSigned()
                    ? __('This offer letter has already been signed.')
                    : __('Only an approved offer letter can be sent for signature.'),
            ]);
        }

        $lead = $this->leadFor($offer);
        $email = $options['email'] ?? $lead?->email;
        $isResend = $offer->signatureRequestIsSent();

        // Rotate on every send. A link already sitting in someone's inbox stops
        // working the moment a fresh one is issued, which is the only way a
        // resend can be a genuine reset rather than a second live key.
        $expiresAt = now()->addHours((int) ($options['expires_hours'] ?? self::EXPIRY_HOURS));

        $offer->update([
            'signature_request_token' => (string) Str::uuid(),
            'signature_requested_at' => now(),
            'signature_requested_by' => $actor->id,
            'signature_request_expires_at' => $expiresAt,
            'signature_reminded_at' => $isResend ? now() : null,
            'signature_request_email' => $email,
        ]);

        $url = $this->signUrl($offer->refresh());

        Activity::create([
            'tenant_id' => $offer->tenant_id,
            'lead_id' => $offer->lead_id,
            'deal_id' => $offer->deal_id,
            'agent_id' => $actor->id,
            'type' => 'note',
            'subject' => $isResend ? __('Signature request resent') : __('Offer letter sent for signature'),
            'body' => $isResend
                ? __('A new signing link for offer letter :no was sent. The previous link no longer works.', ['no' => $offer->offer_no])
                : __('Offer letter :no was sent to the client for signature.', ['no' => $offer->offer_no]),
            'logged_at' => now(),
        ]);

        if (filled($email)) {
            $offer->loadMissing(['tenant', 'deal.lead', 'deal.unit']);

            Notification::route('mail', $email)->notify(new OfferSignatureRequest(
                $offer->refresh(),
                $lead,
                $url,
                (string) ($options['message'] ?? ''),
                $expiresAt,
            ));
        }

        return $url;
    }

    /**
     * The signed, expiring URL the occupant opens.
     *
     * Signed rather than merely tokenised: the signature proves the link was
     * minted by us, so the token in it cannot be swapped for another letter's.
     */
    public function signUrl(OfferLetter $offer): string
    {
        $expiry = $offer->signature_request_expires_at
            ? $offer->signature_request_expires_at
            : now()->addHours(self::EXPIRY_HOURS);

        // Note the argument order: temporarySignedRoute($name, $expiration,
        // $parameters) — the expiration comes *before* the parameters, unlike
        // signedRoute($name, $parameters, $expiration). Passing them the other way
        // round silently drops the token from the path and puts the expiry
        // timestamp there instead.
        return URL::temporarySignedRoute(
            self::ROUTE,
            $expiry,
            ['token' => $offer->signature_request_token]
        );
    }

    /**
     * A signed URL for the POST that records the signature.
     *
     * Separate from the GET link on purpose: the signature covers the request
     * URL, so posting to the unsigned route name would fail the `signed`
     * check even though the client arrived on a perfectly valid link.
     */
    public function submitUrl(OfferLetter $offer): string
    {
        $expiry = $offer->signature_request_expires_at
            ?: now()->addHours(self::EXPIRY_HOURS);

        return URL::temporarySignedRoute(
            'offers.sign.submit',
            $expiry,
            ['token' => $offer->signature_request_token]
        );
    }

    /**
     * Kill a live signing link.
     *
     * Called whenever the letter stops being signable — withdrawn, declined, or
     * edited back to editable — so a link that is already sitting in an inbox
     * cannot be used to sign a letter whose terms have moved on.
     */
    public function revoke(OfferLetter $offer): void
    {
        if (blank($offer->signature_request_token)) {
            return;
        }

        $offer->forceFill(['signature_request_token' => null])->save();
    }

    /**
     * Record the occupant's signature and mark the letter signed.
     *
     * `$signatureDataUrl` is a canvas capture; `$uploadedPath` is a photo or a
     * PDF of a wet-signed copy. Exactly one is expected, and the drawn path is
     * preferred because it is the common phone case.
     *
     * The signing token is deliberately left in place. Clearing it sent the
     * client who had just signed to a link that no longer resolved, so they saw
     * "this link is closed" immediately after agreeing to the letter. Replay is
     * prevented by the status check in signatureRequestIsOpen() instead, which
     * is a stronger guarantee anyway: a signed letter cannot be signed twice.
     */
    public function recordSignature(
        OfferLetter $offer,
        string $signerName,
        ?string $signatureDataUrl,
        ?string $uploadedPath,
        ?string $ip,
        ?string $userAgent,
    ): OfferLetter {
        if (! $offer->signatureRequestIsOpen()) {
            throw ValidationException::withMessages([
                'signature' => __('This signing link is no longer valid. Ask for a new one to be sent.'),
            ]);
        }

        [$method, $path] = $signatureDataUrl
            ? ['drawn', $this->storeDrawnSignature($offer, $signatureDataUrl)]
            : ['uploaded', $uploadedPath];

        // An uploaded wet-signed copy is the signed document in its own right, so
        // it becomes the letter's signed PDF and the existing download button
        // works. A drawn signature has no PDF, so it stays null and the letter is
        // rendered with the captured mark instead.
        $this->offers->markSigned($offer, $method === 'uploaded' ? $uploadedPath : null, null);

        $offer->update([
            'occupant_signature_method' => $method,
            'occupant_signature_path' => $path,
            'occupant_signer_name' => $signerName,
            'occupant_signed_at' => now(),
            'occupant_signed_ip' => $ip,
            'occupant_signed_ua' => $userAgent ? Str::limit($userAgent, 255, '') : null,
            // Hashed after signing so it captures the letter as it stood at the
            // moment of agreement, including the agency seal then in force.
            'occupant_content_hash' => $this->contentHash($offer->refresh()),
        ]);

        Activity::create([
            'tenant_id' => $offer->tenant_id,
            'lead_id' => $offer->lead_id,
            'deal_id' => $offer->deal_id,
            'agent_id' => null,
            'type' => 'note',
            'subject' => __('Offer letter signed by client'),
            'body' => __(':name signed offer letter :no (:method).', [
                'name' => $signerName,
                'no' => $offer->offer_no,
                'method' => $method === 'drawn' ? __('drawn on phone') : __('uploaded copy'),
            ]),
            'logged_at' => now(),
        ]);

        $this->sendSignedCopy($offer->refresh());

        return $offer->refresh();
    }

    /**
     * Send the client their signed copy, as the page they just came from promises.
     *
     * Two things have to be true for this to work, and neither is obvious:
     *
     *  - The tenant's SMTP settings must be applied by hand. This is a public
     *    route with no session, so TenantMiddleware never ran, and the .env
     *    default on the production box is MAIL_MAILER=log.
     *  - A failure must never surface to the client. Their signature is already
     *    recorded and the letter is binding; losing a courtesy copy because the
     *    mail server was down must not turn a completed signing into an error
     *    page that invites them to try again.
     */
    protected function sendSignedCopy(OfferLetter $offer): void
    {
        // Where the link went is the address that proved it was really them.
        $email = $offer->signature_request_email;

        if (blank($email)) {
            return;
        }

        try {
            app(TenantMailConfigurator::class)->apply($offer->tenant);

            Notification::route('mail', $email)
                ->notify(new OfferLetterSignedCopy($offer));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * SHA-256 of the letter exactly as it will be printed with the signature on it.
     *
     * Recorded so a later dispute can be settled by hashing the letter again: if
     * the company later changes its logo or bank details, the hash changes and
     * the mismatch is visible rather than silent.
     */
    public function contentHash(OfferLetter $offer): string
    {
        return hash('sha256', $this->offers->render($offer));
    }

    /**
     * Decode and store a canvas capture.
     *
     * The data URL is validated as a real PNG by re-encoding it rather than by
     * trusting its prefix — the string arrives from the browser, so its
     * `data:image/png;base64,` header is a claim, not evidence.
     */
    protected function storeDrawnSignature(OfferLetter $offer, string $dataUrl): string
    {
        $binary = $this->decodePngDataUrl($dataUrl);

        // The path is built here rather than taken from put()'s return value:
        // FilesystemAdapter::put() returns a bool, so trusting it stored "1" in
        // the column and the signature then never rendered on the letter.
        $path = "offer-signatures/{$offer->tenant_id}/{$offer->id}/".Str::uuid().'.png';

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    protected function decodePngDataUrl(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
            throw ValidationException::withMessages([
                'signature' => __('The signature could not be read. Please sign again.'),
            ]);
        }

        $binary = base64_decode(preg_replace('/\s+/', '', $m[1]) ?: '', true);

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'signature' => __('The signature could not be read. Please sign again.'),
            ]);
        }

        if (strlen($binary) > self::MAX_DRAWN_BYTES) {
            throw ValidationException::withMessages([
                'signature' => __('The signature image is too large. Please sign again.'),
            ]);
        }

        // Confirms the bytes really are a PNG, so nothing else can be stored in
        // the signature slot.
        $info = @getimagesizefromstring($binary);
        if (! $info || ($info['mime'] ?? null) !== 'image/png') {
            throw ValidationException::withMessages([
                'signature' => __('The signature could not be read. Please sign again.'),
            ]);
        }

        return $binary;
    }

    protected function leadFor(OfferLetter $offer): ?Lead
    {
        return $offer->deal?->lead ?: $offer->lead;
    }
}
