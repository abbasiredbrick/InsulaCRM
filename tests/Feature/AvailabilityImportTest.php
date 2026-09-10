<?php

namespace Tests\Feature;

use App\Models\AvailabilityImportRun;
use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Services\AvailabilityIngestService;
use Tests\TestCase;

class AvailabilityImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function amsText(): string
    {
        $col = fn (array $cells) => implode("\t", $cells);

        return implode("\n", [
            $col(['Bey View Tower', 'Abu Dhabi Mall']),
            $col(['', '1301', '4 BR + Maids room 352 Sq Mtr / 3744 Sq Foot', '240,000', '12,000', '20,000', 'Up-coming', '2 Parkings', '18.09.2026']),
            $col(['', '2310', '1 BR - Fully Furnished Open Kitchen Plan', '135,000', '6,750', '2,000', 'Up-coming', '1 Parkings', '14.09.2026']),
            $col(['Taj Residence', '304', '3 BR + Maids Room With Balcony 120 Sq Mtr', '100,000', '5,000', '1,000', 'Up-coming', '10.10.2026']),
            $col(['Al Shaheen Complex', 'Villa # 218', '4 BR + Maid Room - Brand New Open kitchen', '450,000', '22,500', '1,000', 'Vacant', '2 Parking']),
            $col(['', 'P-105', '3 BR + Maids Town House 2768 Square Feet', '320,000', '16,000', '1,000', 'Vacant', '1 Parkings']),
        ]);
    }

    protected function createAmsSource(): AvailabilitySource
    {
        return AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AMS Properties',
            'default_city' => 'Abu Dhabi',
            'parse_options' => [
                'delimiter' => 'tab',
                'has_header' => false,
                'inherit_columns' => ['col0'],
            ],
            'column_map' => [
                'col0' => 'building',
                'col1' => 'unit_no',
                'col2' => 'features',
                'col3' => 'rent',
                'col4' => 'deposit',
                'col5' => 'admin_fee',
                'col6' => 'status',
                'col7' => 'parking',
                'col8' => 'key_date',
            ],
        ]);
    }

    public function test_parses_tab_aligned_text_rows(): void
    {
        $service = new AvailabilityIngestService();
        $table = $service->parseText($this->amsText(), [
            'delimiter' => 'tab',
            'has_header' => false,
            'inherit_columns' => ['col0'],
        ]);

        $this->assertCount(6, $table['rows']);

        // block header row carries the building into the first unit row
        $this->assertSame('1301', $table['rows'][1]['col1']);
        $this->assertSame('Bey View Tower', $table['rows'][1]['col0']);
        $this->assertSame('Bey View Tower', $table['rows'][2]['col0']);
        $this->assertSame('Taj Residence', $table['rows'][3]['col0']);
    }

    public function test_ingest_creates_units_with_normalized_values(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService();
        $table = $service->parseText($this->amsText(), $source->parse_options);

        $result = $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertSame(5, $result['created']);
        $this->assertSame(0, $result['updated']);

        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'availability_source_id' => $source->id,
            'source_unit_ref' => '1301',
            'sub_community' => 'Bey View Tower',
            'unit_no' => '1301',
            'bedrooms' => 4,
            'square_footage' => 3789, // 352 SQM → sqft
            'rent_price' => 240000,
            'deposit_amount' => 12000,
            'admin_fee' => 20000,
            'parking' => 2,
            'availability' => 'ready_to_list',
            'rent_period' => 'yearly',
            'intent' => 'rent',
            'owner_name' => 'AMS Properties',
        ]);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1301')->first();
        $this->assertSame('2026-09-18', $unit->handover_date?->toDateString());

        $studio = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '2310')->first();
        $this->assertSame('furnished', $studio->furnishing);

        $townhouse = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', 'P-105')->first();
        $this->assertSame('townhouse', $townhouse->property_category);
        $this->assertSame(2768, $townhouse->square_footage);
        $this->assertNull($townhouse->handover_date);
    }

    public function test_re_import_updates_instead_of_duplicating(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService();
        $table = $service->parseText($this->amsText(), $source->parse_options);

        $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);
        $result = $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertCount(5, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get());
        $this->assertSame(0, $result['created']);
        $this->assertSame(5, $result['updated']);
    }

    public function test_units_missing_from_latest_sheet_become_unlisted(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService();
        $full = $service->parseText($this->amsText(), $source->parse_options);

        $service->ingest($source, $full['rows'], $this->tenant->id, $this->adminUser->id);
        $this->assertSame(5, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('availability', '!=', 'unlisted')->count());

        $shortRows = array_slice($full['rows'], 0, 3);
        $service->ingest($source, $shortRows, $this->tenant->id, $this->adminUser->id);

        $unlisted = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('availability', 'unlisted')->get();

        $this->assertCount(3, $unlisted);
        $this->assertTrue($unlisted->pluck('source_unit_ref')->contains('304'));
        $this->assertStringContainsString('Removed from AMS Properties', $unlisted->first()->notes);

        $stillThere = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('source_unit_ref', '1301')->first();
        $this->assertSame('ready_to_list', $stillThere->availability);
    }

    public function test_source_status_map_maps_under_offer_to_reserved(): void
    {
        $source = $this->createAmsSource();
        $source->update(['status_map' => [
            'vacant' => 'ready_to_list',
            'up-coming' => 'ready_to_list',
            'under offer' => 'reserved',
        ]]);

        $service = new AvailabilityIngestService();
        $table = $service->parseText(implode("\n", array_merge(
            array_slice(explode("\n", $this->amsText()), 0, 2),
            ["Bey View Tower\t900\t2 BR - Canal View\t190,000\t9,500\t2,000\tUnder Offer\t1 Parking\t"]
        )), $source->parse_options);

        $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '900')->first();
        $this->assertSame('reserved', $unit->availability);
    }

    public function test_http_flow_creates_source_imports_and_records_a_run(): void
    {
        // create source
        $this->post(route('availability-sources.store'), [
            'name' => 'AMS Properties',
            'default_city' => 'Abu Dhabi',
        ])->assertRedirect();

        $source = AvailabilitySource::where('name', 'AMS Properties')->first();
        $this->assertNotNull($source);

        // save mapping
        $this->put(route('availability-sources.update', $source), [
            'name' => 'AMS Properties',
            'delimiter' => 'tab',
            'has_header' => 0,
            'column_map' => [
                ['source' => 'col0', 'target' => 'building', 'inherit' => 1],
                ['source' => 'col1', 'target' => 'unit_no'],
                ['source' => 'col2', 'target' => 'features'],
                ['source' => 'col3', 'target' => 'rent'],
                ['source' => 'col4', 'target' => 'deposit'],
                ['source' => 'col5', 'target' => 'admin_fee'],
                ['source' => 'col6', 'target' => 'status'],
                ['source' => 'col7', 'target' => 'parking'],
                ['source' => 'col8', 'target' => 'key_date'],
            ],
            'status_map' => "Vacant => ready_to_list\nUp-coming => ready_to_list\nUnder Offer => reserved",
        ])->assertRedirect();

        $source->refresh();
        $this->assertSame('building', $source->column_map['col0']);
        $this->assertSame(['col0'], $source->parse_options['inherit_columns']);
        $this->assertSame('reserved', $source->status_map['Under Offer']);

        // paste + preview + run
        $this->post(route('availability-sources.import-parse', $source), [
            'pasted' => $this->amsText(),
            'delimiter' => 'tab',
            'has_header' => 0,
        ])->assertRedirect(route('availability-sources.review', $source));

        $this->get(route('availability-sources.review', $source))
            ->assertOk()
            ->assertSee('1301')
            ->assertSee('Run Import');

        $this->post(route('availability-sources.run', $source))
            ->assertRedirect(route('availability-sources.index'));

        $this->assertDatabaseHas('availability_import_runs', [
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
            'status' => 'completed',
            'created_rows' => 5,
        ]);

        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'source_unit_ref' => '1301',
            'sub_community' => 'Bey View Tower',
        ]);
    }

    public function test_import_index_page_shows_sources(): void
    {
        $source = $this->createAmsSource();

        $this->get(route('availability-sources.index'))
            ->assertOk()
            ->assertSee('AMS Properties')
            ->assertSee('Import List');
    }

    public function test_unparseable_file_yields_error_on_edit_page(): void
    {
        $source = $this->createAmsSource();

        $this->post(route('availability-sources.import-parse', $source), [
            'pasted' => '',
        ])->assertSessionHasErrors('pasted');

        // nothing was staged, so the review page points us to import again
        $this->get(route('availability-sources.review', $source))
            ->assertRedirect(route('availability-sources.import', $source));
    }
}