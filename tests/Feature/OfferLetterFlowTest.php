<?php

namespace Tests\Feature;

use App\Models\OfferLetter;
use App\Models\User;
use App\Notifications\OfferLetterApprovalRequired;
use App\Notifications\OfferLetterApproved;
use App\Services\OfferLetterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OfferLetterFlowTest extends TestCase
{
    private function reAdmin(): TestCase
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function saleDeal(float $contractPrice): \App\Models\Deal
    {
        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => $contractPrice,
        ]);
        $deal->lead->update(['status' => 'active_client']);

        return $deal;
    }

    private function rentDeal(float $annualRental): \App\Models\Deal
    {
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'moved_in',
            'contract_price' => $annualRental,
        ]);
        $deal->lead->update(['status' => 'active_client']);

        return $deal;
    }

    private function issueOffer(\App\Models\Deal $deal, array $payload = []): \App\Models\OfferLetter
    {
        $this->post(route('deal.offers.store', $deal), array_merge([
            'original_amount' => (float) $deal->contract_price ?: 500000,
        ], $payload))->assertRedirect(route('deals.show', $deal));

        return $deal->offerLetters()->first();
    }

    private function signOffer(\App\Models\OfferLetter $offer): \App\Models\OfferLetter
    {
        $this->post(route('deal.offers.uploadSigned', $offer), [
            'signed_pdf' => UploadedFile::fake()->create('signed.pdf', 500, 'application/pdf'),
        ])->assertRedirect();

        return $offer->fresh();
    }

    /**
     * An offer that genuinely sits in 'pending_approval'. Creating one as an
     * admin would self-approve it, so the status is forced afterwards to
     * reproduce what an agent's letter actually looks like.
     */
    private function pendingOffer(\App\Models\Deal $deal, array $payload = [], ?User $creator = null): \App\Models\OfferLetter
    {
        $offer = app(OfferLetterService::class)->createFromValidated(
            $deal,
            $creator ?? auth()->user(),
            array_merge(['original_amount' => (float) $deal->contract_price ?: 500000], $payload),
        );

        $offer->update([
            'status' => 'pending_approval',
            'approved_by' => null,
            'approved_at' => null,
            'discount_approved_by' => null,
            'discount_approved_at' => null,
        ]);

        return $offer->fresh();
    }

    public function test_store_creates_offer_with_expected_amounts_and_activity(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'security_deposit' => 10000,
            'admin_fee' => 5250,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $this->assertNotNull($offer);
        $this->assertInstanceOf(OfferLetter::class, $offer);
        $this->assertEquals('issued', $offer->status);
        $this->assertEquals(500000.0, (float) $offer->original_amount);
        $this->assertEquals(500000.0, (float) $offer->approved_amount);
        $this->assertEquals(10000.0, (float) $offer->commission_amount, '2% sales commission.');
        // This tenant is not VAT registered, so the 5% rate sitting in settings
        // is deliberately not applied.
        $this->assertEquals(0.0, (float) $offer->commission_vat);
        $this->assertEquals(10000.0, (float) $offer->commission_total);
        $this->assertStringContainsString('OL-', $offer->offer_no);
        $this->assertDatabaseHas('activities', ['lead_id' => $deal->lead_id, 'subject' => 'Offer letter issued']);
    }

    public function test_vat_is_added_to_every_service_when_the_company_is_registered(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate', 'is_vat_registered' => true]);

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'discount_amount' => 50000,
            'security_deposit' => 10000,
            'admin_fee' => 5250,
            'contract_fee' => 2200,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        // Contract value is the discounted figure; the sale value itself carries
        // no VAT at all.
        $this->assertEquals(500000.0, (float) $offer->original_amount);
        $this->assertEquals(50000.0, (float) $offer->discount_amount);
        $this->assertEquals(450000.0, (float) $offer->approved_amount);

        // Commission is 2% of the contract value, not of the listed price.
        $this->assertEquals(9000.0, (float) $offer->commission_amount);
        $this->assertEquals(450.0, (float) $offer->commission_vat);
        $this->assertEquals(9450.0, (float) $offer->commission_total);

        // Admin fee and contract fee are services too, so they carry VAT.
        $this->assertEquals(5250.0, (float) $offer->admin_fee);
        $this->assertEquals(262.5, (float) $offer->admin_fee_vat);
        $this->assertEquals(5512.5, (float) $offer->admin_fee_total);
        $this->assertEquals(2200.0, (float) $offer->contract_fee);
        $this->assertEquals(110.0, (float) $offer->contract_fee_vat);
        $this->assertEquals(2310.0, (float) $offer->contract_fee_total);

        $this->assertEquals(5.0, (float) $offer->vatRate());
        $this->assertTrue($offer->chargesVat());
        $this->assertEquals(822.5, $offer->totalVat());
    }

    public function test_no_vat_is_added_to_the_fees_when_the_company_is_not_registered(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'admin_fee' => 5250,
            'contract_fee' => 2200,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $this->assertEquals(5250.0, (float) $offer->admin_fee);
        $this->assertEquals(0.0, (float) $offer->admin_fee_vat);
        $this->assertEquals(5250.0, (float) $offer->admin_fee_total);
        $this->assertEquals(2200.0, (float) $offer->contract_fee);
        $this->assertEquals(0.0, (float) $offer->contract_fee_vat);
        $this->assertEquals(2200.0, (float) $offer->contract_fee_total);
        $this->assertEquals(0.0, (float) $offer->commission_vat);
        $this->assertFalse($offer->chargesVat());
        $this->assertEquals(0.0, $offer->totalVat());
    }

    public function test_a_discount_cannot_exceed_the_listed_price(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'discount_amount' => 900000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        // Clamped, not rejected: a contract value below zero would make the
        // commission negative and the letter nonsensical.
        $this->assertEquals(500000.0, (float) $offer->discount_amount);
        $this->assertEquals(0.0, (float) $offer->approved_amount);
        // The commission falls back to the listed price rather than to zero: a
        // 100% discount must not quietly make the agency fee vanish.
        $this->assertEquals(10000.0, (float) $offer->commission_amount);
    }

    public function test_commission_can_be_entered_as_a_stated_value(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        // A negotiated flat fee: the percentage would say 10,000 on a 500k sale.
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'commission_basis' => 'value',
            'commission_rate_pct' => 2,
            'commission_amount' => 7500,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $this->assertEquals('value', $offer->commission_basis);
        $this->assertTrue($offer->isCommissionOnValue());
        $this->assertEquals(7500.0, (float) $offer->commission_amount);
        $this->assertEquals(7500.0, (float) $offer->commission_total, 'No VAT: tenant not registered.');
    }

    public function test_the_posted_vat_rate_is_ignored(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        // A forged form post tries to add 5% VAT to an unregistered company.
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'commission_vat_pct' => 5,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $this->assertEquals(0.0, (float) $offer->commission_vat_pct);
        $this->assertEquals(0.0, (float) $offer->commission_vat);
    }

    public function test_the_offer_letter_prints_the_three_price_tiers_and_no_vat_on_the_value(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate', 'is_vat_registered' => true]);

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed', 'contract_price' => 120000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 120000,
            'discount_amount' => 10000,
            'admin_fee' => 5250,
            'contract_fee' => 2200,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $html = $this->get(route('deal.offers.print', $offer))->assertOk()->getContent();

        $this->assertStringContainsString('Unit Price (as listed)', $html);
        $this->assertStringContainsString('Discount Value', $html);
        $this->assertStringContainsString('Contract Value', $html);
        $this->assertStringContainsString('Contract Fee', $html);
        $this->assertStringContainsString('Admin Fee', $html);
        $this->assertStringContainsString('VAT @ 5%', $html);
        $this->assertStringContainsString('Agency Services', $html);

        // The old wording is gone.
        $this->assertStringNotContainsString('Tawtheeq Fee', $html);
        $this->assertStringNotContainsString('Admin Fee + VAT', $html);
    }

    public function test_offer_with_discount_from_agent_goes_pending_until_manager_approves(): void
    {
        $agent = $this->actingAsRole('agent', ['business_mode' => 'realestate']);

        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
            'agent_id' => $agent->id,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'discount_amount' => 50000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();
        $this->assertEquals('pending_approval', $offer->status);
        $this->assertEquals(450000.0, (float) $offer->approved_amount, 'Approved amount accounts for the discount.');

        // A regular agent must not be able to approve the discount.
        $this->post(route('deal.offers.approve', $offer))->assertSessionHasErrors('discount');
        $this->assertEquals('pending_approval', $offer->fresh()->status);

        // The admin approves → issued.
        $this->actingAs($this->adminUser);
        $this->post(route('deal.offers.approve', $offer))->assertRedirect(route('deals.show', $deal));

        $this->assertEquals('issued', $offer->fresh()->status);
        $this->assertEquals($this->adminUser->id, $offer->fresh()->discount_approved_by);
    }

    public function test_closed_won_is_blocked_without_a_signed_offer(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);

        $this->patch("/pipeline/{$deal->id}/stage", ['stage' => 'closed_won'])
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'signed offer letter'));

        $lead = $deal->lead->fresh();
        $this->assertNotEquals('closed_won', $lead->status);
        $this->assertNotEquals('closed_won', $deal->fresh()->stage);
    }

    public function test_closed_won_allowed_after_signed_offer_and_applies_commission(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->patch("/pipeline/{$deal->id}/stage", ['stage' => 'closed_won'])
            ->assertStatus(422);

        $this->signOffer($offer);

        $this->patch("/pipeline/{$deal->id}/stage", ['stage' => 'closed_won'])
            ->assertJson(['success' => true]);

        $deal = $deal->fresh();
        $this->assertEquals('closed_won', $deal->stage);
        $this->assertEquals(10000.0, (float) $deal->total_commission, '2% of 500k auto-applied on close.');
        $this->assertEquals('closed_won', $deal->lead->fresh()->status, 'Lead status stays in sync.');
    }

    public function test_a_sale_is_gated_and_closes_after_signing(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);
        $lead = $deal->lead;
        $offer = $this->issueOffer($deal);

        // No signed offer yet, so the win is refused rather than half-applied.
        $this->patchJson(route('deals.updateStage', $deal), ['stage' => 'closed_won'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The deal cannot be closed as Won until a signed offer letter has been uploaded. Generate the offer letter, print it for the client, then upload the signed copy.']);

        $this->assertNotEquals('closed_won', $deal->fresh()->stage);

        $this->signOffer($offer);

        $this->patchJson(route('deals.updateStage', $deal), ['stage' => 'closed_won'])
            ->assertJson(['success' => true]);

        $this->assertEquals('closed_won', $lead->fresh()->status);
        $this->assertEquals('closed_won', $deal->fresh()->stage);
        $this->assertEquals(10000.0, (float) $deal->fresh()->total_commission);
    }

    public function test_a_won_lease_closes_the_lead_and_applies_the_commission(): void
    {
        $this->reAdmin();

        $deal = $this->rentDeal(120000);
        $lead = $deal->lead;

        $this->issueOffer($deal);
        $this->signOffer($deal->offerLetters()->first());

        // Winning is a deal-stage event now, not a lead status an agent types.
        $this->patchJson(route('deals.updateStage', $deal), ['stage' => 'commission_received'])
            ->assertJson(['success' => true]);

        $this->assertEquals('closed_won', $lead->fresh()->status);
        $this->assertEquals('deal_won', $deal->fresh()->stage);
        $this->assertEquals(6000.0, (float) $deal->fresh()->total_commission, '5% of 120k annual rent.');
    }

    public function test_a_lease_lead_cannot_be_closed_won_by_hand(): void
    {
        $this->reAdmin();

        $deal = $this->rentDeal(120000);
        $this->issueOffer($deal);
        $this->signOffer($deal->offerLetters()->first());

        // A non-JSON post redirects with the validation error rather than 422.
        $this->patch(route('leads.updateStatus', $deal->lead), ['status' => 'closed_won'])
            ->assertSessionHasErrors('status');

        $this->assertNotEquals('closed_won', $deal->lead->fresh()->status);
        $this->assertEquals(0.0, (float) $deal->fresh()->total_commission);
    }

    public function test_print_renders_the_offer_letter(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->get(route('deal.offers.print', $offer))
            ->assertOk()
            ->assertSee($offer->offer_no)
            ->assertSee($this->tenant->name)
            ->assertSee('Print / Save as PDF')
            ->assertSee('Back to Deal')
            ->assertSee(route('deals.show', $deal));
    }

    public function test_signed_offer_must_have_discount_approved_first(): void
    {
        $agent = $this->actingAsRole('agent', ['business_mode' => 'realestate']);

        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'moved_in',
            'contract_price' => 120000,
            'agent_id' => $agent->id,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 120000,
            'discount_amount' => 12000,
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();
        $this->assertEquals('pending_approval', $offer->status);

        $this->post(route('deal.offers.uploadSigned', $offer), [
            'signed_pdf' => UploadedFile::fake()->create('signed.pdf', 500, 'application/pdf'),
        ])->assertSessionHasErrors('signed_pdf');

        $this->actingAs($this->adminUser);
        $this->post(route('deal.offers.approve', $offer))->assertRedirect();

        $this->post(route('deal.offers.uploadSigned', $offer), [
            'signed_pdf' => UploadedFile::fake()->create('signed.pdf', 500, 'application/pdf'),
        ])->assertRedirect();

        $this->assertEquals('signed', $offer->fresh()->status);
    }

    public function test_agent_with_manager_cannot_approve_own_offer_and_manager_is_notified(): void
    {
        $this->reAdmin();

        $manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
            'reports_to' => $manager->id,
        ]);

        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
            'agent_id' => $agent->id,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        Notification::fake();

        $this->actingAs($agent);
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();
        $this->assertEquals('pending_approval', $offer->status);
        $this->assertNull($offer->approved_by);

        Notification::assertSentTo($manager, OfferLetterApprovalRequired::class);

        // The agent (a manager's report) still cannot self-approve.
        $this->post(route('deal.offers.approve', $offer))->assertSessionHasErrors('discount');

        // The manager approves → notified the agent.
        $this->actingAs($manager);
        $this->post(route('deal.offers.approve', $offer))->assertRedirect(route('deals.show', $deal));

        $this->assertEquals('issued', $offer->fresh()->status);
        $this->assertEquals($manager->id, $offer->fresh()->approved_by);

        Notification::assertSentTo($agent, OfferLetterApproved::class);

        // Approved letters can be printed.
        $this->get(route('deal.offers.print', $offer))->assertOk();
    }

    public function test_pending_offer_cannot_be_printed_by_agent_due_to_approval_gate(): void
    {
        $this->reAdmin();

        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
        ]);

        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
            'agent_id' => $agent->id,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        Notification::fake();

        $this->actingAs($agent);
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
        ]);

        $offer = $deal->offerLetters()->first();
        $this->assertEquals('pending_approval', $offer->status);

        $this->get(route('deal.offers.print', $offer))->assertForbidden();
    }

    public function test_signed_rent_offer_auto_advances_deal_to_deposit_received(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_signed',
            'contract_price' => 120000,
        ]);
        $deal->lead->update(['status' => 'active_client', 'deal_type' => 'rent']);

        $offer = $this->issueOffer($deal);
        $this->assertEquals('issued', $offer->status);

        $this->signOffer($offer);

        $this->assertEquals('deposit_received', $deal->fresh()->stage);
        $this->assertDatabaseHas('activities', ['deal_id' => $deal->id, 'subject' => 'Deal stage changed']);
    }

    public function test_signed_sale_offer_auto_advances_deal_to_under_contract(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);
        $this->assertEquals('active_listing', $deal->stage);

        $offer = $this->issueOffer($deal);
        $this->signOffer($offer);

        $this->assertEquals('under_contract', $deal->fresh()->stage);
        $this->assertDatabaseHas('activities', ['deal_id' => $deal->id, 'subject' => 'Deal stage changed']);
    }

    public function test_signed_offer_never_regresses_a_later_stage(): void
    {
        $this->reAdmin();

        $deal = $this->rentDeal(120000);
        $this->assertEquals('moved_in', $deal->stage);

        $offer = $this->issueOffer($deal);
        $this->signOffer($offer);

        $this->assertEquals('moved_in', $deal->fresh()->stage);
    }

    public function test_amount_in_words(): void
    {
        $this->reAdmin();
        $service = app(OfferLetterService::class);

        $this->assertStringContainsString('One Hundred Twenty Thousand', $service->amountInWords(120000));
        $this->assertStringContainsString('One Thousand Two Hundred Thirty Four and Fifty Six', $service->amountInWords(1234.56));
        $this->assertStringContainsString('AED', $service->amountInWords(120000, 'AED'));
        $this->assertEquals('Zero', $service->amountInWords(0));
    }

    public function test_a_pending_offer_can_be_edited_by_its_creator(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);

        $offer = $this->pendingOffer($deal, ['original_amount' => 500000, 'discount_amount' => 20000]);

        $this->assertTrue($offer->isEditable());

        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 500000,
            'discount_amount' => 30000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer->refresh();

        $this->assertEquals(30000.0, (float) $offer->discount_amount);
        $this->assertEquals(470000.0, (float) $offer->approved_amount);
    }

    public function test_an_approver_editing_a_pending_offer_self_approves_it(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->pendingOffer($deal, ['original_amount' => 500000]);

        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 450000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer->refresh();

        $this->assertEquals('issued', $offer->status);
        $this->assertEquals(450000.0, (float) $offer->original_amount);
        $this->assertNotNull($offer->approved_at);
        $this->assertEquals(auth()->user()->id, $offer->approved_by);
    }

    public function test_an_issued_offer_cannot_be_edited(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->assertFalse($offer->isEditable());

        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 1,
        ])->assertSessionHasErrors('original_amount');

        $this->assertEquals(500000.0, (float) $offer->fresh()->original_amount);
    }

    public function test_editing_recalculates_commission_the_same_way_creation_does(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);

        $offer = $this->pendingOffer($deal, [
            'original_amount' => 500000,
            'discount_amount' => 50000,
            'commission_rate_pct' => 2,
        ]);

        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 500000,
            'discount_amount' => 50000,
            'commission_rate_pct' => 2,
        ])->assertRedirect(route('deals.show', $deal));

        $offer->refresh();

        // 450,000 approved * 2% = 9,000 net
        $this->assertEquals(450000.0, (float) $offer->approved_amount);
        $this->assertEquals(9000.0, (float) $offer->commission_amount);
        $this->assertEquals(2.0, (float) $offer->commission_rate_pct);
    }

    public function test_editing_a_pending_offer_renotifies_the_approvers(): void
    {
        $this->reAdmin();

        $manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
            'reports_to' => $manager->id,
        ]);

        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
            'agent_id' => $agent->id,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        $offer = $this->pendingOffer($deal, ['original_amount' => 500000]);

        Notification::fake();

        $this->actingAs($agent);
        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 500000,
            'discount_amount' => 1000,
        ])->assertRedirect(route('deals.show', $deal));

        Notification::assertSentTo($manager, OfferLetterApprovalRequired::class);
        $this->assertEquals('pending_approval', $offer->fresh()->status);
    }

    public function test_a_pending_offer_can_be_withdrawn(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
        ]);
        $deal->lead->update(['status' => 'active_client']);

        $offer = $this->pendingOffer($deal, ['original_amount' => 500000]);

        $this->assertTrue($offer->canWithdraw());

        $this->post(route('deal.offers.withdraw', $offer))
            ->assertRedirect(route('deals.show', $deal))
            ->assertSessionHas('success');

        $offer->refresh();

        $this->assertEquals('withdrawn', $offer->status);
        $this->assertNotNull($offer->withdrawn_at);
        $this->assertEquals('Withdrawn', OfferLetter::STATUSES['withdrawn']);
    }

    public function test_an_issued_offer_can_be_withdrawn_but_not_reapproved(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->assertTrue($offer->canWithdraw());

        $this->post(route('deal.offers.withdraw', $offer))->assertRedirect(route('deals.show', $deal));
        $this->assertEquals('withdrawn', $offer->fresh()->status);

        // Withdrawing is terminal — it cannot be revived through approve.
        $this->post(route('deal.offers.approve', $offer))->assertSessionHasErrors('discount');
        $this->assertEquals('withdrawn', $offer->fresh()->status);
    }

    public function test_a_withdrawn_offer_cannot_be_edited_deleted_or_signed(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->post(route('deal.offers.withdraw', $offer));

        $offer->refresh();
        $this->assertFalse($offer->isEditable());
        $this->assertFalse($offer->canWithdraw());
        $this->assertFalse($offer->canDelete());
        $this->assertFalse($offer->isApproved());

        $this->patch(route('deal.offers.update', $offer), ['original_amount' => 1])
            ->assertSessionHasErrors('original_amount');

        $this->delete(route('deal.offers.destroy', $offer))->assertSessionHasErrors('status');

        $this->post(route('deal.offers.uploadSigned', $offer), [
            'signed_pdf' => UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('signed_pdf');

        $this->assertEquals('withdrawn', $offer->fresh()->status);
    }

    public function test_a_pending_offer_can_be_deleted(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);

        $offer = $this->pendingOffer($deal, ['original_amount' => 500000]);

        $this->assertTrue($offer->canDelete());

        $this->delete(route('deal.offers.destroy', $offer))
            ->assertRedirect(route('deals.show', $deal))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('offer_letters', ['id' => $offer->id]);
    }

    public function test_an_issued_offer_cannot_be_deleted(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->assertFalse($offer->canDelete());

        $this->delete(route('deal.offers.destroy', $offer))->assertSessionHasErrors('status');

        $this->assertDatabaseHas('offer_letters', ['id' => $offer->id]);
    }

    public function test_a_signed_offer_cannot_be_edited_withdrawn_or_deleted(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal(120000);
        $offer = $this->issueOffer($deal);
        $this->signOffer($offer);

        $offer->refresh();
        $this->assertEquals('signed', $offer->status);
        $this->assertFalse($offer->isEditable());
        $this->assertFalse($offer->canWithdraw());
        $this->assertFalse($offer->canDelete());

        $this->patch(route('deal.offers.update', $offer), ['original_amount' => 1])
            ->assertSessionHasErrors('original_amount');
        $this->post(route('deal.offers.withdraw', $offer))->assertSessionHasErrors('status');
        $this->delete(route('deal.offers.destroy', $offer))->assertSessionHasErrors('status');

        $this->assertDatabaseHas('offer_letters', ['id' => $offer->id, 'status' => 'signed']);
    }

    public function test_offer_actions_require_deal_update_permission(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $outsider = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
        ]);

        $this->actingAs($outsider);

        $this->patch(route('deal.offers.update', $offer), ['original_amount' => 1])->assertForbidden();
        $this->post(route('deal.offers.withdraw', $offer))->assertForbidden();
        $this->delete(route('deal.offers.destroy', $offer))->assertForbidden();

        $this->assertEquals('issued', $offer->fresh()->status);
        $this->assertDatabaseHas('offer_letters', ['id' => $offer->id]);
    }

    public function test_a_manager_can_edit_and_withdraw_a_teams_pending_offer(): void
    {
        $this->reAdmin();

        $manager = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
        ]);

        $agent = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
            'reports_to' => $manager->id,
        ]);

        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
            'agent_id' => $agent->id,
        ]);
        $deal->lead->update(['agent_id' => $agent->id, 'status' => 'active_client']);

        $offer = $this->pendingOffer($deal, ['original_amount' => 500000], $agent);
        $this->assertEquals('pending_approval', $offer->status);

        $this->actingAs($manager);
        $this->post(route('deal.offers.withdraw', $offer))->assertRedirect(route('deals.show', $deal));

        $this->assertEquals('withdrawn', $offer->fresh()->status);
    }

    public function test_a_signed_copy_can_be_replaced_without_a_second_signature_event(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal(120000);
        $offer = $this->issueOffer($deal);

        $first = $this->signOffer($offer);
        $originalPath = $first->signed_pdf_path;
        $signedAt = $first->signed_at->format('Y-m-d H:i:s');
        $stage = $deal->fresh()->stage;

        $this->assertNotNull($originalPath);

        $this->post(route('deal.offers.uploadSigned', $offer), [
            'signed_pdf' => UploadedFile::fake()->create('rescan.pdf', 200, 'application/pdf'),
        ])->assertRedirect();

        $offer->refresh();

        $this->assertEquals('signed', $offer->status);
        $this->assertNotEquals($originalPath, $offer->signed_pdf_path);
        $this->assertEquals($signedAt, $offer->signed_at->format('Y-m-d H:i:s'));
        $this->assertEquals($stage, $deal->fresh()->stage);

        $this->assertSame(
            1,
            \App\Models\Activity::where('deal_id', $deal->id)
                ->where('subject', 'Offer letter signed')
                ->count()
        );
    }

    public function test_a_declined_offer_cannot_be_signed(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->patch(route('deal.offers.status', $offer), ['status' => 'declined'])
            ->assertRedirect(route('deals.show', $deal));

        $this->assertEquals('declined', $offer->fresh()->status);

        $this->post(route('deal.offers.uploadSigned', $offer), [
            'signed_pdf' => UploadedFile::fake()->create('signed.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('signed_pdf');

        $this->assertEquals('declined', $offer->fresh()->status);
    }
}
