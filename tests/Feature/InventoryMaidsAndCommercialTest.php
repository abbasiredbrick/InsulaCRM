<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Property;
use App\Services\AvailabilityIngestService;
use App\Support\InventorySearchParser;
use Tests\TestCase;

class InventoryMaidsAndCommercialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate', 'timezone' => 'Asia/Dubai', 'country' => 'AE']);
    }

    protected function createSource(array $overrides = [])
    {
        return \App\Models\AvailabilitySource::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relaam Commercial',
            'default_city' => 'Abu Dhabi',
            'column_map' => [],
        ], $overrides));
    }

    // ── Import: commercial categories and maid's rooms ──────────────────────

    public function test_import_reads_commercial_units_that_were_reclassified_as_apartments(): void
    {
        $service = new AvailabilityIngestService;
        $rows = [
            ['Unit No' => '3SW', 'Building' => '7274A', 'Community' => 'Al Bateen', 'Unit Features' => 'SHOWROOM D', 'Rent' => '120,000', 'Status' => 'Vacant'],
            ['Unit No' => '2SG', 'Building' => '1875', 'Community' => 'Al Danah', 'Unit Features' => 'STORAGE D', 'Rent' => '60,000', 'Status' => 'Vacant'],
            ['Unit No' => 'ADU-ALDF-Plot65', 'Building' => 'Private Department Building', 'Community' => 'Al Khalidiyah', 'Unit Features' => 'Full Building', 'Rent' => '1,500,000', 'Status' => 'Vacant'],
            ['Unit No' => 'Retail 3', 'Building' => 'Al Hattan Residence', 'Community' => 'AL Raha', 'Unit Features' => '165 Square Meters', 'Rent' => '250,000', 'Status' => 'Vacant'],
        ];

        $result = $service->ingest($this->createSource(), $rows, $this->tenant->id);

        $this->assertSame(4, $result['created']);

        $byRef = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->get()->keyBy('source_unit_ref');

        $this->assertSame('showroom', $byRef['3SW']->property_category);
        $this->assertSame('Showroom for Rent in 7274A', $byRef['3SW']->marketing_title);

        $this->assertSame('warehouse', $byRef['2SG']->property_category);
        $this->assertSame('Warehouse for Rent in 1875', $byRef['2SG']->marketing_title);

        $this->assertSame('commercial_building', $byRef['ADU-ALDF-Plot65']->property_category);
        $this->assertSame('Commercial Building for Rent in Private Department Building', $byRef['ADU-ALDF-Plot65']->marketing_title);

        // The unit number alone says retail; no "shop" token on the sheet.
        $this->assertSame('shop', $byRef['Retail 3']->property_category);
        $this->assertSame('Shop for Rent in Al Hattan Residence', $byRef['Retail 3']->marketing_title);
    }

    public function test_import_flags_a_maids_room_and_keeps_it_out_of_the_bedroom_count(): void
    {
        $service = new AvailabilityIngestService;
        $rows = [
            ['Unit No' => '1201', 'Building' => 'Bey View Tower', 'Community' => 'Abu Dhabi Mall', 'Unit Features' => '4 BR + Maids room', 'Rent' => '240,000', 'Status' => 'Vacant'],
            ['Unit No' => '903', 'Building' => 'The Bridges', 'Community' => 'Tower 2', 'Unit Features' => '1 BR', 'Rent' => '74,000', 'Status' => 'Vacant'],
            ['Unit No' => '501', 'Building' => 'RDK Towers Najmat Tower I', 'Community' => 'RDK Towers Najmat', 'Unit Features' => '2BHK + MAID - BALCONY', 'Rent' => '180,000', 'Status' => 'Vacant'],
        ];

        $service->ingest($this->createSource(), $rows, $this->tenant->id);

        $byRef = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->get()->keyBy('source_unit_ref');

        $this->assertSame(4, $byRef['1201']->bedrooms);
        $this->assertTrue($byRef['1201']->maids_room);
        $this->assertSame('4BR + Maid Apartment for Rent in Bey View Tower', $byRef['1201']->marketing_title);

        // "+ Maid" is a feature, not a bedroom — the count stays 2.
        $this->assertSame(2, $byRef['501']->bedrooms);
        $this->assertTrue($byRef['501']->maids_room);

        $this->assertFalse($byRef['903']->maids_room);
        $this->assertSame('1BR Apartment for Rent in The Bridges', $byRef['903']->marketing_title);
    }

    // ── Labels ──────────────────────────────────────────────────────────────

    public function test_a_shop_with_no_size_prints_its_category_not_a_bare_for_rent(): void
    {
        $shop = new Property(['property_category' => 'shop', 'bedrooms' => null, 'sub_community' => 'Marafid', 'community' => 'Al Reem Island', 'intent' => 'rent']);

        $this->assertSame('Shop for Rent in Marafid, Al Reem Island', $shop->display_name);

        $office = new Property(['property_category' => 'office', 'bedrooms' => null, 'sub_community' => 'Building 10', 'intent' => 'rent']);
        $this->assertSame('Office for Rent in Building 10', $office->display_name);
    }

    // ── Search and share filters ────────────────────────────────────────────

    public function test_free_text_search_supports_maids_room(): void
    {
        Property::create([
            'tenant_id' => $this->tenant->id, 'intent' => 'rent', 'property_category' => 'apartment',
            'availability' => 'ready_to_list', 'bedrooms' => 2, 'maids_room' => true,
            'sub_community' => 'Burj Al Shams', 'unit_no' => '2506',
            'address' => 'Burj Al Shams Abu Dhabi', 'city' => 'Abu Dhabi', 'state' => '', 'zip_code' => '',
        ]);
        Property::create([
            'tenant_id' => $this->tenant->id, 'intent' => 'rent', 'property_category' => 'apartment',
            'availability' => 'ready_to_list', 'bedrooms' => 2, 'maids_room' => false,
            'sub_community' => 'Another Tower', 'unit_no' => '1001',
            'address' => 'Another Tower Abu Dhabi', 'city' => 'Abu Dhabi', 'state' => '', 'zip_code' => '',
        ]);

        foreach (['maids room', '2BR + Maid', 'maid'] as $term) {
            $query = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id);
            InventorySearchParser::apply($query, $term);

            $units = $query->get();
            $this->assertCount(1, $units, "term: {$term}");
            $this->assertTrue($units->first()->maids_room);
        }
    }

    public function test_share_page_filters_and_badges_maids_room_units(): void
    {
        $maid = Property::create([
            'tenant_id' => $this->tenant->id, 'intent' => 'rent', 'market_class' => 'ready',
            'property_category' => 'apartment', 'availability' => 'ready_to_list',
            'bedrooms' => 2, 'maids_room' => true, 'sub_community' => 'Burj Al Shams',
            'community' => 'Al Reem Island', 'unit_no' => '2506', 'rent_price' => 120000,
            'address' => 'Burj Al Shams Abu Dhabi', 'city' => 'Abu Dhabi', 'state' => '', 'zip_code' => '',
        ]);
        Property::create([
            'tenant_id' => $this->tenant->id, 'intent' => 'rent', 'market_class' => 'ready',
            'property_category' => 'apartment', 'availability' => 'ready_to_list',
            'bedrooms' => 2, 'maids_room' => false, 'sub_community' => 'Sun Tower',
            'community' => 'Rihan Heights', 'unit_no' => '1001', 'rent_price' => 90000,
            'address' => 'Sun Tower Abu Dhabi', 'city' => 'Abu Dhabi', 'state' => '', 'zip_code' => '',
        ]);

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Dana',
            'phone' => '+9715099880011',
        ]);
        $cookie = collect($verify->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'keystone_share'));

        $inventory = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company?maids_room=1');

        $inventory->assertOk()
            ->assertSee($maid->unitLabel())
            ->assertSee(__("Maid's room"))
            ->assertDontSee('Sun Tower');
    }

    public function test_the_maids_room_filter_is_captured_in_the_lead_note(): void
    {
        $verify = $this->post('/s/test-company/verify?maids_room=1', [
            'first_name' => 'Mona',
            'phone' => '+9715099880012',
        ]);

        $verify->assertRedirect('/s/test-company?maids_room=1');

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($lead);
        $this->assertStringContainsString("Maid's room", $lead->notes);
        $this->assertSame(['maids_room' => '1'], $lead->custom_fields['share_link_filters']);
    }
}
