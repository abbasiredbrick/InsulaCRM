<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Showing;
use App\Services\PipelineSyncService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PipelineSyncTest extends TestCase
{
    public function test_rent_lead_at_offer_requested_creates_deal_with_same_stage(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['stage' => 'offer_requested']);

        $deal = app(PipelineSyncService::class)->syncForLead($lead);

        $this->assertNotNull($deal);
        $this->assertSame('rent', $deal->deal_type);
        $this->assertSame('offer_requested', $deal->stage);
        $this->assertSame($lead->id, $deal->lead_id);
    }

    /**
     * The regression the new trigger exists for: a client who views a unit and
     * says "I don't like this one, show me another" must not open a deal.
     */
    public function test_rent_lead_at_viewing_done_does_not_create_a_deal(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['stage' => 'viewing_done']);

        $this->assertNull(app(PipelineSyncService::class)->syncForLead($lead));
        $this->assertSame(0, Deal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    /**
     * The trigger is a single stage, not a slice that happens to include it, so a
     * regression to the old offset cannot creep back in.
     */
    public function test_rent_trigger_starts_exactly_at_offer_requested(): void
    {
        $stages = app(PipelineSyncService::class)->triggerStages('rent');

        $this->assertSame('offer_requested', reset($stages));
        $this->assertNotContains('viewing_done', $stages);
        $this->assertContains('offer_sent', $stages);
    }

    public function test_deal_records_the_unit_the_client_asked_for(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['stage' => 'offer_requested']);

        // The lead's own unit is one unit, but the offer was requested on
        // another — the second property belongs to a different lead, so it is
        // only reachable through the showing.
        $this->createProperty(['lead_id' => $lead->id]);
        $offeredUnit = $this->createProperty(['address' => 'Offered Unit']);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $offeredUnit->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => '2026-04-15',
            'showing_time' => '14:00',
            'status' => 'completed',
        ]);

        $deal = app(PipelineSyncService::class)->syncForLead($lead->fresh(), null, $showing);

        $this->assertSame($offeredUnit->id, $deal->property_id);
        $this->assertStringContainsString('Offered Unit', $deal->title);
    }

    public function test_deal_falls_back_to_the_lead_unit_without_a_showing(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['stage' => 'offer_requested']);
        $unit = $this->createProperty(['lead_id' => $lead->id]);

        $deal = app(PipelineSyncService::class)->syncForLead($lead->fresh());

        $this->assertSame($unit->id, $deal->property_id);
    }

    public function test_sale_wholesale_lead_at_offer_sent_maps_to_offer_presented(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);

        $lead = $this->createLead(['stage' => 'offer_sent', 'deal_type' => 'sale']);

        $deal = app(PipelineSyncService::class)->syncForLead($lead);

        $this->assertNotNull($deal);
        $this->assertSame('sale', $deal->deal_type);
        $this->assertSame('offer_presented', $deal->stage);
    }

    public function test_sale_real_estate_lead_at_offer_sent_maps_to_offer_received(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $lead = $this->createLead(['stage' => 'offer_sent', 'deal_type' => 'sale']);

        $deal = app(PipelineSyncService::class)->syncForLead($lead);

        $this->assertNotNull($deal);
        $this->assertSame('offer_received', $deal->stage);
    }

    public function test_lead_below_revenue_stage_does_not_create_deal(): void
    {
        $this->actingAsAdmin();

        $rent = $this->createLead(['stage' => 'viewing_done']);
        $sale = $this->createLead(['stage' => 'new_lead', 'deal_type' => 'sale']);

        $this->assertNull(app(PipelineSyncService::class)->syncForLead($rent));
        $this->assertNull(app(PipelineSyncService::class)->syncForLead($sale));
    }

    public function test_sync_is_idempotent(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['stage' => 'offer_requested']);
        $service = app(PipelineSyncService::class);

        $service->syncForLead($lead);
        $second = $service->syncForLead($lead);

        $this->assertNull($second);
        $this->assertSame(1, Deal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_commission_is_copied_into_mode_fee_field(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);

        $deal = app(PipelineSyncService::class)
            ->syncForLead($this->createLead(['stage' => 'offer_sent', 'deal_type' => 'sale', 'commission_amount' => 12500]));

        $this->assertSame(12500.0, (float) $deal->assignment_fee);
    }

    public function test_lead_update_wires_auto_sync(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['stage' => 'outreach']);

        $this->patch("/leads/{$lead->id}", [
            'agent_id' => $this->adminUser->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'lead_source' => $lead->lead_source,
            'status' => $lead->status,
            'temperature' => $lead->temperature,
            'stage' => 'offer_requested',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Deal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
        $this->assertSame('offer_requested', $lead->deals()->first()->stage);
    }

    public function test_backfill_creates_deals_for_existing_qualified_leads(): void
    {
        $this->actingAsAdmin();
        Deal::where('tenant_id', $this->tenant->id)->delete();
        Lead::where('tenant_id', $this->tenant->id)->delete();

        $this->createLead(['stage' => 'offer_requested']);
        $this->createLead(['stage' => 'moved_in']);
        $this->createLead(['stage' => 'outreach']);

        Artisan::call('keystone:pipeline-backfill');

        $this->assertSame(2, Deal::withoutGlobalScopes()->count());

        $this->createLead(['stage' => 'offer_accepted', 'deal_type' => 'sale']);
        $sale = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('deal_type', 'sale')->first();

        Artisan::call('keystone:pipeline-backfill', ['--dry-run' => true]);

        $this->assertNull($sale->deals()->first(), 'dry-run must not create any deals');
    }
}
