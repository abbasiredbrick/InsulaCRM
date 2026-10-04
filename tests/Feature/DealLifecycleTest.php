<?php

namespace Tests\Feature;

use App\Events\LeadStatusChanged;
use App\Models\Deal;
use App\Models\OfferLetter;
use App\Services\CustomFieldService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The leasing ladder: commission in means won, tenant in means locked, and a
 * terminal lead status is something a deal does rather than an agent types.
 */
class DealLifecycleTest extends TestCase
{
    private function reAdmin(): TestCase
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function rentDeal(string $stage): Deal
    {
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => $stage,
            'contract_price' => 120000,
        ]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'rent']);

        return $deal;
    }

    private function signOffer(Deal $deal): void
    {
        $offer = OfferLetter::create([
            'tenant_id' => $deal->tenant_id,
            'deal_id' => $deal->id,
            'created_by' => $this->adminUser->id,
            'status' => 'signed',
            'original_amount' => 120000,
            'approved_amount' => 120000,
            'signed_at' => now(),
            'signed_pdf_path' => UploadedFile::fake()->create('signed.pdf', 500, 'application/pdf')
                ->store('offer-letters', 'public'),
        ]);

        $this->assertNotNull($offer);
    }

    private function move(Deal $deal, string $stage)
    {
        return $this->patchJson(route('deals.updateStage', $deal), ['stage' => $stage]);
    }

    public function test_commission_received_promotes_a_lease_to_deal_won(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deposit_received');
        $this->signOffer($deal);

        $this->move($deal, 'commission_received')->assertOk();

        $this->assertSame('deal_won', $deal->fresh()->stage);
    }

    public function test_a_won_lease_closes_the_lead_and_applies_the_commission(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deposit_received');
        $this->signOffer($deal);

        $this->move($deal, 'commission_received')->assertOk();

        $lead = $deal->lead->fresh();
        $this->assertSame('closed_won', $lead->status);
        $this->assertGreaterThan(0, (float) $deal->fresh()->total_commission);
    }

    public function test_the_response_reports_the_stage_the_deal_actually_rests_on(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deposit_received');
        $this->signOffer($deal);

        // The board moves the card to the column it was dropped on; if the
        // response echoed commission_received it would sit in a stage the deal
        // has already left.
        $this->move($deal, 'commission_received')
            ->assertOk()
            ->assertJson(['stage' => 'deal_won', 'promoted_to' => 'deal_won']);
    }

    public function test_a_lease_cannot_be_won_without_a_signed_offer(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deposit_received');

        $this->move($deal, 'commission_received')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The deal cannot be closed as Won until a signed offer letter has been uploaded. Generate the offer letter, print it for the client, then upload the signed copy.']);

        // Refused before the promotion: a refused win must not leave the deal
        // sitting on deal_won with no commission attached.
        $this->assertSame('deposit_received', $deal->fresh()->stage);
        $this->assertNotSame('closed_won', $deal->lead->fresh()->status);
    }

    public function test_moved_in_locks_the_deal(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('moved_in');
        $this->signOffer($deal);

        $this->move($deal, 'moved_in')->assertOk();

        $this->assertSame('deal_locked', $deal->fresh()->stage);
    }

    public function test_a_locked_lease_cannot_be_dragged_back_into_revenue(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deal_locked');
        $this->signOffer($deal);

        // The board is drag-and-drop, so a locked card can be dropped on the
        // commission column. Left alone the promotion would fire and move it to
        // deal_won — reopening a closed lease and reporting it twice.
        $this->move($deal, 'commission_received')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This deal is locked and closed. Its stage cannot be changed.']);

        $this->assertSame('deal_locked', $deal->fresh()->stage);
    }

    public function test_a_locked_lease_cannot_be_moved_backwards_at_all(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deal_locked');

        foreach (['offer_signed', 'negotiating', 'closed_lost'] as $stage) {
            $this->move($deal, $stage)->assertStatus(422);
        }

        $this->assertSame('deal_locked', $deal->fresh()->stage);
    }

    public function test_a_lost_deal_closes_the_lead_lost(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('negotiating');

        $this->move($deal, 'closed_lost')->assertOk();

        $this->assertSame('closed_lost', $deal->fresh()->stage);
        $this->assertSame('closed_lost', $deal->lead->fresh()->status);
    }

    public function test_a_lost_deal_loses_the_live_lead(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('negotiating');
        $this->assertSame('negotiating', $deal->lead->status);

        $this->move($deal, 'closed_lost')->assertOk();

        $this->assertSame('closed_lost', $deal->lead->fresh()->status);
    }

    public function test_deal_won_cannot_be_set_by_hand(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('offer_signed');

        $this->move($deal, 'deal_won')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This stage is set automatically when the deal reaches Commission Received (Broker).']);

        $this->assertSame('offer_signed', $deal->fresh()->stage);
        $this->assertNotSame('closed_won', $deal->lead->fresh()->status);
    }

    public function test_deal_locked_cannot_be_set_by_hand(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('offer_signed');

        $this->move($deal, 'deal_locked')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This stage is set automatically when the deal reaches Moved In / Settled.']);

        $this->assertSame('offer_signed', $deal->fresh()->stage);
    }

    public function test_a_deal_already_in_an_automatic_stage_may_stay_there(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deal_locked');

        // Re-posting the stage it is already on is a no-op, not a forgery
        // attempt, so it must not be refused.
        $this->move($deal, 'deal_locked')->assertOk();
    }

    public function test_an_automatic_stage_is_rejected_before_the_win_gate_runs(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('offer_signed');

        // No signed offer here either, but the answer must be about the manual
        // move being illegal, not about a signature.
        $this->move($deal, 'deal_won')->assertStatus(422)->assertJsonFragment([
            'message' => 'This stage is set automatically when the deal reaches Commission Received (Broker).',
        ]);
    }

    public function test_a_sale_still_wins_at_closed_won(): void
    {
        $this->reAdmin();
        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'closing',
            'contract_price' => 900000,
        ]);
        $deal->lead->update(['status' => 'negotiating', 'deal_type' => 'sale']);
        $this->signOffer($deal);

        $this->move($deal, 'closed_won')->assertOk();

        $this->assertSame('closed_won', $deal->fresh()->stage);
        $this->assertSame('closed_won', $deal->lead->fresh()->status);
    }

    public function test_won_and_lost_are_not_assignable_lead_statuses(): void
    {
        $assignable = CustomFieldService::getAssignableStatusSlugs();

        $this->assertNotContains('closed_won', $assignable);
        $this->assertNotContains('closed_lost', $assignable);
        $this->assertContains('negotiating', $assignable);
        $this->assertContains('new', $assignable);
    }

    public function test_the_lead_status_dropdown_omits_the_automated_statuses(): void
    {
        $this->reAdmin();

        // Scoped to the <select name="status"> block: closed_lost is also a *stage*
        // on this page, which is legitimate, so a page-wide search proves nothing.
        $statusOptions = function (string $html): array {
            preg_match('/<select name="status".*?<\/select>/s', $html, $m);

            preg_match_all('/value="([^"]+)"/', $m[0] ?? '', $values);

            return $values[1];
        };

        foreach ([route('leads.create')] as $url) {
            $options = $statusOptions($this->get($url)->assertOk()->getContent());
            $this->assertContains('new', $options, "{$url} should offer new");
            $this->assertNotContains('closed_won', $options, "{$url} must not offer closed_won");
            $this->assertNotContains('closed_lost', $options, "{$url} must not offer closed_lost");
        }

        // The list filter keeps them: a manager still needs to filter a list down
        // to closed-won, and removing them there would hide won business.
        $index = $this->get(route('leads.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="closed_won"', $index);
    }

    public function test_a_lead_cannot_be_saved_with_a_forged_terminal_status(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->put(route('leads.update', $lead), [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'lead_source' => $lead->lead_source ?? 'website',
            'status' => 'closed_won',
            'agent_id' => $this->adminUser->id,
        ])->assertSessionHasErrors('status');

        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_the_quick_status_endpoint_refuses_a_forged_terminal_status(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->patchJson(route('leads.updateStatus', $lead), ['status' => 'closed_lost'])
            ->assertStatus(422);

        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_a_won_lease_counts_as_won_and_not_as_open_pipeline(): void
    {
        $this->reAdmin();
        $deal = $this->rentDeal('deposit_received');
        $this->signOffer($deal);

        $this->move($deal, 'commission_received')->assertOk();
        $deal->refresh();

        $this->assertTrue($deal->isWon());
        $this->assertTrue($deal->isTerminal());

        $open = Deal::where('tenant_id', $this->tenant->id)->get()->reject(fn ($d) => $d->isTerminal());
        $this->assertTrue($open->every(fn ($d) => $d->id !== $deal->id));
    }

    public function test_a_won_lease_still_outside_the_open_pipeline(): void
    {
        $deal = new Deal(['stage' => 'deal_won']);

        // Rent, tawtheeq, the permit and the move are still outstanding, but none
        // of that is forecast: the money is earned, so it must not be counted as
        // open pipeline or the board overstates what is still to come.
        $this->assertTrue($deal->isWon());
        $this->assertTrue($deal->isTerminal());
    }

    public function test_a_locked_lease_is_won_and_terminal(): void
    {
        $deal = new Deal(['stage' => 'deal_locked']);

        $this->assertTrue($deal->isWon());
        $this->assertTrue($deal->isTerminal());
    }

    public function test_a_lease_never_reports_a_sale_win_stage(): void
    {
        $this->assertSame('deal_won', Deal::wonStageFor('rent'));
        $this->assertSame('closed_won', Deal::wonStageFor('sale'));
    }

    public function test_a_committed_rent_deal_is_not_won_before_the_commission(): void
    {
        $deal = new Deal(['stage' => 'offer_signed']);

        $this->assertFalse($deal->isWon());
        $this->assertFalse($deal->isTerminal());
    }

    public function test_both_won_and_lost_announce_the_terminal_lead_status(): void
    {
        // The asymmetry this guards against: a lost deal alerted the managers
        // through the event, a won deal did not, so every listener hooked to a
        // terminal status change silently skipped deals won via the lifecycle.
        Event::fake([LeadStatusChanged::class]);

        $this->reAdmin();

        $won = $this->rentDeal('deposit_received');
        $this->signOffer($won);
        $this->move($won, 'commission_received')->assertOk();

        Event::assertDispatched(LeadStatusChanged::class, fn ($e) => $e->lead->is($won->lead));
        $this->assertSame('deal_won', $won->fresh()->stage);
        $this->assertSame('closed_won', $won->lead->fresh()->status);

        $lost = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'closed_lost',
            'contract_price' => 90000,
        ]);
        $lost->lead->update(['status' => 'negotiating']);

        $this->move($lost, 'closed_lost')->assertOk();

        Event::assertDispatched(LeadStatusChanged::class, fn ($e) => $e->lead->is($lost->lead));
        $this->assertSame('closed_lost', $lost->lead->fresh()->status);
    }

    public function test_a_lost_deal_can_still_be_edited_after_it_closed_the_lead(): void
    {
        $this->reAdmin();

        $deal = $this->rentDeal('closed_lost');
        $deal->lead->update(['status' => 'negotiating']);

        $this->move($deal, 'closed_lost')->assertOk();

        $this->assertSame('closed_lost', $deal->lead->fresh()->status);

        // The status dropdown does not offer closed_lost, so the edit form posts
        // it straight back. Rejecting that made a closed lead uneditable, which
        // is exactly the record a manager most needs to correct.
        $lead = $deal->lead->fresh();
        $this->patch(route('leads.update', $lead), [
            'agent_id' => $lead->agent_id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'lead_source' => CustomFieldService::getValidSlugs('lead_source')[0],
            'status' => 'closed_lost',
            'temperature' => $lead->temperature,
            'notes' => 'Client asked for a callback on Thursday.',
        ])
            ->assertSessionHasNoErrors();

        $this->assertSame('closed_lost', $lead->fresh()->status);
    }
}
