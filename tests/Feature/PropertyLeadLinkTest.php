<?php

namespace Tests\Feature;

use App\Models\Lead;
use Tests\TestCase;

class PropertyLeadLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function createLinkedLead(string $firstName = 'Ahmed', string $lastName = 'Khan'): Lead
    {
        return $this->createLead([
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => '+971 50 111 2222',
        ]);
    }

    protected function createUnit(array $overrides = []): \App\Models\Property
    {
        return $this->createProperty(array_merge([
            'intent'            => 'rent',
            'market_class'      => 'ready',
            'property_category' => 'apartment',
            'availability'      => 'draft',
            'rera_permit_no'    => 'RERA-LK-1',
        ], $overrides));
    }

    protected function leadPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name'  => 'New',
            'last_name'   => 'Lead',
            'phone'       => '+971 50 333 4444',
            'email'       => 'newlead@example.com',
            'lead_source' => 'website',
            'status'      => 'new',
            'temperature' => 'cold',
            'agent_id'    => $this->adminUser->id,
        ], $overrides);
    }

    public function test_creating_a_lead_links_selected_inventory_units(): void
    {
        $unitOne = $this->createUnit(['marketing_title' => 'Marina 1']);
        $unitTwo = $this->createUnit(['marketing_title' => 'Marina 2']);

        $this->post(route('leads.store'), $this->leadPayload([
            'linked_units' => [$unitOne->id, $unitTwo->id],
        ]))->assertRedirect();

        $lead = Lead::where('email', 'newlead@example.com')->firstOrFail();

        $this->assertSame(
            [$unitOne->id, $unitTwo->id],
            $lead->properties->pluck('id')->sort()->values()->all()
        );
        $this->assertDatabaseHas('lead_property', ['lead_id' => $lead->id, 'property_id' => $unitOne->id]);
        $this->assertDatabaseHas('lead_property', ['lead_id' => $lead->id, 'property_id' => $unitTwo->id]);
    }

    public function test_creating_a_lead_ignores_units_from_another_tenant(): void
    {
        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Other Company', 'slug' => 'other-company-' . uniqid(), 'email' => 'other' . uniqid() . '@test.com', 'status' => 'active',
        ]);
        $foreignUnit = \App\Models\Property::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $this->post(route('leads.store'), $this->leadPayload([
            'linked_units' => [$foreignUnit->id, 999999],
        ]))->assertRedirect();

        $lead = Lead::where('email', 'newlead@example.com')->firstOrFail();

        $this->assertTrue($lead->properties->isEmpty());
    }

    public function test_updating_a_lead_syncs_linked_units(): void
    {
        $unitA = $this->createUnit(['marketing_title' => 'Unit A']);
        $unitB = $this->createUnit(['marketing_title' => 'Unit B']);

        $lead = $this->createLinkedLead();
        $lead->properties()->attach([$unitA->id, $unitB->id]);

$this->put(route('leads.update', $lead), $this->leadPayload([
            'first_name'   => $lead->first_name,
            'last_name'    => $lead->last_name,
            'phone'        => $lead->phone,
            'lead_source'  => 'website',
            'status'       => 'new',
            'temperature'  => 'cold',
            'linked_units' => [$unitB->id],
        ]))->assertRedirect();

        $this->assertSame([$unitB->id], $lead->properties()->pluck('properties.id')->all());
        $this->assertDatabaseMissing('lead_property', ['lead_id' => $lead->id, 'property_id' => $unitA->id]);
    }

    public function test_lead_profile_can_link_and_unlink_a_unit(): void
    {
        $unit = $this->createUnit(['marketing_title' => 'Downtown Loft']);
        $lead = $this->createLinkedLead();

        $this->post(route('leads.property.link', $lead), ['property_id' => $unit->id])
            ->assertRedirect();

        $this->assertDatabaseHas('lead_property', ['lead_id' => $lead->id, 'property_id' => $unit->id]);

        $this->delete(route('leads.property.unlink', [$lead, $unit]))
            ->assertRedirect();

        $this->assertDatabaseMissing('lead_property', ['lead_id' => $lead->id, 'property_id' => $unit->id]);
    }

    public function test_inventory_search_returns_matching_units_as_json(): void
    {
        $unit = $this->createUnit([
            'marketing_title' => 'Exclusive Palm Frond Villa',
            'sub_community'   => 'Palm Jumeirah',
        ]);

        $this->createUnit(['marketing_title' => 'Studio in JLT']);

        $this->get(route('inventory.search') . '?q=Palm+Frond')
            ->assertOk()
            ->assertJsonFragment(['id' => $unit->id])
            ->assertJsonMissing(['id' => $unit->id + 1]);
    }

    public function test_inventory_form_has_no_lead_linking_controls(): void
    {
        $this->get(route('inventory.create'))
            ->assertOk()
            ->assertDontSee('lead_ids')
            ->assertDontSee('Linked Leads');
    }

    public function test_inventory_index_shows_linked_leads(): void
    {
        $unit = $this->createUnit(['marketing_title' => 'JBR Penthouse']);
        $lead = $this->createLinkedLead();
        $unit->leads()->attach($lead->id);

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('JBR Penthouse')
            ->assertSee('Ahmed Khan');
    }

    public function test_lead_profile_shows_linked_inventory_units(): void
    {
        $unit = $this->createUnit(['marketing_title' => 'Downtown Loft']);
        $lead = $this->createLinkedLead();
        $unit->leads()->attach($lead->id);

        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Linked Inventory Units')
            ->assertSee('Downtown Loft');
    }

    public function test_properties_nav_is_hidden_in_realestate_mode(): void
    {
        $this->createUnit();

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertDontSee('href="/properties"');
    }
}