<?php

namespace Tests\Feature;

use App\Models\OfferLetter;
use App\Models\User;
use App\Notifications\OfferLetterApprovalRequired;
use App\Notifications\OfferLetterApproved;
use App\Services\OfferLetterService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
        // One table now, not a separate "Agency Services" block: what changes
        // between letters is who collects each line, not which table it sits in.
        $this->assertStringContainsString('Payable To', $html);
        $this->assertStringContainsString('Total Payable', $html);
        $this->assertStringNotContainsString('Agency Services', $html);

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

    public function test_an_issued_offer_cannot_be_edited_by_an_agent(): void
    {
        $this->reAdmin();
        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->assertFalse($offer->isEditable());

        // An issued letter's terms are locked, so an agent editing one is
        // refused — the figures were approved by someone else. Managers keep
        // the authority they already hold; everyone else asks instead.
        $agent = \App\Models\User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'is_active' => true,
        ]);
        $deal->update(['agent_id' => $agent->id]);
        $deal->lead->update(['agent_id' => $agent->id]);

        $this->actingAs($agent)->patch(route('deal.offers.update', $offer), [
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

    public function test_only_the_owner_can_delete_a_signed_offer_and_the_purge_removes_the_trail(): void
    {
        $owner = $this->actingAsRole('owner', ['business_mode' => 'realestate']);

        $deal = $this->rentDeal(120000);
        $offer = $this->issueOffer($deal);
        $offer = $this->signOffer($offer);

        $offer->refresh();
        $this->assertEquals('signed', $offer->status);
        $this->assertTrue($offer->canDeleteBy($owner));

        $pdfPath = $offer->signed_pdf_path;
        $this->assertNotNull($pdfPath);
        $this->assertTrue(Storage::disk(config('filesystems.default'))->exists($pdfPath));

        $activityIds = \App\Models\Activity::where('deal_id', $deal->id)
            ->where(function ($q) use ($offer) {
                $q->where('subject', 'like', '%'.$offer->offer_no.'%')
                    ->orWhere('body', 'like', '%'.$offer->offer_no.'%');
            })
            ->pluck('id');
        $auditIds = \App\Models\AuditLog::where('model_type', OfferLetter::class)
            ->where('model_id', $offer->id)
            ->pluck('id');

        $this->assertTrue($activityIds->isNotEmpty());

        $this->delete(route('deal.offers.destroy', $offer))
            ->assertRedirect(route('deals.show', $deal))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('offer_letters', ['id' => $offer->id]);
        $this->assertFalse(Storage::disk(config('filesystems.default'))->exists($pdfPath));

        foreach ($activityIds as $id) {
            $this->assertDatabaseMissing('activities', ['id' => $id]);
        }
        foreach ($auditIds as $id) {
            $this->assertDatabaseMissing('audit_log', ['id' => $id]);
        }

        // A purge logs nothing new — the deletion is as invisible as the letter.
        $this->assertDatabaseMissing('activities', ['deal_id' => $deal->id, 'subject' => 'Offer letter deleted']);
    }

    public function test_an_owner_deleting_an_unapproved_letter_keeps_the_normal_deleted_trail(): void
    {
        $owner = $this->actingAsRole('owner', ['business_mode' => 'realestate']);

        $deal = $this->rentDeal(120000);
        $offer = $this->pendingOffer($deal);

        $this->assertTrue($offer->canDelete());
        $this->assertTrue($offer->canDeleteBy($owner));

        $this->delete(route('deal.offers.destroy', $offer))
            ->assertRedirect(route('deals.show', $deal))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('offer_letters', ['id' => $offer->id]);
        $this->assertDatabaseHas('activities', ['deal_id' => $deal->id, 'subject' => 'Offer letter deleted']);
        $this->assertDatabaseHas('audit_log', [
            'model_type' => OfferLetter::class,
            'model_id' => $offer->id,
            'action' => 'offer_letter.deleted',
        ]);
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

    public function test_the_offer_date_defaults_to_today_when_none_is_given(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        // Normal use never touches the field, so it is simply today.
        $this->assertTrue($offer->issued_at->isToday());
    }

    public function test_the_offer_date_can_be_backdated(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        // Reconstructing a letter for a deal that was actually negotiated in March.
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'issued_at' => '2026-03-03',
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $this->assertSame('2026-03-03', $offer->issued_at->format('Y-m-d'));
        $this->assertFalse($offer->issued_at->isToday());
    }

    public function test_the_offer_date_cannot_be_set_in_the_future(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'issued_at' => now()->addWeek()->format('Y-m-d'),
        ])->assertSessionHasErrors('issued_at');

        $this->assertSame(0, $deal->offerLetters()->count());
    }

    public function test_an_existing_offer_date_survives_an_edit_that_does_not_touch_it(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 500000,
            'issued_at' => '2026-03-03',
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        // An admin-created letter is approved on the spot, and an approver may
        // still edit it, so this edit goes through.
        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 475000,
        ])->assertRedirect(route('deals.show', $deal));

        $offer->refresh();

        $this->assertEquals(475000.0, (float) $offer->original_amount);
        // And the edit must not have reset the date to today, because this
        // edit did not touch it.
        $this->assertSame('2026-03-03', $offer->issued_at->format('Y-m-d'));
    }

    public function test_an_unsigned_issued_offer_keeps_its_price_locked_when_only_the_date_is_corrected(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000]);
        $offer = $deal->offerLetters()->first();

        // The date may be corrected on an issued letter...
        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 500000,
            'issued_at' => '2026-02-02',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $offer->refresh();

        $this->assertSame('2026-02-02', $offer->issued_at->format('Y-m-d'));
        // ...but a price smuggled in alongside it is ignored, not applied.
        $this->assertEquals(500000.0, (float) $offer->original_amount);
        $this->assertEquals(0.0, (float) $offer->discount_amount);
        $this->assertEquals(10000.0, (float) $offer->commission_amount, '2% of 500k, not of a forged figure.');
    }

    public function test_the_offer_date_is_frozen_once_the_client_has_signed(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000]);
        $offer = $deal->offerLetters()->first();
        $offer->update(['status' => 'signed', 'signed_at' => now()]);

        // Moving the date on a document the client has already signed would be
        // falsifying their paperwork, not fixing a typo.
        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 500000,
            'issued_at' => '2026-02-02',
        ])->assertSessionHasErrors('original_amount');

        $this->assertTrue($offer->fresh()->issued_at->isToday());
    }

    public function test_an_offer_date_can_be_corrected_after_the_letter_was_issued(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 500000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000]);
        $offer = $deal->offerLetters()->first();

        $this->patch(route('deal.offers.update', $offer), [
            'original_amount' => 500000,
            'issued_at' => '2026-01-15',
        ])->assertRedirect();

        $this->assertSame('2026-01-15', $offer->fresh()->issued_at->format('Y-m-d'));
    }

    public function test_the_printed_letter_shows_the_backfilled_offer_date(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed', 'contract_price' => 120000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 120000,
            'issued_at' => '2026-03-03',
        ])->assertRedirect(route('deals.show', $deal));

        $offer = $deal->offerLetters()->first();

        $html = $this->get(route('deal.offers.print', $offer))->assertOk()->getContent();

        $this->assertStringContainsString('March 3, 2026', $html);
        $this->assertStringNotContainsString(now()->format('F j, Y'), $html);
    }

    public function test_the_offer_form_offers_an_editable_offer_date_capped_at_today(): void
    {
        $this->reAdmin();

        // A rent deal: the offer LETTER panel is the rent branch of the deal page,
        // a sale deal renders the separate wholesale offers panel instead.
        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed', 'contract_price' => 120000]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $html = $this->get(route('deals.show', $deal))->assertOk()->getContent();

        $this->assertStringContainsString('name="issued_at"', $html);
        $this->assertStringContainsString('Offer Date', $html);
        $this->assertStringContainsString('max="'.now()->format('Y-m-d').'"', $html);
        $this->assertStringContainsString('value="'.now()->format('Y-m-d').'"', $html);
    }

    public function test_the_offered_unit_is_chosen_from_the_units_the_client_has_viewed(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $viewed = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'marketing_title' => 'Marina Gate 1402',
            'rent_price' => 120000,
            'rent_period' => 'year',
            'deposit_amount' => 10000,
            'admin_fee' => 2500,
            'contract_fee' => 500,
        ]);
        $other = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'marketing_title' => 'JVC Garden 802',
            'rent_price' => 90000,
            'rent_period' => 'year',
            'deposit_amount' => 7500,
            'admin_fee' => 1750,
            'contract_fee' => 350,
        ]);

        // Viewed units arrive by two different routes: the agent's linked-units
        // list, and a viewing booked against the lead.
        $deal->lead->properties()->attach($viewed->id);
        $deal->lead->showings()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => now()->addDays(3),
            'showing_time' => '11:00',
            'status' => 'scheduled',
            'property_id' => $other->id,
        ]);

        $html = $this->get(route('deals.show', $deal))->assertOk()->getContent();

        $this->assertStringContainsString('data-offer-unit', $html, 'The unit picker must be offered.');
        $this->assertStringContainsString('value="'.$viewed->id.'"', $html);
        $this->assertStringContainsString('value="'.$other->id.'"', $html);

        // Picking the viewing's unit pulls that unit's figures, not the other one's.
        $json = $this->getJson(route('deal.offers.unitDefaults', $deal).'?unit_id='.$other->id);
        $json->assertOk();

        $this->assertEquals(90000.0, (float) $json->json('original_amount'));
        // 5% of 90,000 — the inventory deposit is deliberately not used.
        $this->assertEquals(4500.0, (float) $json->json('security_deposit'));
        $this->assertEquals(1750.0, (float) $json->json('admin_fee'));
        $this->assertEquals(350.0, (float) $json->json('contract_fee'));
    }

    public function test_choosing_a_unit_stamps_it_onto_the_deal_so_a_later_letter_prices_off_that_unit(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $unit = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'rent_price' => 120000,
            'rent_period' => 'year',
            'deposit_amount' => 10000,
            'admin_fee' => 2500,
            'contract_fee' => 500,
        ]);

        $this->post(route('deal.offers.store', $deal), [
            'unit_id' => $unit->id,
            'original_amount' => 120000,
            'security_deposit' => 10000,
            'admin_fee' => 2500,
            'contract_fee' => 500,
        ])->assertRedirect();

        $this->assertSame($unit->id, $deal->fresh()->property_id);

        $offer = $deal->offerLetters()->first();

        $this->assertEquals(2500.0, (float) $offer->admin_fee);
        $this->assertEquals(500.0, (float) $offer->contract_fee);
        // 5% of 120,000 rather than the 10,000 the inventory row carried.
        $this->assertEquals(6000.0, (float) $offer->security_deposit);
    }

    public function test_a_unit_from_another_tenant_cannot_be_used_for_an_offer(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);
        $originalUnit = $deal->fresh()->property_id;

        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Rival Agency',
            'slug' => 'rival-agency',
            'email' => 'admin@rival.test',
            'status' => 'active',
            'currency' => 'USD',
            'country' => 'US',
            'locale' => 'en',
            'distribution_method' => 'round_robin',
        ]);
        $foreign = \App\Models\Property::factory()->create([
            'tenant_id' => $otherTenant->id,
            'rent_price' => 5000000,
        ]);

        $this->post(route('deal.offers.store', $deal), [
            'unit_id' => $foreign->id,
            'original_amount' => 120000,
        ])->assertStatus(422);

        $this->assertSame($originalUnit, $deal->fresh()->property_id);
        $this->assertSame(0, $deal->offerLetters()->count());
    }

    public function test_the_end_date_is_derived_from_the_start_date_and_the_contract_period(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'contract_start_date' => '2027-03-01',
            'contract_years' => 2,
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        // Two years from 1 March 2027 ends on 28 Feb 2029: the -1 day is what
        // makes a tenancy end the day before its anniversary, not a year later.
        $this->assertSame('2027-03-01', $offer->contract_start_date->format('Y-m-d'));
        $this->assertSame('2029-02-28', $offer->contract_end_date->format('Y-m-d'));
        $this->assertSame(2, $offer->contract_years);
    }

    public function test_a_single_year_term_ends_the_day_before_its_anniversary(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'contract_start_date' => '2028-03-01',
            'contract_years' => 1,
        ])->assertRedirect();

        // 2029-02-28, not 2028-02-29: a "one year" term spans start .. start+1yr-1day,
        // so the client occupies all twelve months. addYears() moves the month and
        // day rather than re-deriving them, so this is not a leap-day special case.
        $this->assertSame('2029-02-28', $deal->offerLetters()->first()->contract_end_date->format('Y-m-d'));
    }

    public function test_the_contract_period_must_be_a_sane_number_of_years(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'contract_years' => 0,
        ])->assertSessionHasErrors('contract_years');

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'contract_years' => 40,
        ])->assertSessionHasErrors('contract_years');
    }

    public function test_the_letter_prints_the_contract_period_in_years(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'contract_start_date' => '2027-03-01',
            'contract_years' => 3,
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();
        $html = $this->get(route('deal.offers.print', $offer))->getContent();

        $this->assertStringContainsString('(3 Years)', $html);
    }

    public function test_no_of_payments_is_a_number_from_one_to_twelve(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $html = $this->get(route('deals.show', $deal))->assertOk()->getContent();

        $this->assertStringContainsString(__('No. of Payments'), $html);
        $this->assertStringContainsString('name="payment_period"', $html);
        $this->assertStringNotContainsString(__('Payment Period'), $html);

        // All twelve options present, and nothing outside the range.
        for ($n = 1; $n <= 12; $n++) {
            $this->assertStringContainsString('<option value="'.$n.'"', $html);
        }
        $this->assertStringNotContainsString('<option value="0"', $html);
        $this->assertStringNotContainsString('<option value="13"', $html);
    }

    public function test_the_number_of_payments_is_stored_as_a_count_and_printed_as_a_phrase(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'payment_period' => 4,
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertSame('4', $offer->payment_period);
        $this->assertSame('4 Payments', $offer->paymentPeriodLabel());
        $this->assertStringContainsString('— 4 Payments', $this->get(route('deal.offers.print', $offer))->getContent());
    }

    public function test_one_payment_reads_singular(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'payment_period' => 1,
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertSame('1 Payment', $offer->paymentPeriodLabel());
    }

    public function test_the_number_of_payments_is_capped_at_twelve(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'payment_period' => 13,
        ])->assertSessionHasErrors('payment_period');

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'payment_period' => 0,
        ])->assertSessionHasErrors('payment_period');
    }

    public function test_a_legacy_free_text_payment_period_still_prints_verbatim(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000]);
        $offer = $deal->offerLetters()->first();

        // Letters written before the field became a count hold phrases like this.
        // Numeric suffixing would render "1 Payment Payments".
        $offer->update(['payment_period' => '2 Cheques']);
        $this->assertSame('2 Cheques', $offer->paymentPeriodLabel());

        $offer->update(['payment_period' => '1 Payment']);
        $this->assertSame('1 Payment', $offer->paymentPeriodLabel());

        $offer->update(['payment_period' => null]);
        $this->assertNull($offer->paymentPeriodLabel());
    }

    public function test_the_offered_unit_drives_both_the_price_and_the_fees_not_the_leads_unit(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $leadUnit = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'rent_price' => 100000, 'rent_period' => 'year',
            'deposit_amount' => 1000, 'admin_fee' => 1000, 'contract_fee' => 1000,
        ]);
        $offered = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'rent_price' => 120000, 'rent_period' => 'year',
            'deposit_amount' => 10000, 'admin_fee' => 2500, 'contract_fee' => 500,
        ]);

        // The lead still points at the first unit; the agent writes the offer on
        // the second. Price and fees must both come from the second, or the
        // letter quotes one unit's rent against the other unit's deposit.
        $deal->lead->properties()->attach($leadUnit->id);

        $this->post(route('deal.offers.store', $deal), [
            'unit_id' => $offered->id,
            'original_amount' => 120000,
            'security_deposit' => 10000,
            'admin_fee' => 2500,
            'contract_fee' => 500,
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertEquals(120000.0, (float) $offer->original_amount, 'Listed price must follow the offered unit.');
        $this->assertEquals(2500.0, (float) $offer->admin_fee);
        $this->assertEquals(500.0, (float) $offer->contract_fee);
        // 5% of 120,000 rather than the 10,000 the inventory row carried.
        $this->assertEquals(6000.0, (float) $offer->security_deposit);
        $this->assertNotEquals(1000.0, (float) $offer->admin_fee, 'Must not fall back to the lead\'s unit.');
    }

    public function test_a_deal_with_no_offered_unit_still_prices_off_the_leads_unit(): void
    {
        $this->reAdmin();

        $leadUnit = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'rent_price' => 96000, 'rent_period' => 'year',
        ]);
        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);
        $deal->lead->properties()->attach($leadUnit->id);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 96000])
            ->assertRedirect();

        // No unit chosen, so the lead's unit remains the fallback rather than
        // leaving the letter with no price source at all.
        $this->assertEquals(96000.0, (float) $deal->offerLetters()->first()->original_amount);
    }

    public function test_the_security_deposit_is_five_per_cent_of_the_contract_value(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'discount_amount' => 20000,
        ])->assertRedirect();

        // Contract value 80,000, deposit 5% of that — not 5% of the listed price.
        $this->assertEquals(80000.0, (float) $deal->offerLetters()->first()->approved_amount);
        $this->assertEquals(4000.0, (float) $deal->offerLetters()->first()->security_deposit);
    }

    public function test_a_discount_moves_the_security_deposit_down_with_it(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000])->assertRedirect();
        $this->assertEquals(5000.0, (float) $deal->offerLetters()->orderByDesc('id')->first()->security_deposit);

        // Adding a discount must not leave the letter asking for a deposit that
        // no longer matches the rent the client just agreed to.
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'discount_amount' => 40000,
        ])->assertRedirect();

        $this->assertEquals(3000.0, (float) $deal->offerLetters()->orderByDesc('id')->first()->security_deposit);
    }

    public function test_a_posted_deposit_is_ignored_while_the_five_per_cent_rule_is_on(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'security_deposit' => 99000,
        ])->assertRedirect();

        $this->assertEquals(5000.0, (float) $deal->offerLetters()->first()->security_deposit);
    }

    public function test_the_deposit_can_be_set_manually_when_the_rule_is_switched_off(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'security_deposit' => 12000,
            'security_deposit_mode' => 'custom',
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertEquals(12000.0, (float) $offer->security_deposit);
        $this->assertSame('custom', $offer->security_deposit_mode);
    }

    public function test_the_occupant_and_emirates_id_are_recorded_on_the_letter(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'occupant_name' => 'Fatima Al Mansouri',
            'emirates_id' => '784-1998-1234567-1',
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertSame('Fatima Al Mansouri', $offer->occupant_name);
        $this->assertSame('784-1998-1234567-1', $offer->emirates_id);

        $html = $this->get(route('deal.offers.print', $offer))->getContent();

        $this->assertStringContainsString('Fatima Al Mansouri', $html);
        $this->assertStringContainsString('784-1998-1234567-1', $html);
    }

    public function test_the_property_detail_line_reads_unit_building_sub_community_community_city_and_emirate(): void
    {
        $this->reAdmin();

        $unit = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'unit_no' => '2506',
            'building_no' => 'Burj Al Shams Tower',
            'sub_community' => 'Reem Island',
            'community' => 'Reem Island',
            'city' => 'Abu Dhabi',
            'state' => 'Abu Dhabi',
        ]);

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'unit_id' => $unit->id,
            'original_amount' => 100000,
        ])->assertRedirect();

        $html = $this->get(route('deal.offers.print', $deal->offerLetters()->first()))->getContent();

        // Every part in one line, in the order an agent reads it out.
        $this->assertStringContainsString('Unit No. 2506', $html);
        $this->assertStringContainsString('Burj Al Shams Tower', $html);
        $this->assertStringContainsString('Reem Island', $html);
        $this->assertStringContainsString('Abu Dhabi', $html);
    }

    public function test_a_half_filled_unit_never_prints_an_empty_segment(): void
    {
        $this->reAdmin();

        // Only a unit number and a city: no building, sub-community or community.
        $unit = $this->createProperty([
            'tenant_id' => $this->tenant->id,
            'unit_no' => '1204',
            'city' => 'Dubai',
        ]);

        $unit->forceFill(['building_no' => null, 'sub_community' => null, 'community' => null])->save();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'unit_id' => $unit->id,
            'original_amount' => 100000,
        ])->assertRedirect();

        $html = $this->get(route('deal.offers.print', $deal->offerLetters()->first()))->getContent();

        $this->assertStringContainsString('Unit No. 1204', $html);
        $this->assertStringNotContainsString('Unit No. 1204,,', $html);
        $this->assertStringNotContainsString(',,', $html);
    }

    public function test_each_money_line_can_be_routed_to_the_landlord_or_the_broker(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        // The landlord asks the agency to collect everything.
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'admin_fee' => 2500,
            'contract_fee' => 500,
            'rent_payable_to' => 'broker',
            'deposit_payable_to' => 'broker',
            'commission_payable_to' => 'broker',
            'admin_fee_payable_to' => 'broker',
            'contract_fee_payable_to' => 'broker',
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertSame('broker', $offer->rent_payable_to);
        $this->assertSame('broker', $offer->deposit_payable_to);

        $payees = collect($offer->payableLines())->pluck('payee')->unique()->all();
        $this->assertCount(1, $payees, 'Every line collected by us should name only the agency.');
    }

    public function test_the_landlord_may_collect_the_rent_deposit_and_fees_while_paying_only_the_commission(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'admin_fee' => 2500,
            'contract_fee' => 500,
            'rent_payable_to' => 'landlord',
            'deposit_payable_to' => 'landlord',
            'commission_payable_to' => 'broker',
            'admin_fee_payable_to' => 'landlord',
            'contract_fee_payable_to' => 'landlord',
        ])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $byKey = collect($offer->payableLines())->keyBy('key');

        $this->assertSame('landlord', $offer->admin_fee_payable_to);
        $this->assertSame('landlord', $offer->contract_fee_payable_to);

        // Rent, deposit, admin and contract fees name the landlord; only the
        // commission is ours.
        $this->assertSame('Landlord', $byKey['rent']['payee']);
        $this->assertSame('Landlord', $byKey['deposit']['payee']);
        $this->assertSame('Landlord', $byKey['admin_fee']['payee']);
        $this->assertSame('Landlord', $byKey['contract_fee']['payee']);
        $this->assertSame($this->tenant->name, $byKey['commission']['payee']);
    }

    public function test_the_default_payable_split_is_the_conventional_one(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000])->assertRedirect();

        $offer = $deal->offerLetters()->first();

        $this->assertSame('landlord', $offer->rent_payable_to);
        $this->assertSame('landlord', $offer->deposit_payable_to);
        $this->assertSame('broker', $offer->commission_payable_to);
        $this->assertSame('broker', $offer->admin_fee_payable_to);
        $this->assertSame('broker', $offer->contract_fee_payable_to);
    }

    public function test_a_bogus_payable_to_value_is_rejected_rather_than_coerced(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        // Silently rewriting an unknown party to the default would put a
        // collection instruction on a legal document that nobody asked for.
        $this->post(route('deal.offers.store', $deal), [
            'original_amount' => 100000,
            'rent_payable_to' => 'somebody-else',
        ])->assertSessionHasErrors('rent_payable_to');

        $this->assertSame(0, $deal->offerLetters()->count());
    }

    public function test_a_legacy_letter_with_no_payable_to_still_names_a_party(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000])->assertRedirect();
        // The migration backfills every existing row with the conventional split,
        // so a stored null is unreachable — but an unhydrated model must still
        // name a party rather than print a blank payee.
        $ghost = new OfferLetter([
            'approved_amount' => 100000,
            'security_deposit' => 5000,
            'rent_payable_to' => null,
            'deposit_payable_to' => null,
        ]);
        $ghost->setRelation('deal', $deal);
        $ghost->setRelation('tenant', $this->tenant);

        $this->assertSame('landlord', $ghost->payableToAttributes()['rent_payable_to']);
        $this->assertSame('broker', $ghost->payableToAttributes()['commission_payable_to']);

        $payees = collect($ghost->payableLines())->pluck('payee')->filter()->all();
        $this->assertNotEmpty($payees);
        $this->assertNotContains('', $payees);
    }

    public function test_the_letterhead_shows_both_logo_and_name_by_default(): void
    {
        $this->reAdmin();

        $tenant = $this->tenant;
        $tenant->update(['letterhead_display' => 'both']);
        Storage::fake('public');
        Storage::disk('public')->put('logos/t.png', 'x');
        $tenant->update(['logo_path' => 'logos/t.png']);

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000])->assertRedirect();
        $html = $this->get(route('deal.offers.print', $deal->offerLetters()->first()))->getContent();

        $this->assertStringContainsString('<img src=', $html);
        $this->assertStringContainsString($tenant->name, $html);
    }

    public function test_the_letterhead_can_be_set_to_the_name_only(): void
    {
        $this->reAdmin();

        $tenant = $this->tenant;
        Storage::fake('public');
        Storage::disk('public')->put('logos/t.png', 'x');
        $tenant->update(['logo_path' => 'logos/t.png', 'letterhead_display' => 'name']);

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000])->assertRedirect();
        $html = $this->get(route('deal.offers.print', $deal->offerLetters()->first()))->getContent();

        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringContainsString($tenant->name, $html);
    }

    public function test_a_logo_only_letterhead_still_names_the_company_when_no_logo_is_uploaded(): void
    {
        $this->reAdmin();

        // A letter that names no company is not acceptable, so "logo only" with no
        // logo must fall back to the name rather than print a blank letterhead.
        $this->tenant->update(['letterhead_display' => 'logo', 'logo_path' => null]);

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 100000])->assertRedirect();
        $html = $this->get(route('deal.offers.print', $deal->offerLetters()->first()))->getContent();

        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringContainsString($this->tenant->name, $html);
    }

    public function test_the_letterhead_choice_is_saved_from_settings(): void
    {
        $this->reAdmin();

        $this->put(route('settings.updateGeneral'), [
            'name' => 'Pristine Properties',
            'email' => 'admin@pristine.test',
            'currency' => 'AED',
            'country' => 'AE',
            'letterhead_display' => 'name',
        ])->assertRedirect();

        $this->assertSame('name', $this->tenant->fresh()->letterhead_display);
    }
}
