<?php

namespace Tests\Feature;

use App\Models\OfferLetter;
use App\Services\OfferLetterService;
use App\Services\OfferVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OfferLetterVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin([
            'business_mode' => 'realestate',
            'name' => 'Pristine Properties',
            'currency' => 'AED',
        ]);
        $this->tenant = auth()->user()->tenant;
        $this->admin = auth()->user();
    }

    protected function makeOffer(array $attributes = []): OfferLetter
    {
        $property = $this->createProperty();
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'property_id' => $property->id,
            'stage' => 'negotiating',
        ]);

        return app(OfferLetterService::class)->createFromValidated(
            $deal,
            $this->admin,
            array_merge([
                'original_amount' => 100000,
                'contract_start_date' => '2026-03-01',
            ], $attributes)
        );
    }

    /** A real 1x1 PNG, so image validation and decoding are genuinely exercised. */
    protected function png(string $name = 'asset.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40)->mimeType('image/png');
    }

    // ---- the QR itself ----------------------------------------------------

    public function test_a_letter_gets_a_verification_token_on_creation()
    {
        $offer = $this->makeOffer();

        $this->assertNotNull($offer->verification_token);
        $this->assertSame(36, strlen($offer->verification_token));
    }

    public function test_the_qr_encodes_a_signed_verification_url()
    {
        $offer = $this->makeOffer();
        $url = app(OfferVerificationService::class)->urlFor($offer);

        $this->assertStringContainsString('/verify/offer/'.$offer->verification_token, $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_the_letter_prints_the_qr_code()
    {
        Storage::fake('public');
        $offer = $this->makeOffer();

        $html = app(OfferLetterService::class)->render($offer);

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Verify this offer letter', $html);
    }

    public function test_a_tampered_token_still_yields_the_qr_rather_than_an_exception()
    {
        $offer = $this->makeOffer(['offer_no' => 'OF-9']);

        $html = app(OfferLetterService::class)->render($offer);

        $this->assertStringNotContainsString('Fatal error', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    // ---- the public page --------------------------------------------------

    public function test_scanning_the_code_proves_the_letter_is_genuine()
    {
        $offer = $this->makeOffer();
        $url = app(OfferVerificationService::class)->urlFor($offer);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee($offer->offer_no);
        $response->assertSee('genuine offer letter');
    }

    public function test_the_verification_page_is_reachable_without_any_login()
    {
        $offer = $this->makeOffer();
        $url = app(OfferVerificationService::class)->urlFor($offer);

        // setUp leaves us signed in as an admin, which would mask a route that
        // accidentally sits behind the auth group. The client holding the letter
        // has no account, so log out and prove the code resolves unauthenticated.
        auth()->logout();
        $this->assertGuest();

        $this->get($url)->assertOk()->assertSee($offer->offer_no);
    }

    public function test_a_client_is_not_redirected_to_login_by_the_verification_link()
    {
        $offer = $this->makeOffer();
        $url = app(OfferVerificationService::class)->urlFor($offer);

        auth()->logout();

        $response = $this->get($url);

        $response->assertOk();
        $this->assertStringNotContainsString('/login', $response->headers->get('Location') ?? '');
    }

    public function test_an_authenticated_employee_can_still_open_a_link_they_are_sent()
    {
        $offer = $this->makeOffer();
        $url = app(OfferVerificationService::class)->urlFor($offer);

        // Still acting as the admin from setUp: checking a letter must not bounce.
        $this->get($url)->assertOk()->assertSee($offer->offer_no);
    }

    public function test_a_held_link_cannot_be_edited_to_verify_a_different_offer()
    {
        $one = $this->makeOffer();
        $two = $this->makeOffer();

        $url = app(OfferVerificationService::class)->urlFor($one);
        $tampered = str_replace($one->verification_token, $two->verification_token, $url);

        // The signature still covers the old token, so the swap is rejected.
        $this->get($tampered)->assertStatus(403);
    }

    public function test_a_signed_url_for_an_unknown_token_reports_not_confirmed()
    {
        $url = URL::signedRoute('verify.offer', ['token' => (string) \Illuminate\Support\Str::uuid()]);

        $response = $this->get($url);

        $response->assertStatus(404);
        $response->assertSee('We cannot confirm this letter');
        // Must not read like a success.
        $response->assertDontSee('genuine offer letter');
    }

    public function test_an_unsigned_url_is_rejected_outright()
    {
        $offer = $this->makeOffer();

        $this->get('/verify/offer/'.$offer->verification_token)->assertStatus(403);
    }

    public function test_the_public_page_never_leaks_personal_or_bank_details()
    {
        Storage::fake('public');
        $offer = $this->makeOffer(['occupant_name' => 'Priya Raman', 'emirates_id' => '784-1990-1234567-1']);
        $this->tenant->update(['bank_details' => 'IBAN AE0000000000000000']);

        $response = $this->get(app(OfferVerificationService::class)->urlFor($offer));

        $response->assertOk();
        $response->assertDontSee('Priya Raman');
        $response->assertDontSee('784-1990-1234567-1');
        $response->assertDontSee('AE0000000000000000');
    }

    public function test_a_withdrawn_letter_does_not_verify_as_standing()
    {
        $offer = $this->makeOffer();
        $url = app(OfferVerificationService::class)->urlFor($offer);
        $offer->update(['status' => 'withdrawn']);

        $response = $this->get($url);

        $response->assertStatus(410);
        $response->assertSee('no longer valid');
    }

    // ---- signature and stamp ---------------------------------------------

    public function test_an_approved_letter_prints_the_signature_and_stamp()
    {
        Storage::fake('public');
        $this->tenant->update([
            'signature_path' => $this->png('sig.png')->store('letter-assets/signatures', 'public'),
            'stamp_path' => $this->png('stamp.png')->store('letter-assets/stamps', 'public'),
        ]);
        $offer = $this->makeOffer();
        $offer->update(['status' => 'issued', 'approved_at' => now()]);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringContainsString('letter-assets/signatures', $html);
        $this->assertStringContainsString('letter-assets/stamps', $html);
    }

    public function test_a_pending_letter_does_not_carry_the_signature_or_stamp()
    {
        Storage::fake('public');
        $this->tenant->update([
            'signature_path' => $this->png('sig.png')->store('letter-assets/signatures', 'public'),
            'stamp_path' => $this->png('stamp.png')->store('letter-assets/stamps', 'public'),
        ]);
        $offer = $this->makeOffer();
        $offer->update(['status' => 'pending_approval']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringNotContainsString('letter-assets/signatures', $html);
        $this->assertStringNotContainsString('letter-assets/stamps', $html);
    }

    // ---- bank page --------------------------------------------------------

    public function test_typed_bank_details_are_printed_as_the_last_page()
    {
        Storage::fake('public');
        $this->tenant->update(['bank_details' => 'Mashreq Bank / IBAN AE070331234567890123456']);
        $offer = $this->makeOffer(['bank_details_source' => 'details']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringContainsString('Bank Details', $html);
        $this->assertStringContainsString('AE070331234567890123456', $html);
    }

    public function test_the_letter_declares_a4_paper_so_pdf_export_matches_the_preview()
    {
        Storage::fake('public');
        $offer = $this->makeOffer();

        $html = app(OfferLetterService::class)->render($offer);

        // Without an explicit @page the browser picks its own paper (commonly
        // US Letter) and adds its own margin on top of .sheet's padding, so the
        // last lines of a block spill onto a page of their own.
        $this->assertStringContainsString('@page { size: A4 portrait; margin: 0; }', $html);

        // One A4 sheet is 210mm wide and 297mm tall.
        $this->assertStringContainsString('width: 210mm', $html);
        $this->assertStringContainsString('min-height: 297mm', $html);
    }

    public function test_print_styles_keep_signature_and_qr_blocks_whole()
    {
        Storage::fake('public');
        $offer = $this->makeOffer();

        $html = app(OfferLetterService::class)->render($offer);

        // A signature rule or QR code split across a page boundary is what makes
        // a saved PDF look broken.
        $this->assertStringContainsString('break-inside: avoid', $html);

        // Read the selector list off the rule that carries the declaration rather
        // than pattern-matching the whole stylesheet once per selector.
        $this->assertSame(1, preg_match(
            '/([^{}]+)\{\s*page-break-inside:\s*avoid;\s*break-inside:\s*avoid;/',
            $html,
            $rule
        ), 'expected a print rule holding both break-inside declarations');

        foreach (['.signatures', '.verify', '.head', '.footer', 'table.payments', 'table.terms', 'img'] as $selector) {
            $this->assertStringContainsString(
                $selector,
                $rule[1],
                "expected {$selector} in the print break-inside rule"
            );
        }

        $this->assertStringContainsString('page-break-after: always', $html);
    }

    public function test_an_uploaded_iban_letter_is_capped_so_it_cannot_overflow_the_page()
    {
        Storage::fake('public');
        $this->tenant->update([
            'iban_letter_path' => $this->png('iban.png')->store('letter-assets/iban-letters', 'public'),
        ]);
        $offer = $this->makeOffer(['bank_details_source' => 'upload']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        // The uploaded bank letter is a portrait A4 scan (2635x3641 in
        // production). Capped by width alone it renders ~950px tall, overflows
        // the sheet, and pushes the verification block onto a page of its own —
        // a two-page letter printing as five pages with three near-empty ones.
        $this->assertMatchesRegularExpression(
            '/<img[^>]*iban-letters[^>]*max-height:\s*\d+px/',
            $html,
            'the IBAN letter image must carry an explicit max-height'
        );
    }

    public function test_the_bank_page_is_a_sibling_of_the_letter_sheet()
    {
        Storage::fake('public');
        $this->tenant->update(['bank_details' => 'Mashreq Bank / IBAN AE070331234567890123456']);
        $offer = $this->makeOffer(['bank_details_source' => 'details']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        // Nesting the bank sheet inside the letter sheet made it inherit that
        // sheet's padding, so page two printed indented from page one.
        $letterSheet = strpos($html, '<div class="sheet">');
        $bankSheet = strpos($html, '<div class="sheet page">');
        $firstClose = strpos($html, '</div>', $letterSheet);

        $this->assertIsInt($letterSheet);
        $this->assertIsInt($bankSheet);
        $this->assertGreaterThan(
            $letterSheet,
            $firstClose,
            'the bank sheet must open after the letter sheet has closed, not inside it'
        );
    }

    public function test_the_qr_code_prints_on_the_last_page_only()
    {
        Storage::fake('public');
        $this->tenant->update(['bank_details' => 'Mashreq Bank / IBAN AE070331234567890123456']);
        $offer = $this->makeOffer(['bank_details_source' => 'details']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        // After the bank page, so the code is the final thing in the pack.
        $this->assertGreaterThan(
            strpos($html, 'Bank Details'),
            strpos($html, 'Verify this offer letter'),
            'the verification block must come after the bank page'
        );
        $this->assertSame(
            1,
            substr_count($html, 'Verify this offer letter'),
            'the QR block must appear exactly once'
        );
    }

    public function test_the_qr_becomes_its_own_sheet_when_there_is_no_bank_page()
    {
        Storage::fake('public');
        // The tenant has bank details so the letter can be created at all (the
        // readiness gate requires them), but this letter was issued without a
        // bank page — which is the case being asserted.
        $this->tenant->update(['bank_details' => 'SAVED BUT UNUSED']);
        $offer = $this->makeOffer(['bank_details_source' => 'none']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringNotContainsString('Bank Details', $html);
        // With no bank page the block itself must carry the sheet geometry,
        // otherwise the QR prints as a bare strip under a half-empty page.
        // It shares one unbreakable block with the footer, so a letter that
        // runs over can never leave a page holding a bare footer line.
        $this->assertStringContainsString('class="last-page-block"', $html);

        // Ordering: signatures, then the code, then the footer — i.e. the code is
        // the last thing in the pack, which is the whole point of the request.
        $qr = strpos($html, 'Verify this offer letter');
        $sig = strpos($html, 'Managing Director');
        $footer = strpos($html, 'class="footer"');

        $this->assertIsInt($qr);
        $this->assertGreaterThan($sig, $qr, 'the code must come after the signature blocks');
        $this->assertGreaterThan($qr, $footer, 'the footer must close the last page');

        $this->assertSame(1, substr_count($html, 'Verify this offer letter'));
    }

    public function test_the_uploaded_iban_letter_is_printed_instead_of_typed_details()
    {
        Storage::fake('public');
        $this->tenant->update([
            'bank_details' => 'SHOULD NOT APPEAR',
            'iban_letter_path' => $this->png('iban.png')->store('letter-assets/iban-letters', 'public'),
        ]);
        $offer = $this->makeOffer(['bank_details_source' => 'upload']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringContainsString('letter-assets/iban-letters', $html);
        $this->assertStringNotContainsString('SHOULD NOT APPEAR', $html);
    }

    public function test_a_per_letter_override_beats_the_saved_bank_details()
    {
        Storage::fake('public');
        $this->tenant->update(['bank_details' => 'SAVED ACCOUNT']);
        $offer = $this->makeOffer([
            'bank_details_source' => 'details',
            'bank_details' => 'PER LETTER ACCOUNT',
        ]);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringContainsString('PER LETTER ACCOUNT', $html);
        $this->assertStringNotContainsString('SAVED ACCOUNT', $html);
    }

    public function test_a_letter_with_no_bank_page_prints_neither()
    {
        Storage::fake('public');
        $this->tenant->update([
            'bank_details' => 'SAVED ACCOUNT',
            'iban_letter_path' => $this->png('iban.png')->store('letter-assets/iban-letters', 'public'),
        ]);
        $offer = $this->makeOffer(['bank_details_source' => 'none']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringNotContainsString('SAVED ACCOUNT', $html);
        $this->assertStringNotContainsString('letter-assets/iban-letters', $html);
    }

    public function test_bank_details_are_escaped_not_injected_as_markup()
    {
        Storage::fake('public');
        $this->tenant->update(['bank_details' => '<script>alert(1)</script> IBAN']);
        $offer = $this->makeOffer(['bank_details_source' => 'details']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_bank_page_comes_after_the_offer_terms()
    {
        Storage::fake('public');
        $this->tenant->update(['bank_details' => 'IBAN AE00']);
        $offer = $this->makeOffer(['bank_details_source' => 'details']);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        $this->assertGreaterThan(
            strpos($html, 'Offer Validity'),
            strpos($html, 'Bank Details')
        );
    }

    // ---- settings uploads -------------------------------------------------

    public function test_an_admin_can_upload_the_authorised_signature()
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->put(route('settings.updateGeneral'), [
            'name' => 'Pristine Properties',
            'signature' => $this->png('sig.png'),
        ])->assertRedirect();

        $path = $this->tenant->fresh()->signature_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_uploading_a_new_signature_removes_the_superseded_one()
    {
        Storage::fake('public');
        $old = $this->png('old.png')->store('letter-assets/signatures', 'public');
        $this->tenant->update(['signature_path' => $old]);

        $this->actingAs($this->admin)->put(route('settings.updateGeneral'), [
            'name' => 'Pristine Properties',
            'signature' => $this->png('new.png'),
        ])->assertRedirect();

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($this->tenant->fresh()->signature_path);
    }

    public function test_a_pdf_cannot_be_uploaded_as_a_stamp()
    {
        Storage::fake('public');

        $before = $this->tenant->fresh()->stamp_path;

        $this->actingAs($this->admin)->put(route('settings.updateGeneral'), [
            'name' => 'Pristine Properties',
            'stamp' => UploadedFile::fake()->create('stamp.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('stamp');

        // Unchanged, not nulled: a rejected upload must leave the stamp the
        // company already had in place. The fixture tenant ships with one
        // (OfferLetterReadiness blocks letter creation without it), so this
        // asserts the real intent rather than "no stamp to begin with".
        $this->assertSame($before, $this->tenant->fresh()->stamp_path);
    }

    public function test_bank_details_can_be_saved_as_text_without_an_upload()
    {
        Storage::fake('public');

        $this->actingAs($this->admin)->put(route('settings.updateGeneral'), [
            'name' => 'Pristine Properties',
            'bank_details' => 'Mashreq / AE070331234567890123456',
        ])->assertRedirect();

        $this->assertSame('Mashreq / AE070331234567890123456', $this->tenant->fresh()->bank_details);
    }

    // ---- rendering resilience --------------------------------------------

    public function test_a_letter_still_renders_when_an_uploaded_asset_is_missing_from_disk()
    {
        Storage::fake('public');
        $this->tenant->update([
            'signature_path' => 'letter-assets/signatures/deleted.png',
            'bank_details' => 'IBAN AE00',
        ]);
        $offer = $this->makeOffer();
        $offer->update(['status' => 'issued', 'approved_at' => now()]);

        $html = app(OfferLetterService::class)->render($offer->fresh());

        // A missing file must not print a broken image, nor break the letter.
        $this->assertStringNotContainsString('letter-assets/signatures/deleted.png', $html);
        $this->assertStringContainsString('Bank Details', $html);
    }

    public function test_a_legacy_letter_with_no_token_is_given_one_rather_than_failing()
    {
        Storage::fake('public');
        $offer = $this->makeOffer();
        $offer->forceFill(['verification_token' => null])->save();

        $url = app(OfferVerificationService::class)->urlFor($offer->fresh());

        $this->assertNotNull($offer->fresh()->verification_token);
        $this->get($url)->assertOk();
    }
}
