<?php

namespace Tests\Feature;

use App\Models\Property;
use Tests\TestCase;

class ListingDashboardTest extends TestCase
{
    public function test_admin_can_view_listed_units_page(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $response = $this->get('/listings');
        $response->assertStatus(200);
        $response->assertSee('Listed Units');
    }

    public function test_listed_units_page_shows_listed_properties_only(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createProperty(['availability' => 'listed', 'unit_no' => '2506']);
        $this->createProperty(['availability' => 'ready_to_list', 'unit_no' => '402']);

        $response = $this->get('/listings');
        $response->assertStatus(200);
        $response->assertSee('2506');
        $response->assertDontSee('402');
    }

    public function test_listed_units_page_filters_by_source_and_building(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $source = \App\Models\AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relevate',
        ]);
        $this->createProperty([
            'availability' => 'listed',
            'unit_no' => '2506',
            'availability_source_id' => $source->id,
            'sub_community' => 'Burj Al Shams',
        ]);

        $response = $this->get('/listings?source=' . $source->id . '&building=Burj Al Shams');
        $response->assertStatus(200);
        $response->assertSee('2506');

        $response = $this->get('/listings?building=Other');
        $response->assertStatus(200);
        $response->assertDontSee('2506');
    }

    public function test_admin_can_view_sales_mandates_pipeline(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal(['stage' => 'active_listing']);

        $response = $this->get('/listings/mandates');
        $response->assertStatus(200);
        $response->assertSee('Active Listings');
    }

    public function test_mandates_filters_by_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal(['stage' => 'active_listing']);
        $this->createDeal(['stage' => 'offer_received']);

        $response = $this->get('/listings/mandates?stage=active_listing');
        $response->assertStatus(200);
    }

    public function test_mandates_filters_by_agent(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal(['stage' => 'active_listing']);

        $response = $this->get('/listings/mandates?agent=' . $this->adminUser->id);
        $response->assertStatus(200);
    }

    public function test_wholesale_tenant_cannot_access_listings(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);

        $this->get('/listings')->assertStatus(404);
        $this->get('/listings/mandates')->assertStatus(404);
    }

    public function test_listing_agent_can_access_listed_units_page(): void
    {
        $this->createTenantWithAdmin(['business_mode' => 'realestate']);
        $this->actingAsRole('listing_agent');

        $response = $this->get('/listings');
        $response->assertStatus(200);
    }

    public function test_buyers_agent_can_access_listed_units_page(): void
    {
        $this->createTenantWithAdmin(['business_mode' => 'realestate']);
        $this->actingAsRole('buyers_agent');

        $response = $this->get('/listings');
        $response->assertStatus(200);
    }
}