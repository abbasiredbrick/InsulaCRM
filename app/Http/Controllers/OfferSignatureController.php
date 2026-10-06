<?php

namespace App\Http\Controllers;

use App\Models\OfferLetter;
use App\Services\OfferLetterService;
use App\Services\OfferSignatureRequestService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The public page an occupant opens to sign an approved offer letter.
 *
 * Auth-free and signed: the client has no account, and the link proves both that
 * we issued it and which letter it belongs to.
 *
 * Three outcomes are kept strictly distinct, because collapsing any two of them
 * would let someone agree to a letter that is not live:
 *
 * - open link, unsigned -> show the letter and the signing pad
 * - already signed       -> thank them, show the signed copy, no second signature
 * - no/expired/revoked token -> say plainly that the link is closed
 */
class OfferSignatureController extends Controller
{
    public function __construct(
        private OfferSignatureRequestService $requests,
        private OfferLetterService $offers,
    ) {}

    public function show(string $token)
    {
        $letter = $this->findByToken($token);

        if (! $letter) {
            return response()->view('offers.sign-closed', ['reason' => 'unknown'], 404);
        }

        if ($letter->isSigned()) {
            return response()->view('offers.sign-done', [
                'offer' => $letter,
                'signedAt' => $letter->signed_at,
                'signerName' => $letter->occupant_signer_name,
            ]);
        }

        // An expired link is refused even though the signature on the URL is
        // still valid: the link was real, it has simply aged out.
        if ($letter->signatureRequestIsExpired()) {
            return response()->view('offers.sign-closed', ['reason' => 'expired'], 410);
        }

        if (! $letter->signatureRequestIsOpen()) {
            return response()->view('offers.sign-closed', ['reason' => 'revoked'], 410);
        }

        return view('offers.sign', [
            'offer' => $letter,
            'letterHtml' => $this->offers->render($letter),
            'signerName' => $letter->occupant_name ?: $letter->lead?->full_name,
            'submitUrl' => $this->requests->submitUrl($letter),
            'expiresAt' => $letter->signature_request_expires_at,
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $letter = $this->findByToken($token);

        if (! $letter) {
            return response()->view('offers.sign-closed', ['reason' => 'unknown'], 404);
        }

        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'consent' => ['accepted'],
            // Deliberately NOT named `signature`: a signed URL carries its own
            // `?signature=` query parameter, and Laravel's validator merges query
            // into the input. With the field called `signature`, an upload-only
            // submission picked up the URL's hash as the drawn capture and was
            // rejected as unreadable.
            'signature_data' => ['nullable', 'string', 'max:'.OfferSignatureRequestService::MAX_DRAWN_BYTES],
            'signed_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'message' => ['nullable', 'string', 'max:5000'],
        ], [
            'consent.accepted' => __('Please confirm that you agree to sign this letter.'),
            'signer_name.required' => __('Please type your full name as you sign it.'),
        ]);

        $hasDrawn = filled($data['signature_data'] ?? null);
        $hasUpload = $request->hasFile('signed_file');

        if (! $hasDrawn && ! $hasUpload) {
            throw ValidationException::withMessages([
                'signature' => __('Please sign the letter, or upload a signed copy.'),
            ]);
        }

        $uploadedPath = null;
        if ($hasUpload) {
            $uploadedPath = $request->file('signed_file')->store(
                "offers/{$letter->tenant_id}/{$letter->deal_id}",
                config('filesystems.default')
            );
        }

        try {
            $this->requests->recordSignature(
                $letter,
                $data['signer_name'],
                $hasDrawn ? $data['signature_data'] : null,
                $uploadedPath,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ValidationException $e) {
            // Never leave a stored upload behind for a submission that failed —
            // otherwise a rejected attempt still leaves a file on the letter.
            if ($uploadedPath) {
                Storage::disk(config('filesystems.default'))->delete($uploadedPath);
            }

            throw $e;
        }

        // Straight to the confirmation, never back through the signing form: a
        // signed letter must not look like something still awaiting signature.
        //
        // The signed URL, not a plain route(): a bare route() drops the `signature`
        // query parameter and the `signed` middleware then rejects the
        // confirmation with a 403 — the client sees an error page moments after
        // agreeing to the letter.
        return redirect()->to($this->requests->signUrl($letter))
            ->with('signed', true);
    }

    protected function findByToken(string $token): ?OfferLetter
    {
        return OfferLetter::query()
            ->where('signature_request_token', $token)
            ->with(['tenant', 'deal.lead', 'lead'])
            ->first();
    }
}
