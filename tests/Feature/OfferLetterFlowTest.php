<?php

namespace Tests\Feature;

use App\Models\OfferLetter;
use App\Services\OfferLetterService;
use Illuminate\Http\UploadedFile;
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
        $this->assertEquals(500.0, (float) $offer->commission_vat, '5% VAT on the commission.');
        $this->assertEquals(10500.0, (float) $offer->commission_total);
        $this->assertStringContainsString('OL-', $offer->offer_no);
        $this->assertDatabaseHas('activities', ['lead_id' => $deal->lead_id, 'subject' => 'Offer letter issued']);
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

    public function test_update_status_is_gated_and_closes_after_signing(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);
        $lead = $deal->lead;
        $offer = $this->issueOffer($deal);

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'closed_won'])
            ->assertStatus(422);

        $this->signOffer($offer);

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'closed_won'])
            ->assertJson(['success' => true]);

        $this->assertEquals('closed_won', $lead->fresh()->status);
        $this->assertEquals('closed_won', $deal->fresh()->stage, 'The sale deal follows the lead to Won.');
        $this->assertEquals(10000.0, (float) $deal->fresh()->total_commission);
    }

    public function test_rent_lead_close_keeps_leasing_stage_and_applies_commission(): void
    {
        $this->reAdmin();

        $deal = $this->rentDeal(120000);
        $lead = $deal->lead;

        $this->issueOffer($deal);
        $this->signOffer($deal->offerLetters()->first());

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'closed_won'])
            ->assertJson(['success' => true]);

        $lead = $lead->fresh();
        $this->assertEquals('closed_won', $lead->status);
        $this->assertEquals('moved_in', $deal->fresh()->stage, 'Renting has no closed_won stage — it stays settled.');
        $this->assertEquals(6000.0, (float) $deal->fresh()->total_commission, '5% of 120k annual rent.');
    }

    public function test_print_renders_the_offer_letter(): void
    {
        $this->reAdmin();

        $deal = $this->saleDeal(500000);
        $offer = $this->issueOffer($deal);

        $this->get(route('deal.offers.print', $offer))
            ->assertOk()
            ->assertSee($offer->offer_no)
            ->assertSee($this->tenant->name);
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

    public function test_amount_in_words(): void
    {
        $this->reAdmin();
        $service = app(OfferLetterService::class);

        $this->assertStringContainsString('One Hundred Twenty Thousand', $service->amountInWords(120000));
        $this->assertStringContainsString('One Thousand Two Hundred Thirty Four and Fifty Six', $service->amountInWords(1234.56));
        $this->assertStringContainsString('AED', $service->amountInWords(120000, 'AED'));
        $this->assertEquals('Zero', $service->amountInWords(0));
    }
}
