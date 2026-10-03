<?php

namespace Tests\Feature;

use App\Models\Lease;
use Tests\TestCase;

class DealPipelineTypeTest extends TestCase
{
    private function realEstate(): TestCase
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_pipeline_renders_both_leasing_and_sales_deals(): void
    {
        $this->realEstate();

        $rent = $this->createDeal(['deal_type' => 'rent', 'stage' => 'moved_in']);
        $sale = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing']);

        $response = $this->get('/pipeline');

        $response->assertStatus(200);
        $response->assertSee($rent->lead->full_name);
        $response->assertSee($sale->lead->full_name);
        $response->assertSee('bg-teal-lt');
        $response->assertSee('bg-indigo-lt');
    }

    public function test_pipeline_rent_filter_shows_only_leasing_deals(): void
    {
        $this->realEstate();

        $rent = $this->createDeal(['deal_type' => 'rent', 'stage' => 'moved_in']);
        $sale = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing']);

        $this->get('/pipeline?deal_type=rent')
            ->assertSee($rent->lead->full_name)
            ->assertDontSee($sale->lead->full_name);
    }

    public function test_pipeline_sale_filter_includes_legacy_untagged_deals(): void
    {
        $this->realEstate();

        $legacy = $this->createDeal(['stage' => 'showing']);
        $rent = $this->createDeal(['deal_type' => 'rent', 'stage' => 'moved_in']);

        $this->get('/pipeline?deal_type=sale')
            ->assertSee($legacy->lead->full_name)
            ->assertDontSee($rent->lead->full_name);
    }

    public function test_rent_deal_shows_leasing_stage_board(): void
    {
        $this->realEstate();

        $this->createDeal(['deal_type' => 'rent', 'stage' => 'moved_in']);

        $this->get('/pipeline?deal_type=rent')
            ->assertSee('Moved In / Settled')
            ->assertDontSee('active_listing');
    }

    public function test_rent_deal_stage_update_accepts_leasing_stages(): void
    {
        $this->realEstate();

        $rent = $this->createDeal(['deal_type' => 'rent', 'stage' => 'new_lead']);

        $this->patch("/pipeline/{$rent->id}/stage", ['stage' => 'viewing_done'])
            ->assertJson(['success' => true]);

        $this->assertEquals('viewing_done', $rent->fresh()->stage);
    }

    public function test_deal_type_resolves_rent_from_linked_lease(): void
    {
        $this->realEstate();

        $lead = $this->createLead();
        $lease = Lease::factory()->create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty(['lead_id' => $lead->id])->id,
            'lead_id' => $lead->id,
        ]);
        $deal = $this->createDeal(['lead_id' => $lead->id, 'lease_id' => $lease->id]);

        $this->assertEquals('rent', $deal->dealType());
        $this->assertTrue($deal->is_leasing);
    }

    public function test_export_includes_type_column_and_filter(): void
    {
        $this->realEstate();

        $this->createDeal(['deal_type' => 'rent', 'stage' => 'moved_in']);
        $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing']);

        $response = $this->get('/pipeline/export?deal_type=rent');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=deals-export-'.now()->format('Y-m-d').'.csv');
    }

    public function test_pipeline_summary_shows_value_forecast_and_generated(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);

        $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_presented', 'contract_price' => 200000, 'assignment_fee' => 10000]);
        $won = $this->createDeal(['deal_type' => 'sale', 'stage' => 'closed_won', 'contract_price' => 150000, 'assignment_fee' => 8000]);
        $won->update(['stage_changed_at' => now()]);

        $this->get('/pipeline')
            ->assertSee('Active deals')
            ->assertSee('Pipeline value')
            ->assertSee('$200,000')
            ->assertSee('Weighted forecast')
            ->assertSee('$90,000')
            ->assertSee('Business generated')
            ->assertSee('$8,000')
            ->assertSee('Closed', false);
    }

    public function test_pipeline_filters_by_temperature_and_lead_source(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);

        $hot = $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_presented']);
        $hot->lead->update(['temperature' => 'hot', 'lead_source' => 'zillow']);
        $cold = $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_presented']);
        $cold->lead->update(['temperature' => 'cold', 'lead_source' => 'facebook_ads']);

        $this->get('/pipeline?temp=hot&source=zillow')
            ->assertSee($hot->lead->full_name)
            ->assertDontSee($cold->lead->full_name);
    }

    public function test_pipeline_renders_wholesale_stages_only_for_sale_tab(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);

        $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_presented']);

        $this->get('/pipeline?deal_type=sale')
            ->assertSee('Offer Presented')
            ->assertSee('Dispositions')
            ->assertDontSee('active_listing')
            ->assertDontSee('offer_received');
    }
}
