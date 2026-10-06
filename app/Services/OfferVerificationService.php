<?php

namespace App\Services;

use App\Models\OfferLetter;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Lets a client prove an offer letter is genuine, by scanning a code on it.
 *
 * The QR encodes a *signed* URL carrying an unguessable token. The signature
 * means the URL cannot be edited to point at a different offer, and the token
 * means offers cannot be enumerated — a client holding one letter learns nothing
 * about anyone else's.
 *
 * The QR is emitted as inline SVG rather than a PNG so it stays crisp at any
 * print resolution, costs no extra HTTP request, and needs no image extension on
 * the server.
 */
class OfferVerificationService
{
    public const ROUTE = 'verify.offer';

    /**
     * The token a letter is verified by, minted on first use.
     *
     * Legacy rows are backfilled by migration, so this is belt-and-braces for a
     * letter created outside the normal path.
     */
    public function tokenFor(OfferLetter $offer): string
    {
        if (blank($offer->verification_token)) {
            $offer->forceFill(['verification_token' => (string) Str::uuid()])->save();
        }

        return $offer->verification_token;
    }

    /**
     * The absolute, signed URL the QR encodes.
     */
    public function urlFor(OfferLetter $offer): string
    {
        return URL::signedRoute(self::ROUTE, ['token' => $this->tokenFor($offer)]);
    }

    /**
     * Inline SVG for the letter's verification block.
     *
     * Returns an empty string rather than throwing: a QR code is a convenience,
     * and losing the ability to print a letter because a code would not encode
     * would be a far worse outcome than printing it without the block.
     */
    public function qrSvgFor(OfferLetter $offer): string
    {
        try {
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(170, 1),
                new SvgImageBackEnd
            ));

            // Error correction M: this gets scanned off a printed page in poor
            // light, often at an angle, and a damaged code that fails to scan is
            // worse than no code at all.
            return $writer->writeString(
                $this->urlFor($offer),
                Encoder::DEFAULT_BYTE_MODE_ENCODING,
                ErrorCorrectionLevel::M()
            );
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * Look up a letter from a scanned code, or null when it is not ours.
     *
     * The URL signature is checked by `signed` middleware on the route, so this
     * only has to find the row.
     */
    public function findByToken(string $token): ?OfferLetter
    {
        return OfferLetter::query()
            ->where('verification_token', $token)
            ->first();
    }

    /**
     * The facts a holder of the letter is entitled to see.
     *
     * Deliberately not the client's name, the occupant's Emirates ID or the
     * bank details: enough to prove the document is real, and no more, because
     * the verification URL travels in whatever way the holder chooses to share it.
     */
    public function publicSummary(OfferLetter $offer): array
    {
        $offer->loadMissing(['tenant', 'deal']);

        return [
            'offer_no' => $offer->offer_no,
            'issued_at' => optional($offer->issued_at)->format('F j, Y'),
            'deal_type' => $offer->deal?->dealType() === 'sale' ? 'sale' : 'rent',
            'approved_amount' => (float) $offer->approved_amount,
            'currency' => $offer->tenant?->currency ?? 'AED',
            'company' => $offer->tenant?->name,
            'status' => $offer->status,
            'is_genuine' => true,
        ];
    }
}
