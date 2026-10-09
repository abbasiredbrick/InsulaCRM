<?php

namespace Tests\Feature;

use App\Models\AvailabilitySource;
use App\Models\Community;
use App\Models\MapLocation;
use App\Models\Property;
use App\Services\AvailabilityIngestService;
use App\Services\MapLocationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    public function test_map_location_url_columns_are_text_so_long_google_links_survive(): void
    {
        // A real Google "place" URL embeds the pin, zoom and query in the path
        // and overran the old VARCHAR(500) on MySQL, failing the save outright.
        // SQLite ignores declared lengths, so guard the column type directly.
        $this->assertSame('text', Schema::getColumnType('map_locations', 'map_url'));
        $this->assertSame('text', Schema::getColumnType('map_locations', 'map_query'));
    }

    public function test_a_long_google_place_url_is_saved_whole(): void
    {
        $this->unit('Bloom Living Cordoba', '101', 'Bloom Living');
        $location = $this->location('Bloom Living Cordoba', null, null, 'Bloom Living');

        $url = 'https://www.google.com/maps/place/Bloom+Living+Cordoba/@24.4165173,54.5546245,21587m/'
            .'data=!3m1!1e3'.str_repeat('!4m10!1m2!2m1!1sBloom+Living+-+Cordoba,+Zayed+City', 10);

        $this->assertGreaterThan(500, strlen($url));

        $this->post(route('settings.map-locations.set-location', $location), [
            'mode' => 'save',
            'location' => $url,
        ])->assertSessionHasNoErrors();

        $this->assertSame($url, $location->refresh()->map_url);
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
        $this->assertSame(
            'https://maps.google.com/maps?z=16&q='.rawurlencode($query).'&output=embed',
            $maps->embedUrl($query)
        );
        // A shared key must not change the embed: the legacy output=embed
        // endpoint ignores keys, and the keyed v1/place API 403s otherwise.
        $this->assertSame('https://maps.google.com/maps?z=16&q='.rawurlencode($query).'&output=embed', $maps->embedUrl($query, 'KEY123'));
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
        $location = $this->location('Bey View Tower', null, 'Bey View Tower, Abu Dhabi Mall');

        // Save by hand — a place name turns into a search link.
        $this->post(route('settings.map-locations.set-location', $location), [
            'mode' => 'save',
            'location' => 'Bey View Tower, Abu Dhabi Mall',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($location->refresh()->map_url);
        $this->assertStringContainsString('api=1&query=', $location->map_url);

        // Clear drops it.
        $this->post(route('settings.map-locations.set-location', $location), [
            'mode' => 'clear',
            'location' => '',
        ]);

        $this->assertNull($location->refresh()->map_url);

        // Auto regenerates for the building now that it has none.
        $this->post(route('settings.map-locations.set-location', $location), [
            'mode' => 'auto',
            'location' => '',
        ]);

        $this->assertNotNull($location->refresh()->map_url);
        $this->assertSame('Bey View Tower, Abu Dhabi Mall, Abu Dhabi', $location->map_query);
    }

    public function test_admin_can_generate_links_for_the_entire_inventory(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');

        $this->post(route('settings.map-locations.generate-all'))
            ->assertSessionHasNoErrors();

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

        $this->get(route('settings.map-locations.index'))
            ->assertOk()
            ->assertSee('Bey View Tower')
            ->assertSee('Al+Ghadeer', false);
    }

    public function test_the_locations_screen_uses_a_single_add_location_form(): void
    {
        Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Al Reem Island', 'city' => 'Abu Dhabi']);

        $this->get(route('settings.map-locations.index'))
            ->assertOk()
            ->assertSee('Locations')
            ->assertSee('Add Location')
            ->assertSee(route('settings.map-locations.locations.store'), false)
            ->assertSee('Add a new city', false)
            ->assertSee('Add a new community', false)
            ->assertDontSee('Add Community')
            ->assertDontSee('Add Building');
    }

    public function test_add_location_creates_the_city_community_building_chain(): void
    {
        $this->post(route('settings.map-locations.locations.store'), [
            'city' => 'Abu Dhabi',
            'community' => 'Al Reem Island',
            'sub_community' => 'Sky Tower',
        ])->assertSessionHasNoErrors();

        $community = Community::where('tenant_id', $this->tenant->id)
            ->where('name', 'Al Reem Island')
            ->firstOrFail();
        $this->assertSame('Abu Dhabi', $community->city);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Sky Tower')
            ->firstOrFail();

        $this->assertSame($community->id, $location->community_id);
        $this->assertSame('Al Reem Island', $location->community);
        $this->assertSame('Abu Dhabi', $location->city);
    }

    public function test_add_location_can_create_a_brand_new_city_and_community(): void
    {
        $this->post(route('settings.map-locations.locations.store'), [
            'city' => '__new__',
            'new_city' => 'Al Ain',
            'community' => '__new__',
            'new_community' => 'Al Jimi',
            'sub_community' => 'Jimi Tower',
        ])->assertSessionHasNoErrors();

        $community = Community::where('tenant_id', $this->tenant->id)
            ->where('name', 'Al Jimi')
            ->firstOrFail();
        $this->assertSame('Al Ain', $community->city);

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Jimi Tower')
            ->firstOrFail();
        $this->assertSame('Al Ain', $location->city);
        $this->assertSame($community->id, $location->community_id);
    }

    public function test_add_location_reuses_a_similar_building_instead_of_duplicating_it(): void
    {
        $this->location('Sky Tower', null, 'Sky Tower, Abu Dhabi', 'Al Reem Island', 'Abu Dhabi');

        $this->post(route('settings.map-locations.locations.store'), [
            'city' => 'Abu Dhabi',
            'community' => 'Al Reem Island',
            'sub_community' => '  sky   tower ',
        ])->assertSessionHasNoErrors()->assertSessionHas('warning');

        $this->assertSame(1, MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->whereRaw('LOWER(sub_community) = ?', ['sky tower'])
            ->count());
    }

    public function test_add_location_requires_a_city_a_community_and_a_building(): void
    {
        $this->post(route('settings.map-locations.locations.store'), [
            'sub_community' => '',
        ])->assertSessionHasErrors(['city', 'community', 'sub_community']);
    }

    public function test_the_locations_screen_groups_communities_under_their_city(): void
    {
        Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Al Reem Island', 'city' => 'Abu Dhabi']);
        Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Dubai Marina', 'city' => 'Dubai']);

        $this->get(route('settings.map-locations.index'))
            ->assertOk()
            ->assertSee('Abu Dhabi')
            ->assertSee('Dubai')
            ->assertSee('Al Reem Island')
            ->assertSee('Dubai Marina');
    }

    public function test_a_community_created_from_the_screen_keeps_its_city(): void
    {
        $this->post(route('settings.map-locations.communities.store'), [
            'city' => 'Abu Dhabi',
            'name' => 'Al Reem Island',
        ])->assertSessionHasNoErrors();

        $community = Community::where('tenant_id', $this->tenant->id)->where('name', 'Al Reem Island')->first();
        $this->assertNotNull($community);
        $this->assertSame('Abu Dhabi', $community->city);
    }

    public function test_a_building_can_be_moved_to_another_community(): void
    {
        $abuDhabi = Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Al Reem Island', 'city' => 'Abu Dhabi']);
        $dubai = Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Dubai Marina', 'city' => 'Dubai']);

        $unit = $this->unit('Bey View Tower', '1301', 'Al Reem Island');
        $location = $this->location('Bey View Tower', null, 'Bey View Tower, Al Reem Island', 'Al Reem Island', 'Abu Dhabi');
        $location->update(['community_id' => $abuDhabi->id]);
        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'Bey View Tower', $location);

        $this->post(route('settings.map-locations.buildings.move', $location), [
            'community_id' => $dubai->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($dubai->id, $location->refresh()->community_id);
        $this->assertSame('Dubai Marina', $location->community);
        $this->assertSame('Dubai', $location->city);
        $this->assertSame('Dubai Marina', $unit->refresh()->community);
        $this->assertSame('Dubai', $unit->refresh()->city);
    }

    public function test_a_building_cannot_be_moved_to_a_foreign_tenants_community(): void
    {
        $location = $this->location('Bey View Tower', null, 'Bey View Tower, Abu Dhabi', 'Abu Dhabi Mall', 'Abu Dhabi');

        $other = Community::create(['tenant_id' => 99999, 'name' => 'Someone Elses', 'city' => 'Dubai']);

        $this->post(route('settings.map-locations.buildings.move', $location), [
            'community_id' => $other->id,
        ])->assertStatus(422);

        $this->assertNull($location->refresh()->community_id);
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
            // The embed is keyless on purpose: the keyed v1/place API 403s
            // unless the key is fully enabled and referrer-whitelisted, so a
            // shared key must never change the URL back to the keyed endpoint.
            ->assertSee('maps.google.com/maps?z=16&q=')
            ->assertSee('output=embed', false)
            ->assertDontSee('key=TEST_EMBED_KEY', false)
            ->assertSee('View on map')
            ->assertSee('Directions')
            ->assertSee('Bey View Tower, Abu Dhabi Mall, Abu Dhabi');

        // The interest button now opens the unit modal instead of posting directly.
        $html->assertSee('I\'m interested in this unit');
    }

    public function test_the_share_page_shows_the_keyless_embed_even_without_an_api_key(): void
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
            ->assertSee('maps.google.com/maps?z=16&q=')
            ->assertSee('output=embed', false)
            ->assertSee('Open in Google Maps')
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

    public function test_coordinates_are_extracted_from_google_maps_link_variants(): void
    {
        $maps = app(MapLocationService::class);

        $this->assertSame([25.204847, 55.270782], $maps->coordsFromUrl('https://www.google.com/maps/place/DUBAI/@25.204847,55.270782,17z/data=...'));
        $this->assertSame([24.4539, 54.3773], $maps->coordsFromUrl('https://goo.gl/maps/x?data=!3d24.4539!4d54.3773'));
        $this->assertSame([25.20, 55.27], $maps->coordsFromUrl('https://www.google.com/maps/search/?api=1&query=x&ll=25.20,55.27'));
        $this->assertNull($maps->coordsFromUrl('https://www.google.com/maps/place/Al+Ghadeer'));
        $this->assertNull($maps->coordsFromUrl(null));
    }

    public function test_geocode_resolves_a_query_through_nominatim(): void
    {
        Http::fake(function ($request) {
            return $request['q'] === 'Nowhere, QC'
                ? Http::response('[]', 200)
                : Http::response([
                    ['lat' => '24.4539', 'lon' => '54.3773', 'display_name' => 'Bey View Tower, Abu Dhabi'],
                ]);
        });

        $maps = app(MapLocationService::class);

        $this->assertSame([24.4539, 54.3773], $maps->geocode('Bey View Tower, Abu Dhabi Mall, Abu Dhabi'));

        // A failed lookup must not throw and must not cache a hit.
        $this->assertNull($maps->geocode('Nowhere, QC'));
        $this->assertNull($maps->geocode('Nowhere, QC'));
    }

    public function test_resolve_coordinates_prefers_stored_then_link_embedded_then_geocode(): void
    {
        $maps = app(MapLocationService::class);

        // Stored coordinates win; nothing else is consulted.
        $stored = $this->location('Bey View Tower', null, 'Bey View Tower, Abu Dhabi');
        $stored->update(['latitude' => 1.0, 'longitude' => 2.0]);
        $this->assertSame([1.0, 2.0], $maps->resolveCoordinates($stored->fresh()));

        // A link that embeds coordinates is used and persisted without HTTP.
        Http::preventStrayRequests();
        try {
            $embedded = $this->location('Al Rihan Heights', 'https://www.google.com/maps/place/X/@25.204847,55.270782,17z', 'Al Rihan Heights, Abu Dhabi');
            $this->assertSame([25.204847, 55.270782], $maps->resolveCoordinates($embedded->fresh()));
            $embedded->refresh();
            $this->assertSame(25.204847, (float) $embedded->latitude);
            $this->assertSame(55.270782, (float) $embedded->longitude);
        } finally {
            Http::preventStrayRequests(false);
        }
        Http::assertNothingSent();

        // Otherwise the query is geocoded.
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '24.4', 'lon' => '54.3']])]);
        $geocoded = $this->location('Reem Island', null, 'Reem Island, Abu Dhabi');
        $this->assertSame([24.4, 54.3], $maps->resolveCoordinates($geocoded->fresh()));
        $geocoded->refresh();
        $this->assertSame(24.4, (float) $geocoded->latitude);
    }

    public function test_backfill_coordinates_geocodes_only_the_missing_ones(): void
    {
        Http::fake(function ($request) {
            return $request['q'] === 'Al Rihan Heights, Abu Dhabi'
                ? Http::response([['lat' => '24.48', 'lon' => '54.37', 'display_name' => 'Al Rihan Heights, Abu Dhabi']])
                : Http::response('[]', 200);
        });

        $this->location('Bey View Tower', 'https://www.google.com/maps/place/X/@25.2,55.27,17z', 'Bey View Tower, Abu Dhabi');
        $this->location('Al Rihan Heights', null, 'Al Rihan Heights, Abu Dhabi');
        $this->location('Reem Island', 'https://www.google.com/maps/place/K', null);

        $maps = app(MapLocationService::class);
        $updated = $maps->backfillCoordinates($this->tenant->id);

        // The embedded-link + the query locations gain coordinates; a bare
        // link falls back to the generated building query and, when that does
        // not resolve, stays put.
        $this->assertSame(2, $updated);
        $this->assertSame(25.2, (float) MapLocation::withoutGlobalScopes()->where('sub_community', 'Bey View Tower')->first()->latitude);
        $this->assertSame(24.48, (float) MapLocation::withoutGlobalScopes()->where('sub_community', 'Al Rihan Heights')->first()->latitude);
        $this->assertNull(MapLocation::withoutGlobalScopes()->where('sub_community', 'Reem Island')->first()->latitude);
    }

    public function test_waze_url_prefers_coordinates_and_falls_back_to_query(): void
    {
        $maps = app(MapLocationService::class);

        $withCoords = $this->location('Bey View Tower', null, 'Bey View Tower, Abu Dhabi');
        $withCoords->update(['latitude' => 25.2, 'longitude' => 55.27]);

        $this->assertSame(
            'https://www.waze.com/ul?ll=25.2,55.27&navigate=yes',
            $maps->wazeUrl($withCoords->fresh())
        );

        $withoutCoords = $this->location('Al Rihan Heights', null, 'Al Rihan Heights, Abu Dhabi');
        $this->assertSame(
            'https://www.waze.com/ul?q=Al%20Rihan%20Heights%2C%20Abu%20Dhabi&navigate=yes',
            $maps->wazeUrl($withoutCoords->fresh())
        );
    }

    public function test_the_share_page_drops_a_pin_with_unit_count_and_direction_links(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $this->unit('Bey View Tower', '1302', 'Abu Dhabi Mall');
        $this->unit('Al Rihan Heights', '902', 'Al Rihan Heights');

        $bey = $this->location('Bey View Tower', 'https://www.google.com/maps/place/X/@25.2,55.27,17z', 'Bey View Tower, Abu Dhabi Mall');
        $bey->update(['latitude' => 25.2, 'longitude' => 55.27]);
        // Al Rihan Heights is deliberately not geocoded — it must not pin.

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);
        $cookie = collect($verify->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'keystone_share'));

        $html = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company')
            ->assertOk()
            ->assertSee('id="share-map"', false)
            // Pin payload: only the geocoded building, with its count.
            ->assertSee('"name":"Bey View Tower"', false)
            ->assertSee('"count":2', false)
            ->assertDontSee('"name":"Al Rihan Heights"', false)
            // The pin URLs live in the @json-encoded payload, whose forward
            // slashes are escaped as \/.
            ->assertSee('google.com\\/maps\\/dir', false)
            ->assertSee('waze.com\\/ul', false)
            // Leaflet is self-hosted (no third-party CDN in the critical
            // map path) and the map must actually draw: OSM tile layer plus
            // CSS pins are contract, not decoration.
            ->assertSee('/vendor/leaflet/leaflet.min.js', false)
            ->assertSee('/vendor/leaflet/leaflet.min.css', false)
            ->assertSee('tile.openstreetmap.org', false)
            ->assertSee('insulacrm-pin', false)
            // The keyless-iframe fallback is not rendered while pins exist
            // (its heading only exists in the @elseif branch).
            ->assertDontSee('Where the available units are');

        $html->assertSee('Bey View Tower', false);
    }

    public function test_a_reimport_that_only_changes_building_spelling_reuses_the_location(): void
    {
        $source = $this->createSource(['column_map' => [
            'Building' => 'building',
            'Unit No' => 'unit_no',
            'Features' => 'features',
            'Rent' => 'rent',
        ]]);
        $service = new AvailabilityIngestService;

        $sheet1 = $service->parseText(implode("\n", [
            "Building\tUnit No\tFeatures\tRent",
            "Bey View Tower\t1301\t4 BR\t240,000",
        ]), ['delimiter' => 'tab', 'has_header' => true]);
        $service->ingest($source, $sheet1['rows'], $this->tenant->id);

        // The PM corrects the spelling (extra space, different case) — the
        // normalized-exact de-dup must fold it back onto the existing row.
        $sheet2 = $service->parseText(implode("\n", [
            "Building\tUnit No\tFeatures\tRent",
            "BEY  VIEW TOWER\t1301\t4 BR\t240,000",
        ]), ['delimiter' => 'tab', 'has_header' => true]);
        $service->ingest($source, $sheet2['rows'], $this->tenant->id);

        $rows = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->get(['sub_community']);

        $this->assertCount(1, $rows);
        $this->assertSame('Bey View Tower', $rows->first()->sub_community);
    }

    public function test_renaming_a_building_cascades_to_every_unit(): void
    {
        $unit = $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $location = $this->location('Bey View Tower', 'https://www.google.com/maps/place/X', 'Bey View Tower, Abu Dhabi Mall');
        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'Bey View Tower', $location);

        $this->post(route('settings.map-locations.rename', $location), [
            'name' => 'Al Aryam Tower',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Al Aryam Tower', $location->refresh()->sub_community);
        $this->assertSame('Al Aryam Tower', $unit->refresh()->sub_community);
        $this->assertSame($location->id, $unit->refresh()->map_location_id);
    }

    public function test_renaming_onto_an_existing_building_is_blocked(): void
    {
        $first = $this->location('Bey View Tower', null, 'Bey View Tower, Abu Dhabi');
        $second = $this->location('Al Aryam Tower', null, 'Al Aryam, Abu Dhabi');

        $this->post(route('settings.map-locations.rename', $second), [
            'name' => 'Bey View Tower',
        ])->assertSessionHas('error');

        $this->assertSame('Bey View Tower', $first->refresh()->sub_community);
        $this->assertSame('Al Aryam Tower', $second->refresh()->sub_community);
    }

    public function test_merging_two_buildings_folds_units_into_the_kept_one(): void
    {
        $kept = $this->location('Bey View Tower', 'https://www.google.com/maps/place/Keep+Me', 'Bey View Tower, Abu Dhabi');
        $discard = $this->location('BEY VIEW TOWER', 'https://www.google.com/maps/place/Discard+Me', 'Bey View Tower, Abu Dhabi');

        $unitOnKept = $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $unitOnDiscard = $this->unit('BEY VIEW TOWER', '1302', 'Abu Dhabi Mall');
        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'Bey View Tower', $kept);
        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'BEY VIEW TOWER', $discard);

        $this->post(route('settings.map-locations.merge'), [
            'keep_id' => $kept->id,
            'discard_id' => $discard->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($discard->fresh());
        $this->assertSame('Bey View Tower', $unitOnKept->refresh()->sub_community);
        $this->assertSame($kept->id, $unitOnKept->refresh()->map_location_id);
        $this->assertSame('Bey View Tower', $unitOnDiscard->refresh()->sub_community);
        $this->assertSame($kept->id, $unitOnDiscard->refresh()->map_location_id);
    }

    public function test_a_building_with_units_cannot_be_removed_but_an_empty_one_can(): void
    {
        $this->unit('Bey View Tower', '1301', 'Abu Dhabi Mall');
        $occupied = $this->location('Bey View Tower', null, 'Bey View Tower, Abu Dhabi');
        $empty = $this->location('Empty Tower', null, 'Empty, Abu Dhabi');

        $this->delete(route('settings.map-locations.buildings.destroy', $occupied))
            ->assertSessionHas('error');
        $this->assertNotNull($occupied->fresh());

        $this->delete(route('settings.map-locations.buildings.destroy', $empty))
            ->assertSessionHas('success');
        $this->assertNull($empty->fresh());
    }

    public function test_communities_can_be_created_and_renamed_cascading_to_buildings_and_units(): void
    {
        $this->post(route('settings.map-locations.communities.store'), [
            'name' => 'Al Ryada',
            'city' => 'Abu Dhabi',
        ])->assertSessionHasNoErrors();

        $community = Community::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('Al Ryada', $community->name);

        $unit = $this->unit('Bey View Tower', '1301', 'Al Ryada');
        $location = $this->location('Bey View Tower', null, 'Al Ryada', 'Abu Dhabi');
        $location->update(['community_id' => $community->id]);
        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'Bey View Tower', $location);

        $this->put(route('settings.map-locations.communities.update', $community), [
            'name' => 'Al Ryada Central',
            'city' => 'Abu Dhabi',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Al Ryada Central', $community->refresh()->name);
        $this->assertSame('Al Ryada Central', $location->refresh()->community);
        $this->assertSame('Al Ryada Central', $unit->refresh()->community);
    }

    public function test_a_community_with_buildings_cannot_be_deleted(): void
    {
        $community = Community::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Al Ryada',
            'city' => 'Abu Dhabi',
        ]);
        $this->location('Bey View Tower', null, 'Al Ryada', 'Abu Dhabi')->update(['community_id' => $community->id]);

        $this->delete(route('settings.map-locations.communities.destroy', $community))
            ->assertSessionHas('error');

        $this->assertNotNull($community->fresh());
    }

    public function test_communities_can_be_merged(): void
    {
        $keep = Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Al Reem Island', 'city' => 'Abu Dhabi']);
        $discard = Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Reem Island', 'city' => null]);

        $keepUnit = $this->unit('Shams Tower', '101', 'Al Reem Island');
        $discardUnit = $this->unit('Gate Tower', '202', 'Reem Island');
        $legacy = $this->unit('Park Tower', '303', 'Reem Island');

        $keepBuilding = $this->location('Shams Tower', null, 'Shams Tower, Al Reem Island', 'Al Reem Island', 'Abu Dhabi');
        $keepBuilding->update(['community_id' => $keep->id]);
        $discardBuilding = $this->location('Gate Tower', null, 'Gate Tower, Reem Island', 'Reem Island', 'Abu Dhabi');
        $discardBuilding->update(['community_id' => $discard->id]);

        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'Shams Tower', $keepBuilding);
        app(MapLocationService::class)->linkBuildingUnits($this->tenant->id, 'Gate Tower', $discardBuilding);

        // Park Tower stays legacy (no FK) so the name-based fallback is exercised.
        $legacy->update(['map_location_id' => null, 'community' => 'Reem Island']);

        $this->post(route('settings.map-locations.communities.merge'), [
            'keep_id' => $keep->id,
            'discard_id' => $discard->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($discard->fresh());
        $this->assertSame($keep->id, $keepBuilding->refresh()->community_id);
        $this->assertSame('Al Reem Island', $keepBuilding->refresh()->community);
        $this->assertSame($keep->id, $discardBuilding->refresh()->community_id);
        $this->assertSame('Al Reem Island', $discardBuilding->refresh()->community);
        $this->assertSame('Al Reem Island', $discardUnit->refresh()->community);
        $this->assertSame('Al Reem Island', $keepUnit->refresh()->community);
        $this->assertSame('Al Reem Island', $legacy->refresh()->community);
    }

    public function test_manually_creating_a_unit_links_it_to_its_building(): void
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
        ])->assertRedirect();

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Skyline Heights')
            ->firstOrFail();

        $property = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Skyline Heights')
            ->firstOrFail();

        $this->assertSame($location->id, $property->map_location_id);
    }

    public function test_picking_a_building_wins_over_the_typed_location_fields(): void
    {
        $this->location('Skyline Heights', null, 'Skyline Heights, Al Ryada', 'Al Ryada', 'Abu Dhabi');

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Skyline Heights')
            ->firstOrFail();

        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'draft',
            'map_location_id' => (string) $location->id,
            'sub_community' => 'Something Else',
            'community' => 'Downtown',
            'city' => 'Dubai',
            'unit_no' => 'A1201',
            'bedrooms' => 2,
            'rent_price' => 150000,
        ])->assertRedirect();

        $property = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('unit_no', 'A1201')
            ->firstOrFail();

        $this->assertSame((int) $location->id, $property->map_location_id);
        $this->assertSame('Skyline Heights', $property->sub_community);
        $this->assertSame('Al Ryada', $property->community);
        $this->assertSame('Abu Dhabi', $property->city);
    }

    public function test_the_new_unit_building_picker_searches_locations(): void
    {
        $this->location('Bey View Tower', null, 'Bey View Tower, Al Ryada', 'Al Ryada', 'Abu Dhabi');

        $this->get(route('inventory.locations-search').'?q=bey')
            ->assertOk()
            ->assertJsonPath('results.0.value', (string) MapLocation::withoutGlobalScopes()->firstOrFail()->id)
            ->assertJsonPath('results.0.label', 'Bey View Tower · Al Ryada · Abu Dhabi');
    }

    public function test_creating_a_new_building_from_the_picker_creates_the_location_and_links_the_unit(): void
    {
        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'draft',
            'map_location_id' => 'new:Zaya Tower',
            'community' => 'Al Reem Island',
            'city' => 'Abu Dhabi',
            'unit_no' => 'B0801',
            'bedrooms' => 2,
            'rent_price' => 175000,
        ])->assertRedirect();

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Zaya Tower')
            ->first();

        $this->assertNotNull($location);

        $property = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('unit_no', 'B0801')
            ->firstOrFail();

        $this->assertSame($location->id, $property->map_location_id);
        $this->assertSame('Zaya Tower', $property->sub_community);
        $this->assertSame('Al Reem Island', $property->community);
    }

    public function test_creating_a_building_from_the_picker_folds_onto_a_case_insensitive_match(): void
    {
        $existing = $this->location('Zaya Tower', null, 'Zaya Tower', 'Al Reem Island', 'Abu Dhabi');

        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'draft',
            'map_location_id' => 'new:zaya tower',
            'unit_no' => 'B0802',
            'bedrooms' => 1,
            'rent_price' => 120000,
        ])->assertRedirect();

        $this->assertSame(1, MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Zaya Tower')
            ->count());

        $property = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('unit_no', 'B0802')
            ->firstOrFail();

        $this->assertSame($existing->id, $property->map_location_id);
    }

    public function test_the_building_picker_is_marked_creatable(): void
    {
        $this->get(route('inventory.create'))
            ->assertOk()
            ->assertSee('data-creatable="1"', false);
    }

    public function test_the_edit_form_shows_the_creatable_picker_and_hidden_location_fields(): void
    {
        $this->location('Skyline Heights', null, 'Skyline Heights, Al Ryada', 'Al Ryada', 'Abu Dhabi');

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Skyline Heights')
            ->firstOrFail();

        $property = $this->unit('Skyline Heights', 'A1201', 'Al Ryada', 'Abu Dhabi');
        $property->update(['map_location_id' => $location->id]);

        $this->get(route('inventory.edit', $property))
            ->assertOk()
            ->assertSee('data-creatable="1"', false)
            ->assertSee('name="map_location_id"', false)
            ->assertSee('name="community"', false)
            ->assertSee('name="city"', false)
            ->assertSee('name="sub_community"', false);
    }

    public function test_updating_a_unit_by_picking_a_building_overwrites_location_fields(): void
    {
        $this->location('Skyline Heights', null, 'Skyline Heights, Al Ryada', 'Al Ryada', 'Abu Dhabi');

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Skyline Heights')
            ->firstOrFail();

        $property = $this->unit('Old Building', 'A1202', 'Old Community', 'Dubai');

        $this->put(route('inventory.update', $property), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'draft',
            'map_location_id' => (string) $location->id,
            'unit_no' => 'A1202',
            'bedrooms' => 2,
            'rent_price' => 150000,
        ])->assertRedirect();

        $property->refresh();

        $this->assertSame((int) $location->id, $property->map_location_id);
        $this->assertSame('Skyline Heights', $property->sub_community);
        $this->assertSame('Al Ryada', $property->community);
        $this->assertSame('Abu Dhabi', $property->city);
    }

    public function test_the_picker_create_page_lists_cities_and_prefills_the_building_name(): void
    {
        $this->location('Bey View Tower', null, 'q', 'Al Ryada', 'Abu Dhabi');

        $this->get(route('inventory.locations-create', ['name' => 'Sky Tower']))
            ->assertOk()
            ->assertSee('name="city"', false)
            ->assertSee('Abu Dhabi')
            ->assertSee('value="Sky Tower"', false);
    }

    public function test_the_create_page_prefills_the_community_it_was_opened_from(): void
    {
        Community::create(['tenant_id' => $this->tenant->id, 'name' => 'Al Reem Island', 'city' => 'Abu Dhabi']);

        $this->get(route('inventory.locations-create', [
            'city' => 'Abu Dhabi',
            'community' => 'Al Reem Island',
            'return' => '/settings/map-locations',
        ]))
            ->assertOk()
            ->assertSee('value="Al Reem Island"', false)
            ->assertSee('Abu Dhabi');
    }

    public function test_creating_a_location_from_the_picker_page_makes_the_community_and_building(): void
    {
        $this->location('Bey View Tower', null, 'q', 'Al Ryada', 'Abu Dhabi');

        $this->post(route('inventory.locations-store'), [
            'city' => 'Abu Dhabi',
            'community' => 'Al Reem Island',
            'sub_community' => 'Sky Tower',
            'return' => '/inventory/create',
        ])->assertRedirect('/inventory/create')
            ->assertSessionHas('success');

        $community = Community::where('tenant_id', $this->tenant->id)
            ->where('name', 'Al Reem Island')
            ->firstOrFail();

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Sky Tower')
            ->firstOrFail();

        $this->assertSame('Abu Dhabi', $location->city);
        $this->assertSame($community->id, $location->community_id);

        // The unit form now preselects the freshly created building.
        $this->get(route('inventory.create'))
            ->assertOk()
            ->assertSee('data-selected="'.$location->id.'"', false);
    }

    public function test_creating_a_location_reuses_an_existing_community_case_insensitively(): void
    {
        $this->location('Bey View Tower', null, 'q', 'Al Ryada', 'Abu Dhabi');

        $existing = Community::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Al Reem Island',
            'city' => 'Abu Dhabi',
        ]);

        $this->post(route('inventory.locations-store'), [
            'city' => 'Abu Dhabi',
            'community' => 'AL REEM ISLAND',
            'sub_community' => 'Sky Tower',
        ])->assertRedirect();

        $this->assertSame(1, Community::where('tenant_id', $this->tenant->id)
            ->where('name', 'Al Reem Island')
            ->count());

        $location = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sub_community', 'Sky Tower')
            ->firstOrFail();

        $this->assertSame($existing->id, $location->community_id);
    }

    public function test_creating_a_location_rejects_a_city_that_is_not_known(): void
    {
        $this->location('Bey View Tower', null, 'q', 'Al Ryada', 'Abu Dhabi');

        $this->post(route('inventory.locations-store'), [
            'city' => 'Atlantis',
            'community' => 'Lost City',
            'sub_community' => 'Poseidon Tower',
        ])->assertSessionHasErrors('city');
    }
}
