<?php

namespace Tests\Feature;

use App\Models\OfferLetter;
use App\Services\OfferLetterService;
use App\Services\OfferSignatureRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OfferSignatureRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function approvedOffer(array $attributes = []): OfferLetter
    {
        // createTenantWithAdmin is not idempotent and tenants.slug is unique, so
        // only stand one up the first time. Tests that need two letters call
        // this twice and must share the tenant.
        if (! isset($this->tenant)) {
            $this->actingAsAdmin(['business_mode' => 'realestate']);
        }
        $property = $this->createProperty();
        $deal = $this->createDeal(['deal_type' => 'rent', 'property_id' => $property->id]);

        $offer = app(OfferLetterService::class)->createFromValidated($deal, auth()->user(), [
            'original_amount' => 100000,
            'contract_start_date' => '2026-03-01',
        ]);

        // createFromValidated issues directly for an admin; force the shape the
        // feature actually cares about rather than depending on that.
        $offer->update(array_merge(['status' => 'issued', 'approved_at' => now()], $attributes));

        return $offer->refresh();
    }

    /**
     * A real PNG encoded through GD.
     *
     * Built here rather than pasted as base64 because the service re-decodes the
     * payload and checks it is genuinely a PNG — a hard-coded blob that is
     * subtly wrong fails the test for the wrong reason.
     */
    protected function drawnSignature(): string
    {
        $image = imagecreatetruecolor(120, 40);
        $ink = imagecolorallocate($image, 17, 17, 17);
        imageline($image, 10, 30, 40, 10, $ink);
        imageline($image, 40, 10, 70, 30, $ink);
        imageline($image, 70, 30, 110, 12, $ink);

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($binary);
    }

    /** Sign through the same path a phone client takes. Returns the signed_at. */
    protected function signViaPhone(OfferLetter $offer): \Carbon\Carbon
    {
        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ]);

        return $offer->refresh()->signed_at;
    }

    protected function requestSignature(OfferLetter $offer, array $payload = []): string
    {
        return app(OfferSignatureRequestService::class)->request(
            $offer,
            auth()->user(),
            $payload
        );
    }

    // ---- sending the link -------------------------------------------------

    public function test_an_approved_letter_can_be_sent_for_signature()
    {
        $offer = $this->approvedOffer();

        $url = $this->requestSignature($offer);

        $offer->refresh();

        $this->assertNotNull($offer->signature_request_token);
        $this->assertNotNull($offer->signature_requested_at);
        $this->assertTrue($offer->signatureRequestIsOpen());
        $this->assertStringContainsString('/offer/'.$offer->signature_request_token.'/sign', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_the_client_is_emailed_the_signing_link()
    {
        Notification::fake();

        $offer = $this->approvedOffer();
        $offer->lead->update(['email' => 'client@example.com']);

        $this->requestSignature($offer);

        Notification::assertSentOnDemand(
            \App\Notifications\OfferSignatureRequest::class,
            function ($notification, $channels, $notifiable) {
                return true;
            }
        );
    }

    public function test_a_pending_letter_cannot_be_sent_for_signature()
    {
        $offer = $this->approvedOffer(['status' => 'pending_approval', 'approved_at' => null]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->requestSignature($offer);
    }

    public function test_an_already_signed_letter_cannot_be_sent_again()
    {
        $offer = $this->approvedOffer(['status' => 'signed', 'signed_at' => now()]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->requestSignature($offer);
    }

    public function test_resending_rotates_the_token_so_the_old_email_stops_working()
    {
        $offer = $this->approvedOffer();
        $first = $this->requestSignature($offer);
        $oldToken = $offer->refresh()->signature_request_token;

        $second = $this->requestSignature($offer);

        $this->assertNotSame($oldToken, $offer->refresh()->signature_request_token);
        $this->assertNotSame($first, $second);

        // The superseded link is now dead rather than a second live key.
        $this->get($first)->assertStatus(404);
        $this->get($second)->assertOk();
    }

    public function test_an_agent_can_send_the_link_by_whatsapp_when_there_is_no_email()
    {
        $offer = $this->approvedOffer();
        $offer->lead->update(['email' => null]);

        $url = $this->requestSignature($offer);

        // Same link either way; the agent pastes it into WhatsApp.
        $this->get($url)->assertOk();
    }

    // ---- the signing page -------------------------------------------------

    public function test_the_client_opens_the_link_without_logging_in_and_sees_the_letter()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);

        auth()->logout();

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee($offer->offer_no);
        $response->assertSee('Sign with your finger');
        // The letter itself is embedded in the page, so what they agree to is
        // on screen rather than something they have to go and download.
        $response->assertSee('Offer Validity');
        $response->assertSee($offer->offer_no);
    }

    public function test_the_signing_link_does_not_bounce_a_signed_in_agent_to_login()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);

        $this->get($url)->assertOk();
    }

    public function test_an_unsigned_link_cannot_be_forged()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->get('/offer/'.$offer->refresh()->signature_request_token.'/sign')->assertStatus(403);
    }

    public function test_a_link_with_the_token_swapped_for_another_letter_is_rejected()
    {
        $one = $this->approvedOffer();
        $two = $this->approvedOffer();
        $urlOne = $this->requestSignature($one);
        $urlTwo = $this->requestSignature($two);

        $tampered = str_replace(
            $two->refresh()->signature_request_token,
            $one->refresh()->signature_request_token,
            $urlTwo
        );

        $this->get($tampered)->assertStatus(403);
    }

    public function test_an_expired_link_is_closed_rather_than_left_usable()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer, ['expires_hours' => 24]);
        $offer->update(['signature_request_expires_at' => now()->subMinute()]);

        // The URL signature is still valid; the token has aged out.
        $response = $this->get($url);

        $response->assertStatus(410);
        $response->assertSee('This signing link is closed');
    }

    public function test_withdrawing_the_letter_kills_a_live_signing_link()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);
        $token = $offer->refresh()->signature_request_token;

        $this->post(route('deal.offers.withdraw', $offer));

        $this->assertNull($offer->refresh()->signature_request_token);
        $this->get($url)->assertStatus(404);
    }

    public function test_declining_the_letter_kills_a_live_signing_link()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);

        $this->patch(route('deal.offers.status', $offer), ['status' => 'declined']);

        $this->get($url)->assertStatus(404);
    }

    public function test_closing_the_link_from_the_deal_page_kills_it()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);

        $this->post(route('deal.offers.revokeSignature', $offer))->assertRedirect(route('deals.show', $offer->deal));

        $this->get($url)->assertStatus(404);
    }

    // ---- signing ----------------------------------------------------------

    public function test_the_client_can_draw_a_signature_and_the_letter_becomes_signed()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);
        $token = $offer->refresh()->signature_request_token;

        $response = $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ]);

        // Straight back to a *signed* confirmation URL, not a bare route() that
        // would lose the signature and bounce the client off a 403.
        $response->assertRedirect($url);

        $offer->refresh();

        $this->assertSame('signed', $offer->status);
        $this->assertNotNull($offer->signed_at);
        $this->assertSame('drawn', $offer->occupant_signature_method);
        $this->assertSame('Priya Raman', $offer->occupant_signer_name);
        $this->assertNotNull($offer->occupant_signature_path);
        Storage::disk('public')->assertExists($offer->occupant_signature_path);
    }

    public function test_the_signature_is_recorded_with_the_audit_trail()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ]);

        $offer->refresh();

        $this->assertNotNull($offer->occupant_signed_at);
        $this->assertNotNull($offer->occupant_signed_ip);
        $this->assertNotNull($offer->occupant_content_hash);
        $this->assertSame(64, strlen($offer->occupant_content_hash));
    }

    public function test_a_wet_signed_copy_can_be_uploaded_instead()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signed_file' => UploadedFile::fake()->image('signed.jpg'),
        ]);

        $offer->refresh();

        $this->assertSame('signed', $offer->status);
        $this->assertSame('uploaded', $offer->occupant_signature_method);
        $this->assertNotNull($offer->signed_pdf_path);
    }

    public function test_signing_advances_the_deal_as_a_wet_signature_does()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ]);

        $this->assertSame('signed', $offer->refresh()->deal->refresh()->offerLetters()->first()->status);
    }

    public function test_a_signed_link_cannot_be_replayed_to_sign_twice()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);
        $signedAt = $this->signViaPhone($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Someone Else',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ])->assertSessionHasErrors();

        $offer->refresh();

        $this->assertSame('signed', $offer->status);
        $this->assertSame($signedAt->timestamp, $offer->signed_at->timestamp);
        // The replay must not overwrite the original signature either.
        $this->assertSame('Priya Raman', $offer->occupant_signer_name);
    }

    public function test_the_client_lands_on_a_confirmation_rather_than_a_dead_link()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ])->assertRedirect($url);

        $this->get($url)->assertOk()->assertSee('Thank you');
    }

    // ---- validation -------------------------------------------------------

    public function test_signing_without_consent_is_refused()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'signature_data' => $this->drawnSignature(),
        ])->assertSessionHasErrors('consent');

        $this->assertNotSame('signed', $offer->refresh()->status);
    }

    public function test_signing_without_a_name_is_refused()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ])->assertSessionHasErrors('signer_name');
    }

    public function test_submitting_neither_a_signature_nor_an_upload_is_refused()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
        ])->assertSessionHasErrors('signature');

        $this->assertNotSame('signed', $offer->refresh()->status);
    }

    public function test_a_forged_signature_payload_is_refused()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        // A data URL that claims to be a PNG but is a PHP script.
        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => 'data:image/png;base64,'.base64_encode('<?php echo "pwned"; ?>'),
        ])->assertSessionHasErrors('signature');

        $this->assertNotSame('signed', $offer->refresh()->status);
    }

    public function test_a_rejected_submission_leaves_no_upload_behind_on_the_letter()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            // An upload plus a corrupt capture: the capture is what fails, and
            // the stored upload must not survive it.
            'signature_data' => 'data:image/png;base64,'.base64_encode('not a png'),
            'signed_file' => UploadedFile::fake()->image('signed.jpg'),
        ])->assertSessionHasErrors('signature');

        $this->assertNull($offer->refresh()->signed_pdf_path);
    }

    // ---- rendering --------------------------------------------------------

    public function test_the_signed_letter_shows_the_clients_signature()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);
        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ]);

        $html = app(OfferLetterService::class)->render($offer->refresh());

        $this->assertStringContainsString('offer-signatures', $html);
        $this->assertStringContainsString('Priya Raman', $html);
        // The Tenant/Purchaser rule is replaced by the captured mark. Scoped to
        // that block: the agency's own blank rule is unrelated and stays put.
        $this->assertMatchesRegularExpression(
            '/Client signature.*Tenant \/ Purchaser/s',
            $html
        );
    }

    public function test_an_unsigned_letter_shows_a_blank_signature_rule()
    {
        $offer = $this->approvedOffer();

        $html = app(OfferLetterService::class)->render($offer);

        $this->assertStringContainsString('Name / Signature / Date', $html);
    }

    // ---- agent UI ---------------------------------------------------------

    public function test_the_deal_page_offers_to_send_an_approved_letter_for_signature()
    {
        $offer = $this->approvedOffer();

        $this->get(route('deals.show', $offer->deal))
            ->assertOk()
            ->assertSee('Send for Signature');
    }

    public function test_the_deal_page_shows_the_link_is_awaiting_signature()
    {
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);

        $this->get(route('deals.show', $offer->deal->fresh()))
            ->assertOk()
            ->assertSee('Awaiting signature');
    }

    public function test_the_deal_page_reports_a_client_signed_copy()
    {
        Storage::fake('public');
        $offer = $this->approvedOffer();
        $this->requestSignature($offer);
        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ]);

        $this->get(route('deals.show', $offer->deal->fresh()))
            ->assertOk()
            ->assertSee('Signed by client');
    }

    public function test_an_agent_cannot_send_an_unapproved_letter_for_signature()
    {
        $offer = $this->approvedOffer(['status' => 'pending_approval', 'approved_at' => null]);

        $this->post(route('deal.offers.requestSignature', $offer))
            ->assertSessionHasErrors('signature');

        $this->assertNull($offer->refresh()->signature_request_token);
    }

    // ---- the signing pad (client-side) ----------------------------------

    public function test_a_drawn_signature_is_serialised_into_the_submission()
    {
        // Regression. The pad answered "has this been signed?" by reading the
        // hidden field — but that field is only filled in *during* submit, so it
        // always said no. The canvas was therefore never serialised, the server
        // received an empty signature_data, and a client who had plainly signed
        // was told "Please sign the letter, or upload a signed copy."
        $offer = $this->approvedOffer();
        $html = $this->get($this->requestSignature($offer))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('hasInk = true', $html, 'the pad must track ink as it is drawn');
        $this->assertStringContainsString("field.value = pad.toDataURL('image/png')", $html);
        $this->assertStringNotContainsString("field.value !== ''", $html, 'the hidden field is not the record of a signature');
    }

    public function test_the_submit_button_is_labelled_plainly()
    {
        // "Submit Signature" told the client they were submitting a signature
        // object rather than the letter.
        $offer = $this->approvedOffer();

        $this->get($this->requestSignature($offer))
            ->assertOk()
            ->assertSee('Submit')
            ->assertDontSee('Submit Signature');
    }

    public function test_the_public_offer_pages_carry_pwa_and_safe_area_markup()
    {
        $offer = $this->approvedOffer();
        $url = $this->requestSignature($offer);

        // `signed` middleware, so the verification page needs a minted signature.
        $verifyUrl = URL::temporarySignedRoute('verify.offer', now()->addHour(), ['token' => $offer->verification_token]);

        foreach ([$url, $verifyUrl] as $page) {
            $this->get($page)
                ->assertOk()
                // Renders like an app rather than a stray web page: theme colour
                // for the Android address bar, icons for the iOS home screen.
                ->assertSee('rel="manifest"', false)
                ->assertSee('theme-color', false)
                ->assertSee('apple-touch-icon', false)
                // viewport-fit=cover needs matching insets, or the home
                // indicator sits on top of the submit button.
                ->assertSee('viewport-fit=cover', false)
                ->assertSee('safe-area-inset-bottom', false);
        }
    }

    // ---- the client's own copy -------------------------------------------

    public function test_the_client_is_emailed_their_signed_copy()
    {
        Notification::fake();

        $offer = $this->approvedOffer();
        $offer->lead->update(['email' => 'client@example.com']);

        $this->requestSignature($offer);
        $this->signViaPhone($offer);

        Notification::assertSentTo(
            new \Illuminate\Notifications\AnonymousNotifiable,
            \App\Notifications\OfferLetterSignedCopy::class
        );
    }

    public function test_the_signed_copy_goes_to_the_address_the_link_was_sent_to()
    {
        Notification::fake();

        $offer = $this->approvedOffer();

        // A different address from the lead's: whoever was sent the link is
        // whoever proved they were the client.
        $this->requestSignature($offer, ['email' => 'signer@example.com']);
        $this->signViaPhone($offer);

        Notification::assertSentTo(
            new \Illuminate\Notifications\AnonymousNotifiable,
            \App\Notifications\OfferLetterSignedCopy::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['mail'] === 'signer@example.com';
            }
        );
    }

    public function test_a_wet_signed_upload_is_attached_and_a_drawn_signature_is_not()
    {
        Notification::fake();

        $offer = $this->approvedOffer();
        $this->requestSignature($offer, ['email' => 'signer@example.com']);
        $this->signViaPhone($offer);

        // A drawn signature is an image of a squiggle, not a document — the mail
        // has to say so rather than attach a PNG of a signature.
        Notification::assertSentTo(
            new \Illuminate\Notifications\AnonymousNotifiable,
            \App\Notifications\OfferLetterSignedCopy::class,
            function ($notification) {
                $mail = $notification->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

                return empty($mail->attachments)
                    && str_contains(implode("\n", $mail->introLines), 'no file attached');
            }
        );

        Notification::fake();

        $uploaded = $this->approvedOffer();
        $this->requestSignature($uploaded, ['email' => 'signer@example.com']);

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($uploaded->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signed_file' => UploadedFile::fake()->create('wet-signed.pdf', 200, 'application/pdf'),
        ]);

        Notification::assertSentTo(
            new \Illuminate\Notifications\AnonymousNotifiable,
            \App\Notifications\OfferLetterSignedCopy::class,
            function ($notification) {
                $mail = $notification->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

                // The stored file carries a generated name; the name the client
                // sees is the 'as' label.
                return count($mail->attachments) === 1
                    && str_contains((string) ($mail->attachments[0]['options']['as'] ?? ''), 'signed');
            }
        );
    }

    public function test_the_signed_copy_carries_a_reply_to_for_the_agent_who_wrote_it()
    {
        Notification::fake();

        $offer = $this->approvedOffer();
        $this->requestSignature($offer, ['email' => 'signer@example.com']);

        $agent = $this->adminUser;
        $offer->deal->update(['agent_id' => $agent->id]);
        $offer->lead->update(['agent_id' => $agent->id]);

        $this->signViaPhone($offer->refresh());

        Notification::assertSentTo(
            new \Illuminate\Notifications\AnonymousNotifiable,
            \App\Notifications\OfferLetterSignedCopy::class,
            function ($notification) use ($agent) {
                $mail = $notification->toMail(new \Illuminate\Notifications\AnonymousNotifiable);

                // "Reply to this email" is on the page the client just read, so
                // it has to land with a person who can act.
                // replyTo holds [address, name] pairs.
                return collect($mail->replyTo)->contains(fn ($pair) => ($pair[0] ?? null) === $agent->email);
            }
        );
    }

    public function test_a_mail_failure_never_fails_the_signing()
    {
        Notification::fake();

        $offer = $this->approvedOffer();
        $this->requestSignature($offer, ['email' => 'signer@example.com']);

        // The signature is already binding; a courtesy copy must not turn a
        // completed signing into an error page.
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP down'));

        $this->post(app(OfferSignatureRequestService::class)->submitUrl($offer->refresh()), [
            'signer_name' => 'Priya Raman',
            'consent' => '1',
            'signature_data' => $this->drawnSignature(),
        ])->assertRedirect();

        $this->assertNotNull($offer->refresh()->signed_at);
    }

    public function test_tenant_mail_settings_are_applied_outside_the_authenticated_session()
    {
        // The signing route has no session, so TenantMiddleware never runs. If the
        // tenant's own SMTP settings are not applied by hand the client falls
        // back to the .env default — which on prod is MAIL_MAILER=log, and their
        // copy silently never arrives.
        $offer = $this->approvedOffer();

        config(['mail.default' => 'log']);

        $this->tenant->update(['mail_settings' => [
            'mail_host' => 'smtp.agency.test',
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_username' => 'agency',
            'mail_password' => encrypt('secret'),
            'mail_from_address' => 'offers@agency.test',
            'mail_from_name' => 'Agency',
        ]]);

        $applied = app(\App\Services\TenantMailConfigurator::class)->apply($this->tenant->fresh());

        $this->assertTrue($applied);
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.agency.test', config('mail.mailers.smtp.host'));
        $this->assertSame('secret', config('mail.mailers.smtp.password'), 'stored encrypted, sent decrypted');
        $this->assertSame('offers@agency.test', config('mail.from.address'));
    }

    public function test_a_tenant_with_no_mail_settings_is_left_on_the_env_defaults()
    {
        $this->approvedOffer();

        $this->tenant->update(['mail_settings' => null]);
        config(['mail.default' => 'log']);

        $this->assertFalse(app(\App\Services\TenantMailConfigurator::class)->apply($this->tenant->fresh()));
        $this->assertSame('log', config('mail.default'));
    }
}
