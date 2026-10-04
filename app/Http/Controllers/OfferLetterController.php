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
     * The money and term figures a chosen unit implies, for the picker to fill in.
     *
     * Authorised on the deal, like the rest of the letter, and scoped to the
     * tenant inside the service, so this cannot be used to read another agency's
     * inventory or to price a deal the viewer cannot edit.
     */
    public function unitDefaults(Request $request, Deal $deal)
    {
        $this->authorize('update', $deal);

        $validated = $request->validate([
            'unit_id' => 'required|integer',
        ]);

        return response()->json($this->offers->defaultsForUnit($deal, $validated['unit_id']));
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
            // The date printed on the letter. Defaults to today when left blank,
            // and may be backdated to fix up a letter generated after the fact -
            // but never forward-dated, because an offer cannot be issued before
            // the day it exists.
            'issued_at' => 'nullable|date|before_or_equal:today',
            'valid_until' => 'nullable|date',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date',
            // The term in whole years, which derives the end date. Bounded because
            // a "term" of 0 or 40 years is a typo, not a lease.
            'contract_years' => 'nullable|integer|min:1|max:20',
            // How many payments the rent is split into. 1-12: monthly is the
            // ceiling, and a single upfront payment is the floor.
            'payment_period' => 'nullable|integer|min:1|max:12',
            // The unit the offer is written on, chosen from the ones this client
            // has been shown. Scoped to the tenant in applyChosenUnit().
            'unit_id' => 'nullable|integer',
            'documents_required' => 'nullable|string',
            // Unit price as listed.
            'original_amount' => 'required|numeric|min:0',
            // Discount off the listed price; capped to it in buildAmounts so the
            // contract value can never go negative.
            'discount_amount' => 'nullable|numeric|min:0',
            // 'percentage' of the contract value, or a stated 'value'. The two
            // commission inputs are both accepted because only one is visible at
            // a time; the service recomputes from the one the basis selects.
            'commission_basis' => 'nullable|in:percentage,value',
            'commission_rate_pct' => 'nullable|numeric|min:0|max:100',
            'commission_amount' => 'nullable|numeric|min:0',
            // Deliberately not accepted from the request: the VAT rate is the
            // tenant's, read from Tenant::effectiveVatRate(). Letting the form
            // post it back is how a not-registered company ends up charging 5%.
            'security_deposit' => 'nullable|numeric|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'contract_fee' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
    }
}
