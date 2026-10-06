<?php

namespace Tests\Feature;

use App\Models\AvailabilitySource;
use App\Models\MapLocation;
use App\Models\Property;
use App\Services\AvailabilityIngestService;
use App\Services\MapLocationService;
use Tests\TestCase;

class InventoryMapLocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function createSource(array $overrides = []): AvailabilitySource
    {
        return AvailabilitySource::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reelam',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'tab', 'has_header' => true],
            'column_map' => [
                'Building' => 'building',
                'Unit No' => 'unit_no',
                'Features' => 'features',
                'Rent' => 'rent',
                'Location Link' => 'map_url',
            ],
            'status_map' => ['vacant' => 'ready_to_list'],
        ], $overrides));
    }

    protected function unit(string $subCommunity = 'Bey View Tower', string $unitNo = '1301', ?string $community = 'Abu Dhabi Mall', ?string $city = 'Abu Dhabi'): Property
    {
        return Property::create([
            'tenant_id' => $this->tenant->id,
            'intent' => 'rent',
            'availability' => 'listed',
            'sub_community' => $subCommunity,
            'community' => $community,
            'city' => $city,
            'unit_no' => $unitNo,
            'bedrooms' => 2,
            'rent_price' => 240000,
            'address' => "{$subCommunity} {$community}",
            'state' => '',
            'zip_code' => '',
            'marketing_title' => '2BR in '.$subCommunity,
        ]);
    }

    protected function location(string $subCommunity, ?string $mapUrl, ?string $mapQuery, ?string $community = 'Abu Dhabi Mall', ?string $city = 'Abu Dhabi'): MapLocation
    {
        return MapLocation::create([
            'tenant_id' => $this->tenant->id,
            'sub_community' => $subCommunity,
            'community' => $community,
            'city' => $city,
            'map_url' => $mapUrl,
            'map_query' => $mapQuery,
        ]);
    }

    public function test_url_column_in_a_source_sheet_is_stored_as_a_map_location(): void
    {
        $source = $this->createSource();
        $service = new AvailabilityIngestService;

        $table = $service->parseText(implode("\n", [
            "Building\tUnit No\tFeatures\tRent\tLocation Link",
            "Bey View Tower\t1601\t4 BR + Maids\t240,000\thttps://www.google.com/maps/place/Al+Ghadeer",
        ]), ['delimiter' => 'tab', 'has_header' => true]);

        $result = $service->ingest($source, $table['rows'], $this->tenant->id, contextUrl: $table['context_url'] ?? null);

        $this->assertSame(1, $result['created']);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Bey View Tower')
            ->first();

        $this->assertSame('https://www.google.com/maps/place/Al+Ghadeer', $location->map_url);
        $this->assertSame('Bey View Tower, Abu Dhabi', $location->map_query);
    }

    public function test_the_header_detection_pins_a_url_valued_column_to_map_location(): void
    {
        $source = $this->createSource(['column_map' => []]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(implode("\n", [
            'Building,Unit,Location',
            'Bey View Tower,1601,https://www.google.com/maps/place/Al+Ghadeer',
        ]), ['delimiter' => 'comma', 'has_header' => true]);

        $result = $service->ingest($source, $table['rows'], $this->tenant->id);

        $this->assertSame(1, $result['created']);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Bey View Tower')
            ->first();

        $this->assertSame('https://www.google.com/maps/place/Al+Ghadeer', $location->map_url);
    }

    public function test_a_pre_table_url_line_is_applied_to_the_building(): void
    {
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AY',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'tab', 'has_header' => false, 'inherit_columns' => ['col0']],
            'column_map' => [
                'col0' => 'building',
                'col1' => 'unit_no',
                'col2' => 'features',
                'col3' => 'rent',
                'col4' => 'status',
            ],
            'status_map' => ['vacant' => 'ready_to_list'],
        ]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(implode("\n", [
            'https://maps.app.goo.gl/AbCdEf',
            'Al Rihan Heights	501	1 BR + Balcony	125,000	Vacant',
            'Al Rihan Heights	502	2 BR	160,000	Vacant',
        ]), ['delimiter' => 'tab', 'has_header' => false]);

        $this->assertSame('https://maps.app.goo.gl/AbCdEf', $table['context_url']);

        $result = $service->ingest($source, $table['rows'], $this->tenant->id, contextUrl: $table['context_url']);

        $this->assertSame(2, $result['created']);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Al Rihan Heights')
            ->first();

        $this->assertSame('https://maps.app.goo.gl/AbCdEf', $location->map_url);
    }

    public function test_sheets_without_a_link_get_a_generated_google_maps_search_url(): void
    {
        $source = $this->createSource(['column_map' => [
            'Building' => 'building',
            'Unit No' => 'unit_no',
            'Features' => 'features',
            'Rent' => 'rent',
        ]]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(implode("\n", [
            "Building\tUnit No\tFeatures\tRent",
            "Bey View Tower\t1301\t4 BR\t240,000",
        ]), ['delimiter' => 'tab', 'has_header' => true]);

        $service->ingest($source, $table['rows'], $this->tenant->id);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Bey View Tower')
            ->first();

        $maps = app(MapLocationService::class);
        $this->assertSame($maps->searchUrl('Bey View Tower, Abu Dhabi'), $location->map_url);
        $this->assertSame('Bey View Tower, Abu Dhabi', $location->map_query);
    }

    public function test_reimport_keeps_a_manually_corrected_location(): void
    {
        $this->location('Bey View Tower', 'https://www.google.com/maps/place/Correct+Spot', 'Correct Spot, Abu Dhabi');
        $source = $this->createSource(['column_map' => [
            'Building' => 'building',
            'Unit No' => 'unit_no',
            'Features' => 'features',
            'Rent' => 'rent',
        ]]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(implode("\n", [
            "Building\tUnit No\tFeatures\tRent",
            "Bey View Tower\t1301\t4 BR\t240,000",
        ]), ['delimiter' => 'tab', 'has_header' => true]);

        $service->ingest($source, $table['rows'], $this->tenant->id);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Bey View Tower')
            ->first();

        $this->assertSame('https://www.google.com/maps/place/Correct+Spot', $location->map_url);
        $this->assertSame('Correct Spot, Abu Dhabi', $location->map_query);
    }

    public function test_a_building_named_other_gets_a_city_level_link(): void
    {
        $source = $this->createSource(['column_map' => [
            'Building' => 'building',
            'Unit No' => 'unit_no',
            'Features' => 'features',
            'Rent' => 'rent',
        ]]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(implode("\n", [
            "Building\tUnit No\tFeatures\tRent",
            "Other\t1301\t4 BR\t240,000",
        ]), ['delimiter' => 'tab', 'has_header' => true]);

        $service->ingest($source, $table['rows'], $this->tenant->id);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Other')
            ->first();

        $maps = app(MapLocationService::class);
        $this->assertSame($maps->searchUrl('Abu Dhabi'), $location->map_url);
        $this->assertSame('Abu Dhabi', $location->map_query);
    }

    public function test_map_url_only_accepts_http_links(): void
    {
        $maps = app(MapLocationService::class);

        $this->assertSame('https://www.google.com/maps/place/X', $maps->cleanUrl('https://www.google.com/maps/place/X'));
        $this->assertSame('https://maps.google.com/?q=10', $maps->cleanUrl('maps.google.com/?q=10'));
        $this->assertSame('https://www.google.com/maps/place/X', $maps->cleanUrl('  https://www.google.com/maps/place/X  '));
        $this->assertNull($maps->cleanUrl('javascript:alert(1)'));
        $this->assertNull($maps->cleanUrl('data:text/html,hi'));
        $this->assertNull($maps->cleanUrl('Bey View Tower'));
    }

    public function test_map_links_populate_from_search_queries_and_render_public_links(): void
    {
        $maps = app(MapLocationService::class);
        $query = 'Bey View Tower, Abu Dhabi Mall, Abu Dhabi';

        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query),
            $maps->searchUrl($query)
        );
        $this->assertSame(
            'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($query),
            $maps->directionsUrl($query)
        );
        $this->assertStringContainsString('output=embed', $maps->embedUrl($query));
        $this->assertStringContainsString('embed/v1/place', $maps->embedUrl($query, 'KEY123'));
        $this->assertStringContainsString('key=KEY123', $maps->embedUrl($query, 'KEY123'));
        $this->assertSame('Bey View Tower, Abu Dhabi', $maps->queryFor(['Bey View Tower', 'Bey View Tower', 'Other', 'Abu Dhabi']));
    }

    public function test_backfill_generates_links_for_buildings_missing_them_only(): void
    {
        $withLink = $this->location('Bey View Tower', 'https://www.google.com/maps/place/Keep+Me', 'Keep Me, Abu Dhabi');
        $this->unit('Al Rihan Heights', '902', 'Rihan Heights');

        $updated = app(MapLocationService::class)->backfillForTenant($this->tenant->id);

        $this->assertSame(1, $updated);

        $this->assertSame('Keep Me, Abu Dhabi', $withLink->refresh()->map_query);

        $maps = app(MapLocationService::class);
        $generated = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Al Rihan Heights')
            ->first();

        $this->assertSame($maps->searchUrl('Al Rihan Heights, Rihan Heights, Abu Dhabi'), $generated->map_url);
        $this->assertSame('Al Rihan Heights, Rihan Heights, Abu Dhabi', $generated->map_query);
    }

    public function test_admin_can_save_clear_and_auto_generate_a_building_location(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');

        // Save by hand — a place name turns into a search link.
        $this->post(route('availability-sources.locations-apply'), [
            'building' => 'Bey View Tower',
            'community' => 'Abu Dhabi Mall',
            'city' => 'Abu Dhabi',
            'location' => 'Bey View Tower, Abu Dhabi Mall',
            'mode' => 'save',
        ])->assertSessionHasNoErrors();

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Bey View Tower')
            ->first();
        $this->assertNotNull($location);
        $this->assertStringContainsString('api=1&query=', $location->map_url);

        // Clear drops it.
        $this->post(route('availability-sources.locations-apply'), [
            'building' => 'Bey View Tower',
            'community' => 'Abu Dhabi Mall',
            'city' => 'Abu Dhabi',
            'mode' => 'clear',
            'location' => '',
        ]);

        $this->assertNull($location->refresh()->map_url);

        // Auto regenerates for the building now that it has none.
        $this->post(route('availability-sources.locations-apply'), [
            'building' => 'Bey View Tower',
            'community' => 'Abu Dhabi Mall',
            'city' => 'Abu Dhabi',
            'mode' => 'auto',
            'location' => '',
        ]);

        $this->assertNotNull($location->refresh()->map_url);
        $this->assertSame('Bey View Tower, Abu Dhabi Mall, Abu Dhabi', $location->map_query);
    }

    public function test_admin_can_generate_links_for_the_entire_inventory(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');

        $this->post(route('availability-sources.locations-apply'), [
            'building' => '__all__',
            'mode' => 'auto',
            'location' => '',
        ])->assertSessionHasNoErrors();

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Bey View Tower')
            ->first();

        $this->assertNotNull($location);
        $this->assertSame('Bey View Tower, Abu Dhabi Mall, Abu Dhabi', $location->map_query);
    }

    public function test_the_locations_screen_lists_each_building_with_its_current_link(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $this->location('Bey View Tower', 'https://www.google.com/maps/place/Al+Ghadeer', 'Bey View Tower, Abu Dhabi Mall');

        $this->get(route('availability-sources.locations'))
            ->assertOk()
            ->assertSee('Bey View Tower')
            ->assertSee('Al+Ghadeer', false);
    }

    public function test_the_public_share_page_embeds_the_map_and_links_each_unit(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $this->location('Bey View Tower', 'https://www.google.com/maps/place/Al+Ghadeer', 'Bey View Tower, Abu Dhabi Mall, Abu Dhabi');
        $this->tenant->update(['google_maps_embed_key' => 'TEST_EMBED_KEY']);

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);
        $cookie = collect($verify->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'keystone_share'));

        $html = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company')
            ->assertOk()
            ->assertSee('embed/v1/place', false)
            ->assertSee('key=TEST_EMBED_KEY', false)
            ->assertSee('View on map')
            ->assertSee('Directions')
            ->assertSee('Bey View Tower, Abu Dhabi Mall, Abu Dhabi');

        // The interest button now opens the unit modal instead of posting directly.
        $html->assertSee('I\'m interested in this unit');
    }

    public function test_the_share_page_hides_the_embed_without_an_api_key(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $this->location('Bey View Tower', 'https://www.google.com/maps/place/Al+Ghadeer', 'Bey View Tower, Abu Dhabi Mall, Abu Dhabi');

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);
        $cookie = collect($verify->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'keystone_share'));

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company')
            ->assertOk()
            ->assertDontSee('output=embed', false)
            ->assertDontSee('embed/v1/place', false)
            ->assertSee('View on map');
    }

    public function test_manually_creating_a_unit_generates_a_location_for_a_new_building(): void
    {
        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'draft',
            'sub_community' => 'Skyline Heights',
            'community' => 'Downtown',
            'city' => 'Abu Dhabi',
            'unit_no' => 'A1201',
            'bedrooms' => 2,
            'rent_price' => 150000,
        ])->assertRedirect()
            ->assertSessionHas('warning');

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Skyline Heights')
            ->first();

        $maps = app(MapLocationService::class);
        $this->assertNotNull($location);
        $this->assertSame($maps->searchUrl('Skyline Heights, Downtown, Abu Dhabi'), $location->map_url);
        $this->assertSame('Skyline Heights, Downtown, Abu Dhabi', $location->map_query);
    }
}
