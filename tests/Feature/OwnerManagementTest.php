<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Property;
use App\Services\OwnerService;
use Tests\TestCase;

class OwnerManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function unit(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'listed',
            'sub_community' => 'Sky Tower',
            'community' => 'Al Reem',
            'city' => 'Abu Dhabi',
            'unit_no' => '101',
            'address' => 'Sky Tower',
            'state' => '',
            'zip_code' => '',
            'marketing_title' => 'Unit',
        ], $overrides));
    }

    public function test_creating_an_owner_from_settings_dedupes_by_normalized_name(): void
    {
        $this->post(route('settings.owners.store'), ['name' => 'Ahmed Al Mansoori', 'phone' => '+971 50 111 2222'])
            ->assertSessionHas('success');
        $this->post(route('settings.owners.store'), ['name' => '  ahmed   al mansoori ', 'email' => 'a@example.com'])
            ->assertSessionHas('warning');

        $this->assertSame(1, Owner::where('tenant_id', $this->tenant->id)->count());

        $owner = Owner::where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('a@example.com', $owner->email, 'richer data fills a missing field');
    }

    public function test_creating_an_owner_adopts_units_with_the_same_free_text_name(): void
    {
        $unit = $this->unit(['owner_name' => 'Legacy Owner']);
        $this->assertNull($unit->owner_id);

        $this->post(route('settings.owners.store'), ['name' => 'legacy owner'])->assertSessionHas('success');

        $this->assertNotNull($unit->fresh()->owner_id);
    }

    public function test_owner_search_returns_matching_owners_only_for_the_tenant(): void
    {
        Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Sara Holding', 'phone' => '+971500000000']);
        Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Someone Else', 'phone' => '+971511111111']);

        $results = $this->getJson(route('inventory.owners-search', ['q' => 'sara']))
            ->assertOk()
            ->json('results');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Sara Holding', $results[0]['label']);
    }

    public function test_the_unit_form_hosts_the_owner_picker_and_add_modal(): void
    {
        $this->get(route('inventory.create'))
            ->assertOk()
            ->assertSee('name="owner_id"', false)
            ->assertSee('id="addOwnerModal"', false)
            ->assertSee(route('inventory.owners-store'), false)
            ->assertSee('ownerFormData', false);
    }

    public function test_picking_an_owner_on_a_unit_links_the_fk_and_snapshot(): void
    {
        $owner = Owner::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Landlord One',
            'phone' => '+971522222222',
            'email' => 'l@example.com',
        ]);

        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'listed',
            'sub_community' => 'Sky Tower',
            'community' => 'Al Reem',
            'city' => 'Abu Dhabi',
            'address' => 'Sky Tower 101',
            'owner_id' => $owner->id,
        ])->assertRedirect();

        $unit = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->orderByDesc('id')
            ->first();

        $this->assertSame($owner->id, $unit->owner_id);
        $this->assertSame('Landlord One', $unit->owner_name);
        $this->assertSame('l@example.com', $unit->owner_email);
    }

    public function test_editing_an_owner_cascades_to_linked_units(): void
    {
        $owner = Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Old Name']);
        $unit = $this->unit(['owner_id' => $owner->id, 'owner_name' => 'Old Name']);

        $this->put(route('settings.owners.update', $owner), ['name' => 'New Name', 'phone' => '+971533333333'])
            ->assertSessionHas('success');

        $unit->refresh();
        $this->assertSame('New Name', $unit->owner_name);
        $this->assertSame('+971533333333', $unit->owner_phone);
    }

    public function test_an_owner_with_units_cannot_be_deleted(): void
    {
        $owner = Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Attached']);
        $this->unit(['owner_id' => $owner->id]);

        $this->delete(route('settings.owners.destroy', $owner))->assertSessionHas('error');

        $this->assertDatabaseHas('owners', ['id' => $owner->id]);
    }

    public function test_an_unused_owner_can_be_deleted(): void
    {
        $owner = Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Unused']);

        $this->delete(route('settings.owners.destroy', $owner))->assertSessionHas('success');

        $this->assertDatabaseMissing('owners', ['id' => $owner->id]);
    }

    public function test_setting_the_office_location_resolves_a_map_pin(): void
    {
        $owner = Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Mapped']);

        app(OwnerService::class)->setOfficeLocation($owner, 'https://www.google.com/maps/@25.2048,55.2708,17z');
        $owner->refresh();

        $this->assertSame('https://www.google.com/maps/@25.2048,55.2708,17z', $owner->map_url);
        $this->assertNotNull($owner->latitude);
        $this->assertNotNull($owner->longitude);
        $this->assertNotNull($owner->mapsDirectionsUrl());
        $this->assertNotNull($owner->wazeUrl());
    }

    public function test_the_owner_picker_create_row_creates_an_owner_and_returns_to_the_unit_form(): void
    {
        $this->post(route('inventory.owners-store'), [
            'name' => 'Created From Unit',
            'phone' => '+971544444444',
            'return' => '/inventory/create',
        ])
            ->assertRedirect('/inventory/create')
            ->assertSessionHasInput('owner_id');

        $this->assertSame(1, Owner::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_owners_settings_screen_lists_owners(): void
    {
        Owner::create(['tenant_id' => $this->tenant->id, 'name' => 'Listed Owner', 'phone' => '+971555555555']);

        $this->get(route('settings.owners.index'))
            ->assertOk()
            ->assertSee('Listed Owner')
            ->assertSee('id="addOwnerModal"', false);
    }
}
