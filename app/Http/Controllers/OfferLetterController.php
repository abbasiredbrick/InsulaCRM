<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\OfferLetter;
use App\Services\OfferLetterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Brokerage offer letters (real estate mode).
 *
 * An offer letter turns the final agreed value (annual rent or sales price)
 * into an unsigned document for the client. Once the client signs and the
 * signed copy is uploaded, the offer is 'signed' — the only status that
 * unlocks closing the transaction as Won.
 */
class OfferLetterController extends Controller
{
    public function __construct(protected OfferLetterService $offers) {}

    /**
     * Prepare an offer letter for a deal (values pre-filled from the deal /
     * unit / tenant settings). GET returns the form fields as JSON so the deal
     * page can render the "New Offer Letter" form without a full reload.
     */
    public function create(Request $request, Deal $deal)
    {
        $this->authorize('update', $deal);

        if ($request->expectsJson()) {
            return response()->json($this->offers->buildDefaults($deal));
        }

        return view('offers.create', [
            'deal' => $deal,
            'defaults' => $this->offers->buildDefaults($deal),
        ]);
    }

    /**
     * Issue a new offer letter for the deal. Every offer letter requires
     * manager/admin approval and its status reflects that (pending_approval /
     * issued) depending on who created it.
     */
    public function store(Request $request, Deal $deal)
    {
        $this->authorize('update', $deal);

        $v = $this->validateOffer($request);

        $offer = $this->offers->createFromValidated($deal, auth()->user(), $v);

        $status = __('Offer letter :no issued.', ['no' => $offer->offer_no]);
        if ($offer->status === 'pending_approval') {
            $status = __('Offer letter :no created — Waiting for Approval.', ['no' => $offer->offer_no]);
        }

        return redirect()->route('deals.show', $deal)->with('success', $status);
    }

    /**
     * Render the printable (A4) offer letter for the client to sign. Only an
     * approved letter can be printed by a non-approver; managers/admins may
     * preview it even while it is awaiting approval.
     */
    public function print(OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('view', $deal);

        $user = auth()->user();
        if (! $offerLetter->isApproved() && ! $this->offers->canApproveOffer($user)) {
            abort(403, __('This offer letter is awaiting manager approval.'));
        }

        return response($this->offers->render($offerLetter))
            ->header('Content-Type', 'text/html');
    }

    /**
     * Manager/admin approves a pending offer letter, unblocking printing and
     * signing.
     */
    public function approve(Request $request, OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('update', $deal);

        try {
            $this->offers->approve($offerLetter, auth()->user());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['discount' => $e->getMessage()]);
        }

        \App\Models\AuditLog::log('offer_letter.approved', $offerLetter);

        return redirect()->route('deals.show', $deal)->with('success', __('Offer letter approved — it can now be printed for the client.'));
    }

    /**
     * Store the signed copy of the offer letter. This is what marks the offer
     * as 'signed' and unlocks closing the deal as Won.
     */
    public function uploadSigned(Request $request, OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('update', $deal);

        $request->validate([
            'signed_pdf' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $path = $request->file('signed_pdf')->store(
            "offers/{$offerLetter->tenant_id}/{$deal->id}",
            config('filesystems.default')
        );

        try {
            $this->offers->markSigned($offerLetter, $path, auth()->user());
        } catch (\RuntimeException $e) {
            Storage::disk(config('filesystems.default'))->delete($path);
            throw ValidationException::withMessages(['signed_pdf' => $e->getMessage()]);
        }

        \App\Models\AuditLog::log('offer_letter.signed', $offerLetter, ['signed_pdf' => $path]);

        return redirect()->route('deals.show', $deal)->with('success', __('Signed offer letter recorded — the transaction can now be closed as Won.'));
    }

    /**
     * Download the signed copy of an offer letter.
     */
    public function downloadSigned(OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('view', $deal);

        abort_unless($offerLetter->signed_pdf_path, 404);

        return Storage::disk(config('filesystems.default'))->download($offerLetter->signed_pdf_path);
    }

    /**
     * Mark the offer declined / withdraw it (e.g. the client backed out or the
     * landlord changed the terms). Won is never reached from here.
     */
    public function updateStatus(Request $request, OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('update', $deal);

        $request->validate([
            'status' => 'required|in:declined',
        ]);

        $offerLetter->update(['status' => 'declined', 'declined_at' => now()]);

        \App\Models\AuditLog::log('offer_letter.declined', $offerLetter);

        return redirect()->route('deals.show', $deal)->with('success', __('Offer letter marked as declined.'));
    }

    public function update(Request $request, OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('update', $deal);

        try {
            $this->offers->updateFromValidated($offerLetter, $request->user(), $this->validateOffer($request));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['original_amount' => $e->getMessage()])->withInput();
        }

        \App\Models\AuditLog::log('offer_letter.updated', $offerLetter);

        return redirect()
            ->route('deals.show', $deal)
            ->with('success', $offerLetter->status === 'issued'
                ? __('Offer letter updated and issued.')
                : __('Offer letter updated — waiting for approval.'));
    }

    public function withdraw(Request $request, OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('update', $deal);

        try {
            $this->offers->withdraw($offerLetter, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        \App\Models\AuditLog::log('offer_letter.withdrawn', $offerLetter);

        return redirect()->route('deals.show', $deal)->with('success', __('Offer letter withdrawn.'));
    }

    public function destroy(Request $request, OfferLetter $offerLetter)
    {
        $deal = $offerLetter->deal;
        abort_unless($deal, 404);
        $this->authorize('update', $deal);

        try {
            $this->offers->destroy($offerLetter, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        \App\Models\AuditLog::log('offer_letter.deleted', $offerLetter);

        return redirect()->route('deals.show', $deal)->with('success', __('Offer letter deleted.'));
    }

    protected function validateOffer(Request $request): array
    {
        return $request->validate([
            'offer_no' => 'nullable|string|max:50',
            'valid_until' => 'nullable|date',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date',
            'payment_period' => 'nullable|string|max:100',
            'documents_required' => 'nullable|string',
            'original_amount' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'commission_rate_pct' => 'nullable|numeric|min:0|max:100',
            'commission_vat_pct' => 'nullable|numeric|min:0|max:100',
            'security_deposit' => 'nullable|numeric|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'tawtheeq_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
    }
}
