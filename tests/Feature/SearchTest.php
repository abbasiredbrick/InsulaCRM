<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Property;
use Tests\TestCase;

class SearchTest extends TestCase
{
    public function test_search_page_loads(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('search', ['q' => 'test']));

        $response->assertStatus(200);
        $response->assertSee('Search Results');
    }

    public function test_search_returns_json_for_ajax(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(route('search', ['q' => 'test']));

        $response->assertStatus(200);
        $response->assertJsonStructure(['results']);
    }

    public function test_search_finds_leads(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['first_name' => 'Searchable', 'last_name' => 'Person']);

        $response = $this->getJson(route('search', ['q' => 'Searchable']));

        $response->assertStatus(200);
        $results = $response->json('results');
        $this->assertTrue(collect($results)->contains('type', 'lead'));
    }

    public function test_search_finds_buyers(): void
    {
        $this->actingAsAdmin();

        Buyer::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'UniqueCompany123',
            'last_name' => 'Buyer',
            'email' => 'john@unique.com',
        ]);

        $response = $this->getJson(route('search', ['q' => 'UniqueCompany123']));

        $results = $response->json('results');
        $this->assertTrue(collect($results)->contains('type', 'buyer'));
    }

    public function test_search_finds_properties(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead();
        Property::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'address' => '999 Unique Searchable St',
            'city' => 'Testville',
            'state' => 'TX',
            'zip_code' => '75001',
        ]);

        $response = $this->getJson(route('search', ['q' => '999 Unique Searchable']));

        $results = $response->json('results');
        $this->assertTrue(collect($results)->contains('type', 'property'));
    }

    public function test_short_query_returns_empty(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(route('search', ['q' => 'a']));

        $response->assertJson(['results' => []]);
    }

    public function test_agents_only_see_their_own_leads(): void
    {
        $this->actingAsAdmin();
        $agent = $this->actingAsRole('agent');

        // Create lead owned by admin
        $lead = $this->createLead(['first_name' => 'AdminOnlyLead', 'agent_id' => $this->adminUser->id]);

        $response = $this->getJson(route('search', ['q' => 'AdminOnlyLead']));

        $results = collect($response->json('results'));
        $leadResults = $results->where('type', 'lead');
        $this->assertCount(0, $leadResults);
    }

    public function test_field_scouts_cannot_search_deals(): void
    {
        $this->actingAsAdmin();
        $scout = $this->actingAsRole('field_scout');

        $response = $this->getJson(route('search', ['q' => 'anything']));

        $results = collect($response->json('results'));
        $dealResults = $results->where('type', 'deal');
        $this->assertCount(0, $dealResults);
    }

    public function test_scope_inventory_returns_only_properties(): void
    {
        $this->actingAsAdmin();
        $this->createLead(['first_name' => 'ScopeLead', 'last_name' => 'Person']);
        Property::create([
            'tenant_id' => $this->tenant->id,
            'address' => 'Scope Unit 42, Testville',
            'city' => 'Testville',
            'state' => 'TX',
            'zip_code' => '75001',
        ]);

        $response = $this->getJson(route('search', ['q' => 'Scope', 'scope' => 'inventory']));

        $results = collect($response->json('results'));
        $this->assertTrue($results->count() > 0);
        $this->assertFalse($results->contains('type', 'lead'));
        $this->assertTrue($results->contains('type', 'property'));
    }

    public function test_scope_leads_returns_only_leads(): void
    {
        $this->actingAsAdmin();
        $this->createLead(['first_name' => 'ScopeLead', 'last_name' => 'Person']);
        Property::create([
            'tenant_id' => $this->tenant->id,
            'address' => 'Scope Unit 42, Testville',
            'city' => 'Testville',
            'state' => 'TX',
            'zip_code' => '75001',
        ]);

        $response = $this->getJson(route('search', ['q' => 'Scope', 'scope' => 'leads']));

        $results = collect($response->json('results'));
        $this->assertTrue($results->contains('type', 'lead'));
        $this->assertFalse($results->contains('type', 'property'));
    }

    public function test_inventory_search_matches_community_and_unit_fields(): void
    {
        $this->actingAsAdmin();
        Property::create([
            'tenant_id' => $this->tenant->id,
            'marketing_title' => '2BR in Palm Jumeirah',
            'community' => 'Palm Jumeirah',
            'sub_community' => 'Bey View Tower',
            'unit_no' => '901',
            'address' => 'Bey View Tower, Palm Jumeirah',
            'city' => 'Dubai',
            'state' => 'AE',
            'zip_code' => '00000',
        ]);

        $response = $this->getJson(route('search', ['q' => 'Bey View', 'scope' => 'inventory']));

        $results = collect($response->json('results'));
        $property = $results->where('type', 'property')->first();
        $this->assertNotNull($property);
        // Wholesale mode links property matches to properties.show
        $this->assertStringContainsString('/properties/', $property['url']);
    }

    public function test_realestate_property_results_link_to_inventory_show(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        Property::create([
            'tenant_id' => $this->tenant->id,
            'marketing_title' => 'Marina Unit One',
            'community' => 'Dubai Marina',
            'address' => 'Marina Unit One, Dubai Marina',
            'city' => 'Dubai',
            'state' => 'AE',
            'zip_code' => '00000',
        ]);

        $response = $this->getJson(route('search', ['q' => 'Marina Unit', 'scope' => 'inventory']));

        $results = collect($response->json('results'));
        $property = $results->where('type', 'property')->first();
        $this->assertNotNull($property);
        $this->assertStringContainsString('/inventory/', $property['url']);
    }
}
