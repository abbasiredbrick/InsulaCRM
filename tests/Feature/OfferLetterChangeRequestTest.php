<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OfferLetterChangeRequested;
use App\Notifications\OfferLetterChangeReviewed;
use App\Services\OfferLetterService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who may change the terms of an offer letter, and how an agent gets an
 * approved letter unlocked.
 *
 * The rule under test: an issued letter's price and terms are locked, because a
 * manager approved them and the client may be holding them. A manager can
 * always edit (that is the authority they already hold); anyone else has to
 * ask; a *signed* letter is closed to everyone.
 */
class OfferLetterChangeRequestTest extends TestCase
{
    private function agentRoleId(): int
    {
        return Role::where('name', 'agent')->first()->id;
    }

    /**
     * An agent with a manager, plus an issued (approved, unsigned) sale letter
     * on their deal. Returns [agent, manager, offer].
     */
    private function issuedOfferOnAgentDeal(bool $withManager = true): array
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $manager = null;
        if ($withManager) {
            $manager = User::factory()->create([
                'tenant_id' => $this->tenant->id,
                'role_id' => $this->agentRoleId(),
                'is_active' => true,
            ]);
        }

        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->agentRoleId(),
            'is_active' => true,
            'reports_to' => $manager?->id,
        ]);

        $deal = Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead(['agent_id' => $agent->id])->id,
            'agent_id' => $agent->id,
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        $this->actingAs($agent);
        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000])
            ->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();
        $this->assertSame('pending_approval', $offer->status);

        $this->actingAs($manager ?? $this->adminUser);
        $this->post(route('deal.offers.approve', $offer))->assertRedirect(route('deals.show', $deal));

        $this->assertSame('issued', $offer->fresh()->status);

        return [$agent, $manager, $offer->fresh()];
    }

    public function test_an_agent_cannot_edit_the_terms_of_an_approved_offer(): void
    {
        [$agent, , $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->patch(route('deal.offers.update', $offer), [
            'original_amount' => 750000,
        ])->assertSessionHasErrors();

        $this->assertSame(500000.0, (float) $offer->fresh()->approved_amount);
    }

    public function test_a_manager_can_edit_an_approved_offer_directly(): void
    {
        [, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($manager)->patch(route('deal.offers.update', $offer), [
            'original_amount' => 750000,
        ])->assertSessionHasNoErrors();

        $offer->refresh();

        // Re-authorised in the same step, by the same person — so it stays
        // issued rather than bouncing back through approval.
        $this->assertSame(750000.0, (float) $offer->approved_amount);
        $this->assertSame('issued', $offer->status);
        $this->assertSame($manager->id, $offer->approved_by);
    }

    public function test_managers_are_offered_no_change_request(): void
    {
        [, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($manager)
            ->post(route('deal.offers.requestChange', $offer), ['reason' => 'Landlord changed the price.'])
            ->assertSessionHasErrors('change_request');

        $this->assertSame(0, $offer->changeRequests()->count());
    }

    public function test_an_agent_can_request_a_change_and_their_manager_is_notified(): void
    {
        Notification::fake();

        [$agent, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), [
            'reason' => 'Landlord reduced the rent after the second viewing.',
        ])->assertRedirect(route('deals.show', $offer->deal));

        $request = $offer->changeRequests()->sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame($agent->id, $request->requested_by);
        $this->assertNull($request->reviewed_at);

        Notification::assertSentTo($manager, OfferLetterChangeRequested::class);
    }

    public function test_a_reason_is_required(): void
    {
        [$agent, , $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)
            ->post(route('deal.offers.requestChange', $offer), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $offer->changeRequests()->count());
    }

    public function test_only_one_request_may_be_pending_at_a_time(): void
    {
        [$agent, , $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'First ask.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'Second ask.'])
            ->assertSessionHasErrors('change_request');

        $this->assertSame(1, $offer->changeRequests()->count());
    }

    public function test_an_agent_cannot_review_their_own_request(): void
    {
        [$agent, , $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'Please.'])
            ->assertSessionHasNoErrors();

        $request = $offer->changeRequests()->sole();

        $this->actingAs($agent)
            ->post(route('deal.offers.reviewChange', $request), ['decision' => 'approve'])
            ->assertSessionHasErrors('change_request');

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_rejecting_a_request_requires_a_note_and_leaves_the_letter_locked(): void
    {
        Notification::fake();

        [$agent, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'Cheaper elsewhere.'])
            ->assertSessionHasNoErrors();

        $request = $offer->changeRequests()->sole();

        // No note: the agent would have no idea what to do next.
        $this->actingAs($manager)
            ->post(route('deal.offers.reviewChange', $request), ['decision' => 'reject'])
            ->assertSessionHasErrors('note');

        $this->actingAs($manager)
            ->post(route('deal.offers.reviewChange', $request), [
                'decision' => 'reject',
                'note' => 'The client already agreed this figure.',
            ])->assertRedirect(route('deals.show', $offer->deal));

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame($manager->id, $request->reviewed_by);

        // Still issued, still at the old price, still not editable by the agent.
        $this->assertSame('issued', $offer->fresh()->status);
        $this->assertSame(500000.0, (float) $offer->fresh()->approved_amount);

        Notification::assertSentTo($agent, OfferLetterChangeReviewed::class, fn ($n) => $n->approved === false);
    }

    public function test_approving_a_request_reopens_the_letter_and_clears_the_prior_approval(): void
    {
        [$agent, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'Landlord agreed a reduction.'])
            ->assertSessionHasNoErrors();

        $request = $offer->changeRequests()->sole();

        $this->actingAs($manager)
            ->post(route('deal.offers.reviewChange', $request), ['decision' => 'approve'])
            ->assertRedirect(route('deals.show', $offer->deal));

        $offer->refresh();

        // Approving the *request* unlocks the edit; it does not approve the new
        // figures, so the old approval must not survive.
        $this->assertSame('pending_approval', $offer->status);
        $this->assertNull($offer->approved_by);
        $this->assertNull($offer->approved_at);

        // And the agent can now make the change they described.
        $this->actingAs($agent)->patch(route('deal.offers.update', $offer), [
            'original_amount' => 450000,
        ])->assertSessionHasNoErrors();

        $offer->refresh();
        $this->assertSame(450000.0, (float) $offer->approved_amount);
        $this->assertSame('pending_approval', $offer->status);
    }

    public function test_approving_a_request_revokes_any_live_signing_link(): void
    {
        [$agent, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->post(route('deal.offers.requestSignature', $offer), [
            'signature_email' => 'client@example.test',
        ])->assertRedirect();

        $offer->refresh();
        $this->assertNotNull($offer->signature_request_token);

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'Wrong figures sent.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->post(route('deal.offers.reviewChange', $offer->changeRequests()->sole()), ['decision' => 'approve'])
            ->assertRedirect();

        // The client was sent the *old* terms; the link must not survive the
        // letter becoming editable again.
        $this->assertNull($offer->fresh()->signature_request_token);
    }

    public function test_a_request_cannot_be_reviewed_twice(): void
    {
        [$agent, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), ['reason' => 'Please unlock.'])
            ->assertSessionHasNoErrors();

        $request = $offer->changeRequests()->sole();

        $this->actingAs($manager)
            ->post(route('deal.offers.reviewChange', $request), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        $this->actingAs($manager)
            ->post(route('deal.offers.reviewChange', $request), ['decision' => 'reject', 'note' => 'Too late.'])
            ->assertSessionHasErrors('change_request');
    }

    /**
     * An issued offer letter on a *rent* deal, which is the only kind of deal
     * whose page renders the offer-letter panel at all — a sale deal shows the
     * wholesale _offers panel instead. Returns [agent, manager, offer].
     */
    private function issuedOfferOnRentDeal(): array
    {
        [$agent, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $deal = $offer->deal;
        $deal->update(['deal_type' => 'rent', 'stage' => 'deal_won']);

        return [$agent, $manager, $offer->fresh()];
    }

    public function test_a_manager_is_shown_the_edit_button_on_an_approved_letter(): void
    {
        [, $manager, $offer] = $this->issuedOfferOnRentDeal();

        // The service allowed this edit all along; the button that reveals the
        // form was gated on a narrower condition, so a manager saw no way in.
        $this->assertTrue(
            app(OfferLetterService::class)->canEditTerms($offer, $manager),
            'precondition: the service permits a manager to edit an issued letter'
        );

        $response = $this->actingAs($manager)->get(route('deals.show', $offer->deal));

        $response->assertOk();
        $response->assertSee('data-bs-target="#offer-edit-'.$offer->id.'"', false);
    }

    public function test_an_agent_is_not_shown_the_edit_button_on_an_approved_letter(): void
    {
        [$agent, , $offer] = $this->issuedOfferOnRentDeal();

        $response = $this->actingAs($agent)->get(route('deals.show', $offer->deal));

        $response->assertOk();
        // They get the request-a-change door instead, never a dead edit button.
        $response->assertDontSee('data-bs-target="#offer-edit-'.$offer->id.'"', false);
    }

    public function test_the_edit_button_is_withdrawn_from_a_signed_letter(): void
    {
        [, $manager, $offer] = $this->issuedOfferOnRentDeal();

        $offer->update(['status' => 'signed', 'signed_at' => now()]);

        $response = $this->actingAs($manager)->get(route('deals.show', $offer->deal));

        $response->assertOk();
        $response->assertDontSee('data-bs-target="#offer-edit-'.$offer->id.'"', false);
    }

    public function test_a_signed_letter_can_be_edited_by_nobody_including_a_manager(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $deal = Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
        ]);
        $deal->lead->update(['status' => 'active_client']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000])
            ->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();
        $this->assertSame('issued', $offer->status, 'an admin-issued offer is approved on the spot');

        $offer->update(['status' => 'signed', 'signed_at' => now()]);

        // Editing the figures would rewrite a document the client has signed.
        $this->patch(route('deal.offers.update', $offer), ['original_amount' => 750000])
            ->assertSessionHasErrors();

        // And there is nothing to request a change on either.
        $this->post(route('deal.offers.requestChange', $offer), ['reason' => 'Wrong price.'])
            ->assertSessionHasErrors('change_request');

        $this->assertSame(500000.0, (float) $offer->fresh()->approved_amount);
    }

    public function test_a_request_on_an_unapproved_letter_is_refused(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->agentRoleId(),
            'is_active' => true,
        ]);

        $deal = Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead(['agent_id' => $agent->id])->id,
            'agent_id' => $agent->id,
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        $this->actingAs($agent);
        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000]);

        $offer = $deal->offerLetters()->first();
        $this->assertSame('pending_approval', $offer->status);

        // Already editable — a request would be a pointless extra hop.
        $this->post(route('deal.offers.requestChange', $offer), ['reason' => 'Let me change it.'])
            ->assertSessionHasErrors('change_request');

        $this->assertSame(0, $offer->changeRequests()->count());
    }

    public function test_can_edit_terms_reflects_the_same_rules(): void
    {
        [, $manager, $offer] = $this->issuedOfferOnAgentDeal();

        $service = app(OfferLetterService::class);

        $this->assertTrue($service->canEditTerms($offer, $manager));
        $this->assertFalse($service->canEditTerms($offer, $offer->deal->lead->agent));

        $offer->update(['status' => 'signed', 'signed_at' => now()]);
        $this->assertFalse($service->canEditTerms($offer, $manager));

        $offer->update(['status' => 'pending_approval', 'signed_at' => null]);
        $this->assertTrue($service->canEditTerms($offer, $offer->deal->lead->agent));
    }

    public function test_the_change_request_reason_is_recorded_on_the_activity_trail(): void
    {
        [$agent, , $offer] = $this->issuedOfferOnAgentDeal();

        $this->actingAs($agent)->post(route('deal.offers.requestChange', $offer), [
            'reason' => 'Landlord reduced to 450k.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activities', [
            'deal_id' => $offer->deal_id,
            'agent_id' => $agent->id,
            'subject' => 'Change requested on offer letter',
        ]);
    }
}
