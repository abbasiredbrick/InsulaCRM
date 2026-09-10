<?php

namespace Tests\Feature;

use App\Models\Property;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_index_lists_units(): void
    {
        $property = $this->createProperty([
            'marketing_title' => '2BR Apartment in Dubai Marina',
            'availability' => 'listed',
            'intent' => 'both',
        ]);

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('2BR Apartment in Dubai Marina');
    }

    public function test_store_creates_a_brokerage_unit(): void
    {
        $response = $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'community' => 'Dubai Marina',
            'sub_community' => 'Marina Heights',
            'rera_permit_no' => 'RERA-123-2026',
            'rent_price' => 120000,
            'rent_period' => 'yearly',
            'availability' => 'ready_to_list',
            'marketing_title' => 'Studio in Marina Heights',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'intent' => 'rent',
            'rera_permit_no' => 'RERA-123-2026',
            'availability' => 'ready_to_list',
        ]);

        $property = Property::where('rera_permit_no', 'RERA-123-2026')->first();
        $this->assertSame('Marina Heights Dubai Marina', $property->address);
        $this->assertSame('Dubai Marina', $property->city);
    }

    public function test_store_persists_deposit_and_admin_fee(): void
    {
        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'community' => 'Reem Island',
            'sub_community' => 'Canal Residence',
            'rent_price' => 85000,
            'deposit_amount' => 4250,
            'admin_fee' => 1000,
            'rent_period' => 'yearly',
            'availability' => 'ready_to_list',
            'unit_no' => '502',
        ])->assertRedirect();

        $this->assertDatabaseHas('properties', [
            'deposit_amount' => 4250,
            'admin_fee' => 1000,
        ]);

        $property = Property::where('unit_no', '502')->first();
        $this->get(route('inventory.show', $property))
            ->assertOk()
            ->assertSee('4,250')
            ->assertSee('1,000');
    }

    public function test_show_displays_portal_tracking(): void
    {
        $property = $this->createProperty([
            'rera_permit_no' => 'RERA-9-2026',
            'availability' => 'listed',
            'intent' => 'sale',
            'property_category' => 'villa',
            'list_price' => 5000000,
            'bayut_status' => 'live',
            'bayut_url' => 'https://www.bayut.com/en/ae/properties/demo',
        ]);

        $this->get(route('inventory.show', $property))
            ->assertOk()
            ->assertSee('RERA-9-2026')
            ->assertSee('Bayut')
            ->assertSee('www.bayut.com');
    }

    public function test_update_marks_portal_live(): void
    {
        $property = $this->createProperty([
            'rera_permit_no' => 'RERA-7-2026',
            'availability' => 'ready_to_list',
            'intent' => 'rent',
            'property_category' => 'apartment',
        ]);

        $this->post(route('inventory.portal-status', $property), [
            'portal' => 'dubizzle',
            'status' => 'live',
            'listing_reference' => 'DZ-555',
            'url' => 'https://www.dubizzle.ae/.../property/dz-555',
        ])->assertRedirect(route('inventory.portal'));

        $property->refresh();
        $this->assertSame('live', $property->dubizzle_status);
        $this->assertSame('DZ-555', $property->dubizzle_listing_reference);
    }

    public function test_bayut_csv_export_includes_permit_and_prices(): void
    {
        $this->createProperty([
            'rera_permit_no' => 'RERA-CSV-1',
            'availability' => 'listed',
            'intent' => 'rent',
            'property_category' => 'apartment',
            'rent_price' => 90000,
            'rent_period' => 'yearly',
        ]);

        $response = $this->get(route('inventory.export.bayut'));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('RERA-CSV-1', $csv);
        $this->assertStringContainsString('RENT', $csv);
        $this->assertStringContainsString('90000', $csv);
    }

    public function test_propertyfinder_xml_export(): void
    {
        $this->createProperty([
            'rera_permit_no' => 'RERA-XML-2',
            'availability' => 'ready_to_list',
            'intent' => 'sale',
            'property_category' => 'townhouse',
            'list_price' => 2500000,
        ]);

        $response = $this->get(route('inventory.export.propertyfinder'));
        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('RERA-XML-2', $xml);
        $this->assertStringContainsString('SELL', $xml);
    }

    public function test_advanced_search_filters_by_rent_range(): void
    {
        $this->createProperty([
            'marketing_title' => 'Cheap Studio',
            'intent' => 'rent',
            'availability' => 'ready_to_list',
            'rent_price' => 60000,
            'rent_period' => 'yearly',
        ]);
        $this->createProperty([
            'marketing_title' => 'Premium 3BR',
            'intent' => 'rent',
            'availability' => 'ready_to_list',
            'rent_price' => 180000,
            'rent_period' => 'yearly',
        ]);

        $this->get(route('inventory.index') . '?rent_min=100000&rent_max=200000')
            ->assertOk()
            ->assertDontSee('Cheap Studio')
            ->assertSee('Premium 3BR');
    }

    public function test_advanced_search_filters_by_community_furnishing_and_photos(): void
    {
        $this->createProperty([
            'marketing_title' => 'Furnished Marina Unit',
            'intent' => 'rent',
            'availability' => 'ready_to_list',
            'community' => 'Dubai Marina',
            'sub_community' => 'Marina Heights',
            'furnishing' => 'furnished',
        ]);
        $this->createProperty([
            'marketing_title' => 'Unfurnished Reef',
            'intent' => 'rent',
            'availability' => 'ready_to_list',
            'community' => 'Reem Island',
            'furnishing' => 'unfurnished',
        ]);

        $this->get(route('inventory.index') . '?community=Dubai+Marina&furnishing=furnished')
            ->assertOk()
            ->assertSee('Furnished Marina Unit')
            ->assertDontSee('Unfurnished Reef');
    }

    public function test_destroy_removes_unit(): void
    {
        $property = $this->createProperty();

        $this->delete(route('inventory.destroy', $property))
            ->assertRedirect(route('inventory.index'));

        $this->assertDatabaseMissing('properties', ['id' => $property->id]);
    }
}