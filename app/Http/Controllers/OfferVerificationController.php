<?php

namespace App\Http\Controllers;

use App\Services\OfferVerificationService;
use Illuminate\Routing\Controller;

/**
 * The public page a client lands on after scanning the code on an offer letter.
 *
 * Auth-free by design: the point is that a prospect holding a letter can prove
 * it is genuine without having an account, and a landlord receiving a copy from
 * a tenant can check it too.
 *
 * Two distinct failure modes are rendered differently on purpose:
 *
 * - A *tampered* URL (bad or missing signature) never reaches this method at all;
 *   `signed` middleware rejects it. That is the "this is not a Keystone letter"
 *   case and it does not confirm anything.
 * - A correctly signed URL whose token matches nothing means the letter was
 *   withdrawn or deleted. That is reported plainly rather than as a success,
 *   because "we cannot confirm this" must never read like "this is fine".
 */
class OfferVerificationController extends Controller
{
    public function __construct(private OfferVerificationService $verifier) {}

    public function show(string $token)
    {
        $offer = $this->verifier->findByToken($token);

        if (! $offer) {
            return response()->view('offers.verify-unknown', [
                'token' => $token,
            ], 404);
        }

        // A withdrawn or declined letter is not a valid offer any more. Say so
        // rather than printing its figures as if it still stands.
        if (in_array($offer->status, ['withdrawn', 'declined', 'superseded'], true)) {
            return response()->view('offers.verify', [
                'summary' => $this->verifier->publicSummary($offer),
                'statusNote' => __('This offer letter is no longer valid. Please contact the agency that issued it.'),
            ], 410);
        }

        return response()->view('offers.verify', [
            'summary' => $this->verifier->publicSummary($offer),
            'statusNote' => null,
        ]);
    }
}
