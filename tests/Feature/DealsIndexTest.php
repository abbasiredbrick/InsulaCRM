<?php

namespace Tests\Feature;

use Tests\TestCase;

class DealsIndexTest extends TestCase
{
    public function test_index_lists_deals_with_open_links(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_received']);

        $response = $this->get('/deals');

        $response->assertStatus(200);
        $response->assertSee($deal->title);
        $response->assertSee("/pipeline/{$deal->id}");
        $response->assertSee('List');
        $response->assertSee('Kanban');
    }

    public function test_index_agent_only_sees_own_deals(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale']);
        $admin = $this->adminUser;

        $agent = $this->createUserWithRole('agent');

        $own = $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_presented', 'agent_id' => $agent->id]);
        $other = $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_presented', 'agent_id' => $admin->id]);

        $this->actingAs($agent);

        $response = $this->get('/deals');

        $response->assertStatus(200);
        $response->assertSee($own->lead->full_name);
        $response->assertDontSee($other->lead->full_name);
    }

    public function test_index_filters_by_deal_type_and_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $rent = $this->createDeal(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $sale = $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_received']);

        $this->get('/deals?deal_type=rent')
            ->assertStatus(200)
            ->assertSee($rent->lead->full_name)
            ->assertDontSee($sale->lead->full_name);

        $this->get('/deals?deal_type=rent&stage=offer_received')
            ->assertStatus(200)
            ->assertDontSee($rent->lead->full_name);
    }

    public function test_index_searches_by_lead_name_and_property(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $deal->lead->update(['first_name' => 'Zorander', 'last_name' => 'Qwix']);

        $this->get('/deals?search=Zorander')
            ->assertStatus(200)
            ->assertSee($deal->lead->full_name);
    }

    public function test_index_falls_back_to_default_sort_for_unknown_sort_key(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal(['deal_type' => 'sale', 'stage' => 'offer_received']);

        $this->get('/deals?sort=drop&direction=asc')->assertStatus(200);
        $this->get('/deals?direction=sideways')->assertStatus(200);
    }
}
