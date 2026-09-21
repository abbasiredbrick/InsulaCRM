<?php

namespace Tests\Feature;

use App\Models\AvailabilityImportRun;
use App\Models\AvailabilityReview;
use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Notifications\AvailabilityConflictAlert;
use App\Services\AvailabilityIngestService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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
        $service = new AvailabilityIngestService;
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
        $service = new AvailabilityIngestService;
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

    // ── The real AMS "Properties-<date>.csv" layout ─────────────────────────
    //
    // AMS emails a comma CSV with a UTF-8 BOM, an Excel-style header row,
    // trailing empty columns, and grouped blocks where only the first unit row
    // carries the building/location.

    protected function amsGroupedBlocks(): array
    {
        return [
            ['Bey View Tower', 'Abu Dhabi Mall', '', '1301', '4 BR + Maids room', '352 Sq Mtr / 3744 Sq Foot', '240,000', '12,000', '20,000', 'Vacant', '2 Parkings', 'Keys - Security', 'Beach Rotana Membership', '2 Adults + 2 Child below 17 Yrs'],
            [], // blank separator row between blocks
            ['The Bridges', 'Tower 2', '', '903', '1 BR', '', '74,000', '3,700', '15,000', 'Vacant', '1 Parkings', 'Keys at Canal Residence', '', ''],
            [],
            ['Canal Residence', 'Reem Island', '', 'P-105', '3 BR + Maids Town House', '2768 Square Feet', '300,000', '15,000', '1,000', 'Vacant', '2 Parkings', 'Concierge', 'Gym/Swimming Pool', ''],
            ['', '', '', '1714', '1 BR - 871.34 Square Foot', 'Closed Kitchen', '115,000', '5,750', '1,000', 'Vacant', '1 Parkings', 'Concierge', 'Gym/Swimming Pool', ''],
            ['', '', '', '1209', '1 BR - 864.57 Square Foot', 'Closed Kitchen', '115,000', '5,750', '1,000', 'Up-coming', '1 Parkings', '31.10.2026', 'Gym/Swimming Pool', ''],
            [],
            ['Taj Residence', 'Behind ADCB Head Off', '', '304', '3 BR + Maids Room', 'With Balcony / 120 Sq Mtr', '100,000', '5,000', '1,000', 'Vacant', '', 'Keys - Security', '', ''],
        ];
    }

    protected function amsCsv(?array $blocks = null): string
    {
        $header = ['Property Name', 'Location', "Location (please\nclick)", 'Unit No.', 'No. of Bedrooms', 'Unit Features', 'Rent', 'Deposit', 'Admin Fee', 'Status', 'Parking', 'Key Location', 'Facilities', 'Remarks'];
        $encode = fn (array $cells): string => implode(',', array_map(fn ($cell) => (function ($cell) {
            $cell = (string) $cell;
            if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")) {
                return '"'.str_replace('"', '""', $cell).'"';
            }

            return $cell;
        })($cell), $cells));
        $lines = [$encode($header).','];
        foreach (($blocks ?? $this->amsGroupedBlocks()) as $block) {
            if ($block === []) {
                $lines[] = '';
            } else {
                $lines[] = $encode($block).',,';
            }
        }

        return "\xEF\xBB\xBF".implode("\n", $lines)."\n";
    }

    protected function parseAmsCsv(?array $blocks = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ams_csv_');
        file_put_contents($path, $this->amsCsv($blocks));

        try {
            return (new AvailabilityIngestService)->parseFile($path, 'csv', [
                'delimiter' => 'comma',
                'has_header' => true,
            ]);
        } finally {
            @unlink($path);
        }
    }

    protected function headerMappedAmsSource(): AvailabilitySource
    {
        return AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AMS Properties',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'comma', 'has_header' => true],
            'column_map' => [
                'Property Name' => 'building',
                'Location' => 'community',
                'Unit No.' => 'unit_no',
                'No. of Bedrooms' => 'bedrooms',
                'Unit Features' => 'features',
                'Rent' => 'rent',
                'Deposit' => 'deposit',
                'Admin Fee' => 'admin_fee',
                'Status' => 'status',
                'Parking' => 'parking',
                'Key Location' => 'key_date',
                'Facilities' => 'amenities',
                'Remarks' => 'remarks',
            ],
        ]);
    }

    public function test_ams_header_csv_parses_to_clean_column_names(): void
    {
        $table = $this->parseAmsCsv();

        $this->assertSame(6, count($table['rows']));
        $this->assertSame('Property Name', $table['header'][0]);
        $this->assertSame('Location (please click)', $table['header'][2]);
        $this->assertSame('Unit No.', $table['header'][3]);
        $this->assertSame('col14', $table['header'][14]);

        $this->assertSame('Bey View Tower', $table['rows'][0]['Property Name']);
        $this->assertSame('1301', $table['rows'][0]['Unit No.']);
        $this->assertSame('The Bridges', $table['rows'][1]['Property Name']);
        $this->assertSame('Canal Residence', $table['rows'][2]['Property Name']);
    }

    public function test_ams_csv_ingest_creates_units_with_correct_buildings_and_no_leases(): void
    {
        $source = $this->headerMappedAmsSource();
        $service = new AvailabilityIngestService;
        $result = $service->ingest($source, $this->parseAmsCsv()['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertSame(6, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['missing']);
        $this->assertFalse($result['reconciliation_skipped']);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get();
        $this->assertCount(6, $units);
        $this->assertSame(0, $units->where('availability', 'leased')->count());
        $this->assertSame(0, $units->where('sub_community', 'Other')->count());

        $byRef = $units->keyBy('source_unit_ref');
        $this->assertSame('Bey View Tower', $byRef['1301']->sub_community);
        $this->assertSame('The Bridges', $byRef['903']->sub_community);
        $this->assertSame('Tower 2', $byRef['903']->community);
        $this->assertSame('Canal Residence', $byRef['P-105']->sub_community);
        $this->assertSame('Canal Residence', $byRef['1714']->sub_community);
        $this->assertSame('Reem Island', $byRef['1209']->community);
        $this->assertSame('Taj Residence', $byRef['304']->sub_community);
        $this->assertSame('ready_to_list', $byRef['1209']->availability);

        $this->assertSame('2026-10-31', $byRef['1209']->handover_date?->toDateString());
    }

    public function test_ams_csv_bedroom_counts_ignore_area_and_maid_figures(): void
    {
        // The "No. of Bedrooms" cell carries trailing area/parking digits
        // ("3 BR + Maid - (309 SQM)", "1 BR - 871.34 Square Foot"). These must
        // never be concatenated into the bedroom count (3309 / 187134 bug).
        $source = $this->headerMappedAmsSource();
        $service = new AvailabilityIngestService;

        $rows = $this->parseAmsCsv([
            ['Al Hattan Residence', 'AL Raha', '', '803', '3 BR + Maid - (309 SQM)', '', '150,000', '7,500', '1,000', 'Vacant', '2 Parkings', '', '', ''],
            ['Canal Residence', 'Reem Island', '', '1714', '1 BR - 871.34 Square Foot', '', '115,000', '5,750', '1,000', 'Vacant', '1 Parkings', '', '', ''],
            ['Canal Residence', 'Reem Island', '', '1007', '2 BR + Maid / 2022 Square Ft', '', '130,000', '6,500', '1,000', 'Vacant', '1 Parkings', '', '', ''],
            ['Al Hattan Residence', 'AL Raha', '', 'Retail 3', '165 Square Meters', '', '250,000', '12,500', '1,000', 'Vacant', '', '', '', ''],
            ['Al Hattan Residence', 'AL Raha', '', '105', '1 BR (131 SQM)', '', '62,000', '3,100', '1,000', 'Vacant', '', '', '', ''],
        ])['rows'];

        $service->ingest($source, $rows, $this->tenant->id, $this->adminUser->id);

        $byRef = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get()->keyBy('source_unit_ref');

        $this->assertSame(3, $byRef['803']->bedrooms);
        $this->assertSame(1, $byRef['1714']->bedrooms);
        $this->assertSame(2, $byRef['1007']->bedrooms);
        $this->assertNull($byRef['Retail 3']->bedrooms);
        $this->assertSame(1, $byRef['105']->bedrooms);

        $this->assertSame('3BR Apartment for Rent in Al Hattan Residence', $byRef['803']->marketing_title);
        $this->assertSame('1BR Apartment for Rent in Canal Residence', $byRef['1714']->marketing_title);
    }

    public function test_ams_partial_reimport_never_bulk_marks_units_leased(): void
    {
        $source = $this->headerMappedAmsSource();
        $service = new AvailabilityIngestService;
        $service->ingest($source, $this->parseAmsCsv()['rows'], $this->tenant->id, $this->adminUser->id);

        $partial = $this->parseAmsCsv([
            ['Bey View Tower', 'Abu Dhabi Mall', '', '1301', '4 BR + Maids room', '352 Sq Mtr / 3744 Sq Foot', '240,000', '12,000', '20,000', 'Vacant', '2 Parkings', 'Keys - Security', '', ''],
        ]);
        $result = $service->ingest($source, $partial['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertTrue($result['reconciliation_skipped']);
        $this->assertSame(0, $result['missing']);

        $leased = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('availability', 'leased')->count();
        $this->assertSame(0, $leased);
    }

    public function test_legacy_col_mapping_source_gets_rebuilt_from_header_csv(): void
    {
        // Sources created for the old paste-text format store a positional
        // "colN" map + "has_header: false". Uploading a proper header CSV
        // through one used to misalign every column. It must now detect the
        // header, rebuild the mapping, and import units under the right names.
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'AMS Properties',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'comma', 'has_header' => false, 'inherit_columns' => ['col0']],
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
                'col9' => 'amenities',
            ],
            'status_map' => ['vacant' => 'ready_to_list', 'up-coming' => 'ready_to_list'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'ams_legacy_');
        file_put_contents($path, $this->amsCsv());

        try {
            $table = (new AvailabilityIngestService)->parseFile($path, 'csv', ['delimiter' => 'comma', 'has_header' => false]);
        } finally {
            @unlink($path);
        }

        // The header must be promoted even though the stored options say no.
        $this->assertSame('Property Name', $table['header'][0]);
        $this->assertSame('Unit No.', $table['header'][3]);

        $result = (new AvailabilityIngestService)->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertTrue($result['mapping_rebuilt']);
        $this->assertSame(6, $result['created']);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get();
        $byRef = $units->keyBy('source_unit_ref');
        $this->assertSame('Bey View Tower', $byRef['1301']->sub_community);
        $this->assertSame('The Bridges', $byRef['903']->sub_community);
        $this->assertSame('Tower 2', $byRef['903']->community);
        $this->assertSame('Canal Residence', $byRef['P-105']->sub_community);
        $this->assertSame('Taj Residence', $byRef['304']->sub_community);
        $this->assertSame('ready_to_list', $byRef['1209']->availability);
        $this->assertSame(0, $units->where('sub_community', 'Other')->count());
        $this->assertSame(0, $units->where('sub_community', 'AMS Properties')->count());
    }

    public function test_building_only_header_row_carries_down_to_following_units(): void
    {
        // AMS fills 'Property Name' on the first row of a merged block. When
        // that first row has no unit number (a building header row), the name
        // must still carry down to the actual unit rows.
        $blocks = [
            ['Al Hattan Residence', 'Al Raha - Dana Area', '', '', '', '', '150,000', '15,000', '1,000', 'Vacant', '', 'Keys - Security', '', ''],
            ['', '', '', '803', '3 BR + Maid - (309 SQM)', 'Duplex with Terrace', '200,000', '10,000', '3,000', 'Vacant', '', 'Keys', '', ''],
            ['', '', '', '105', '1 BR (131 SQM)', 'Closed kitchen With Terrace', '95,000', '4,750', '2,000', 'Vacant', '', 'Keys', '', ''],
        ];

        $source = $this->headerMappedAmsSource();
        $result = (new AvailabilityIngestService)->ingest($source, $this->parseAmsCsv($blocks)['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get();
        $byRef = $units->keyBy('source_unit_ref');
        $this->assertSame('Al Hattan Residence', $byRef['803']->sub_community);
        $this->assertSame('Al Hattan Residence', $byRef['105']->sub_community);
        $this->assertSame('Al Raha - Dana Area', $byRef['803']->community);
        $this->assertSame('ready_to_list', $byRef['105']->availability);
        $this->assertSame(0, $units->where('sub_community', 'Other')->count());
    }

    public function test_re_import_updates_instead_of_duplicating(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService;
        $table = $service->parseText($this->amsText(), $source->parse_options);

        $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);
        $result = $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertCount(5, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get());
        $this->assertSame(0, $result['created']);
        $this->assertSame(5, $result['updated']);
    }

    // ── The real Relevate (Burj Al Shams) CSV layout ───────────────────────
    //
    // Relevate emails a comma CSV whose header labels carry embedded newlines
    // ("Unit No\n"), a "listing price", a "balcony" and "view" column, an
    // "expected vacating date" column (dates like "Sep 14, 2026" or the word
    // "Available" for units ready now), and trailing empty columns. There is no
    // building column, so the source carries default_building.

    protected function relevateCsv(): string
    {
        $header = ["Unit No\n", "Area (Sqft)\n", "Unit Type\n", "Balcony\n", "View\n", "Status\n", "Expected vacating date\n", "Listing price\n"];
        $rows = [
            ['1901', '1639', "3 BHK\n", "No\n", "Community view\n", "Available for viewing\n", '', '125000'],
            ['1605', '1121', "2 BHK\n", "Yes\n", "Sea View\n", "Available for viewing\n", "Available\n", '102000'],
            ['1703', '1227', "2 BHK\n", "No\n", "Sea View\n", "Upcoming\n", 'Sep 14, 2026', '105000'],
            ['1810', '1121', "2 BHK\n", "No\n", "Community view\n", "Upcoming\n", 'Sep 26, 2026', '100000'],
            ['2404', '1104', "2 BHK\n", "Yes\n", "Sea View\n", "Upcoming\n", 'Sep 28, 2026', '103000'],
            ['1407', '1104', "2 BHK\n", "Yes\n", "Sea View\n", "Upcoming\n", 'Sep 15, 2026', '108000'],
            ['1806', '1460', "2 BHK + M\n", "No\n", "Sea View\n", "Upcoming\n", 'Oct 1, 2026', '120000'],
        ];
        $encode = fn (array $cells): string => implode(',', array_map(
            fn ($cell) => (function (string $cell): string {
                $cell = trim($cell);
                if (str_contains($cell, ',') || str_contains($cell, '"') || str_contains($cell, "\n")) {
                    return '"'.str_replace('"', '""', $cell).'"';
                }

                return $cell;
            })((string) $cell),
            $cells
        ));
        $lines = [$encode($header).','];
        foreach ($rows as $row) {
            $lines[] = $encode($row).',,,,,,,,,,,,,,';
        }

        return implode("\n", $lines)."\n";
    }

    protected function parseRelevateCsv(string $delimiter = 'comma', ?bool $hasHeader = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'relevate_csv_');
        file_put_contents($path, $this->relevateCsv());

        try {
            return (new AvailabilityIngestService)->parseFile($path, 'csv', [
                'delimiter' => $delimiter,
                'has_header' => $hasHeader ?? false,
            ]);
        } finally {
            @unlink($path);
        }
    }

    protected function relevateSource(): AvailabilitySource
    {
        return AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relevate',
            'default_city' => 'Abu Dhabi',
            'default_building' => 'Burj Al Shams',
            'default_deposit_pct' => 5,
            'default_deposit_min' => 5000,
            'default_admin_fee' => 1050,
            'default_tawtheeq_fee' => 150,
            'parse_options' => ['delimiter' => 'comma', 'has_header' => true],
            'column_map' => [
                'Unit No' => 'unit_no',
                'Area (Sqft)' => 'square_footage',
                'Unit Type' => 'features',
                'Balcony' => 'balcony',
                'View' => 'view',
                'Status' => 'status',
                'Expected vacating date' => 'key_date',
                'Listing price' => 'rent',
            ],
        ]);
    }

    public function test_relevate_csv_is_auto_detected_and_fully_mapped(): void
    {
        // A brand-new source has no mapping yet — the Relevate layout (header
        // auto-detected, sqft area, balcony/view, status, date, listing price)
        // must be rebuilt automatically on the first upload.
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relevate',
            'default_building' => 'Burj Al Shams',
            'default_city' => 'Abu Dhabi',
            'default_deposit_pct' => 5,
            'default_deposit_min' => 5000,
            'default_admin_fee' => 1050,
            'default_tawtheeq_fee' => 150,
            'parse_options' => ['delimiter' => 'auto', 'has_header' => false],
        ]);

        $table = $this->parseRelevateCsv('auto', false);

        $this->assertSame('Unit No', $table['header'][0]);
        $this->assertSame('Area (Sqft)', $table['header'][1]);
        $this->assertSame('Expected vacating date', $table['header'][6]);
        $this->assertCount(7, $table['rows']);

        $result = (new AvailabilityIngestService)->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertTrue($result['mapping_rebuilt']);
        $this->assertSame(7, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get();
        $this->assertCount(7, $units);
        $this->assertSame(0, $units->where('sub_community', 'Other')->count());

        $byRef = $units->keyBy('source_unit_ref');

        $u = $byRef['1901'];
        $this->assertSame('Burj Al Shams', $u->sub_community);
        $this->assertSame('1901', $u->unit_no);
        $this->assertSame(3, $u->bedrooms);
        $this->assertSame(1639, $u->square_footage); // sqft is NOT ×10.7639
        $this->assertSame('no', $u->balcony);
        $this->assertSame('Community view', $u->view);
        $this->assertSame('listed', $u->availability); // "Available for viewing"
        $this->assertSame(125000, (int) $u->rent_price);
        $this->assertSame(6250, (int) $u->deposit_amount); // max(5000, 5%)
        $this->assertSame(1050, (int) $u->admin_fee);
        $this->assertSame(150, (int) $u->tawtheeq_fee);
        $this->assertStringContainsString('Balcony: No', $u->marketing_description);
        $this->assertStringContainsString('View: Community view', $u->marketing_description);

        $u = $byRef['1703'];
        $this->assertSame('ready_to_list', $u->availability); // "Upcoming"
        $this->assertSame('2026-09-14', $u->handover_date?->toDateString());
        $this->assertSame('no', $u->balcony);
        $this->assertSame('Sea View', $u->view);

        $u = $byRef['1605'];
        $this->assertNull($u->handover_date);
        $this->assertStringContainsString('Available immediately', $u->notes);
        $this->assertSame(5100, (int) $u->deposit_amount);
    }

    public function test_relevate_deposit_formula_uses_minimum_floor(): void
    {
        $source = $this->relevateSource();

        $path = tempnam(sys_get_temp_dir(), 'relevate_csv_');
        file_put_contents($path, $this->relevateCsv());

        try {
            $table = (new AvailabilityIngestService)->parseFile($path, 'csv', $source->parse_options);
        } finally {
            @unlink($path);
        }

        $result = (new AvailabilityIngestService)->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);
        $this->assertFalse($result['mapping_rebuilt']);
        $this->assertSame(7, $result['created']);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get()->keyBy('source_unit_ref');

        // max(5,000, 5% × rent): 125,000 → 6,250; 102,000 → 5,100; 100,000 → 5,000 (floor); 120,000 → 6,000.
        $this->assertSame(6250, (int) $units['1901']->deposit_amount);
        $this->assertSame(5100, (int) $units['1605']->deposit_amount);
        $this->assertSame(5000, (int) $units['1810']->deposit_amount);
        $this->assertSame(6000, (int) $units['1806']->deposit_amount);
    }

    public function test_relevate_source_without_formula_uses_fixed_deposit(): void
    {
        $source = $this->relevateSource();
        $source->update(['default_deposit_pct' => null, 'default_deposit_min' => null, 'default_deposit' => 4000]);

        $table = $this->parseRelevateCsv('comma', true);
        (new AvailabilityIngestService)->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1901')->first();
        $this->assertSame(4000, (int) $unit->deposit_amount);
    }

    public function test_legacy_relevate_source_rebuilds_mapping_when_header_csv_uploaded(): void
    {
        // Replicates the prod Relevate source before this fix: a legacy positional
        // "colN" map (multi_space, no header). Because the headerless layout names
        // buckets col0..col8, the rebuild guard used to compare the saved map keys
        // against the row keys and "succeeded" on the CSV's padded col8..col25
        // placeholder columns, so a properly-headed Relevate CSV was fed through the
        // old positional map and never updated the CRM.
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relevate',
            'default_city' => 'Abu Dhabi',
            'default_building' => 'Burj Al Shams',
            'default_deposit_pct' => 5,
            'default_deposit_min' => 5000,
            'default_admin_fee' => 1050,
            'default_tawtheeq_fee' => 150,
            'parse_options' => ['delimiter' => 'multi_space', 'has_header' => false],
            'column_map' => [
                'col0' => 'unit_no',
                'col1' => 'square_footage',
                'col2' => 'features',
                'col3' => 'amenities',
                'col4' => 'rent',
                'col5' => 'deposit',
                'col6' => 'admin_fee',
                'col7' => 'tawtheeq',
                'col8' => 'community',
            ],
        ]);

        // importDirect forces a non-tabular legacy delimiter to 'auto', so the
        // header row gets promoted and rows carry the eight real Relevate labels.
        $table = $this->parseRelevateCsv('auto', false);

        $this->assertCount(7, $table['rows']);
        $this->assertArrayHasKey('Unit No', $table['rows'][0]);
        $this->assertArrayHasKey('Expected vacating date', $table['rows'][0]);

        $result = (new AvailabilityIngestService)->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertTrue($result['mapping_rebuilt']);
        $this->assertSame(7, $result['created']);
        $this->assertSame(0, $result['skipped']);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->get()->keyBy('source_unit_ref');
        $this->assertCount(7, $units);
        $this->assertSame(0, $units->where('sub_community', 'Other')->count());

        $u = $units['1901'];
        $this->assertSame('Burj Al Shams', $u->sub_community);
        $this->assertSame(1639, $u->square_footage); // sqft, not ×10.7639
        $this->assertSame('no', $u->balcony);
        $this->assertSame('Community view', $u->view);
        $this->assertSame('listed', $u->availability);
        $this->assertSame(125000, (int) $u->rent_price);
        $this->assertSame(6250, (int) $u->deposit_amount); // max(5000, 5%)
        $this->assertSame(1050, (int) $u->admin_fee);
        $this->assertSame(150, (int) $u->tawtheeq_fee);

        $this->assertSame('ready_to_list', $units['1703']->availability);
        $this->assertSame('2026-09-14', $units['1703']->handover_date?->toDateString());
        $this->assertSame(5000, (int) $units['1810']->deposit_amount); // floor
    }

    public function test_units_missing_from_latest_sheet_become_leased(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService;
        $full = $service->parseText($this->amsText(), $source->parse_options);

        $service->ingest($source, $full['rows'], $this->tenant->id, $this->adminUser->id);
        $this->assertSame(5, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('availability', '!=', 'leased')->count());

        $shortRows = array_slice($full['rows'], 0, 3);
        $service->ingest($source, $shortRows, $this->tenant->id, $this->adminUser->id);

        $leased = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('availability', 'leased')->get();

        $this->assertCount(3, $leased);
        $this->assertTrue($leased->pluck('source_unit_ref')->contains('304'));
        $this->assertTrue($leased->pluck('source_unit_ref')->contains('P-105'));
        $this->assertStringContainsString('Leased per AMS Properties', $leased->first()->notes);

        $stillThere = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->where('source_unit_ref', '1301')->first();
        $this->assertSame('ready_to_list', $stillThere->availability);
    }

    public function test_previously_available_unit_becomes_leased_when_sheet_says_rented(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService;
        $table = $service->parseText($this->amsText(), $source->parse_options);

        $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1301')->first();
        $this->assertSame('ready_to_list', $unit->availability);

        // Re-upload same unit but now marked "Rented" → must flip to leased.
        $service->ingest($source, $service->parseText(implode("\n", [
            "Bey View Tower\t1301\t4 BR + Maids room 352 Sq Mtr / 3744 Sq Foot\t240,000\t12,000\t20,000\tRented\t2 Parkings\t",
        ]), $source->parse_options)['rows'], $this->tenant->id, $this->adminUser->id);

        $unit->refresh();
        $this->assertSame('leased', $unit->availability);
    }

    public function test_new_unit_first_appearing_as_leased_is_created_leased(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService;

        $service->ingest($source, $service->parseText(implode("\n", [
            "Bey View Tower\t1405\t1 BR - City View\t90,000\t4,500\t2,000\tLeased\t1 Parking\t",
        ]), $source->parse_options)['rows'], $this->tenant->id, $this->adminUser->id);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1405')->first();
        $this->assertNotNull($unit);
        $this->assertSame('leased', $unit->availability);
    }

    public function test_source_status_map_maps_under_offer_to_reserved(): void
    {
        $source = $this->createAmsSource();
        $source->update(['status_map' => [
            'vacant' => 'ready_to_list',
            'up-coming' => 'ready_to_list',
            'under offer' => 'reserved',
        ]]);

        $service = new AvailabilityIngestService;
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
            ->assertSee('Re-import');
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

    public function test_tawtheeq_fee_maps_from_sheet_and_source_defaults(): void
    {
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relevate',
            'default_building' => 'Burj Al Shams',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'tab', 'has_header' => false],
            'column_map' => [
                'col0' => 'unit_no',
                'col1' => 'features',
                'col2' => 'amenities',
                'col3' => 'rent',
                'col4' => 'deposit',
                'col5' => 'admin_fee',
                'col6' => 'tawtheeq',
            ],
            'default_admin_fee' => 1050,
            'default_tawtheeq_fee' => 150,
        ]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(implode("\n", [
            "406\t2 BR - Sea View\tBalcony\t97,000\t5,000\t1050\t150",
            "1903\t2 BR - Sea View\t\t119,000\t5,950\t\t", // empty → source defaults
        ]), $source->parse_options);

        $result = $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);
        $this->assertSame(2, $result['created']);

        $withColumn = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '406')->first();
        $this->assertSame(97000.0, (float) $withColumn->rent_price);
        $this->assertSame(5000.0, (float) $withColumn->deposit_amount);
        $this->assertSame(1050.0, (float) $withColumn->admin_fee);
        $this->assertSame(150.0, (float) $withColumn->tawtheeq_fee);

        $fromDefaults = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1903')->first();
        $this->assertSame(1050.0, (float) $fromDefaults->admin_fee);
        $this->assertSame(150.0, (float) $fromDefaults->tawtheeq_fee);
        $this->assertStringContainsString('Tawtheeq: AED 150', $fromDefaults->marketing_description);
    }

    public function test_pre_existing_unlinked_unit_is_linked_and_updated_not_duplicated(): void
    {
        $existing = Property::create([
            'tenant_id' => $this->tenant->id,
            'unit_no' => '2506',
            'sub_community' => null,
            'intent' => 'rent',
            'rent_price' => 120000,
            'availability' => 'listed',
            'rent_period' => 'yearly',
            'market_class' => 'ready',
            'city' => 'Abu Dhabi',
            'state' => '',
            'zip_code' => '',
            'address' => '2506 Burj Al Shams Abu Dhabi',
            'source_unit_ref' => null,
        ]);

        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Relevate',
            'default_building' => 'Burj Al Shams',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'tab', 'has_header' => false],
            'column_map' => [
                'col0' => 'unit_no',
                'col1' => 'features',
                'col2' => 'amenities',
                'col3' => 'rent',
                'col4' => 'deposit',
                'col5' => 'admin_fee',
                'col6' => 'tawtheeq',
            ],
        ]);

        $service = new AvailabilityIngestService;
        $table = $service->parseText(
            "2506\t2 BR + Maids - Sea View\t\t119,000\t5,950\t1,050\t150",
            $source->parse_options
        );

        $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $units = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('unit_no', '2506')->get();
        $this->assertCount(1, $units);
        $this->assertSame($source->id, $units->first()->availability_source_id);
        $this->assertSame(119000.0, (float) $units->first()->rent_price);
        $this->assertSame('Burj Al Shams', $units->first()->sub_community);
        $this->assertSame((int) $existing->id, (int) $units->first()->id);

        // sheet listed it → refetched to ready_to_list, not unlisted
        $this->assertSame('ready_to_list', $units->first()->availability);
    }

    // ── Listed units flagged as leased by the PM sheet ─────────────────────

    protected function publishedListedUnit(): Property
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService;
        $service->ingest($source, $service->parseText($this->amsText(), $source->parse_options)['rows'], $this->tenant->id, $this->adminUser->id);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1301')->first();
        $unit->update(['availability' => 'listed']);

        return $unit;
    }

    /**
     * The full AMS sheet with unit 1301 now showing "Rented".
     */
    protected function rentedSheet(): array
    {
        $service = new AvailabilityIngestService;
        $source = AvailabilitySource::first();
        $rows = $service->parseText($this->amsText(), $source->parse_options)['rows'];

        foreach ($rows as &$row) {
            if (($row['col1'] ?? '') === '1301') {
                $row['col6'] = 'Rented';
            }
        }
        unset($row);

        return $rows;
    }

    public function test_listed_unit_pm_says_rented_stays_listed_and_needs_a_decision(): void
    {
        $unit = $this->publishedListedUnit();
        $source = AvailabilitySource::first();
        $service = new AvailabilityIngestService;

        $result = $service->ingest($source, $this->rentedSheet(), $this->tenant->id, $this->adminUser->id);

        $unit->refresh();
        $this->assertSame('listed', $unit->availability);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(0, $result['missing']);
        $this->assertSame(5, $result['updated']);

        $this->assertDatabaseHas('availability_reviews', [
            'tenant_id' => $this->tenant->id,
            'property_id' => $unit->id,
            'reason' => 'sheet_says_leased',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('notifications', [
            'type' => AvailabilityConflictAlert::class,
            'notifiable_id' => $this->adminUser->id,
        ]);
    }

    public function test_listed_unit_missing_from_sheet_stays_listed_and_needs_a_decision(): void
    {
        $unit = $this->publishedListedUnit();
        $source = AvailabilitySource::first();
        $service = new AvailabilityIngestService;
        $full = $service->parseText($this->amsText(), $source->parse_options);

        // The short sheet no longer contains unit 1301 (listed) or 2310 (ready_to_list).
        $result = $service->ingest($source, array_slice($full['rows'], 3), $this->tenant->id, $this->adminUser->id);

        $unit->refresh();
        $this->assertSame('listed', $unit->availability);
        $this->assertSame(1, $result['conflicts']);

        $this->assertDatabaseHas('availability_reviews', [
            'tenant_id' => $this->tenant->id,
            'property_id' => $unit->id,
            'reason' => 'missing_from_sheet',
            'status' => 'pending',
        ]);

        // The non-listed missing unit still auto-leases as before.
        $other = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '2310')->first();
        $this->assertSame('leased', $other->availability);
    }

    public function test_ready_to_list_missing_unit_still_becomes_leased(): void
    {
        $this->publishedListedUnit(); // sets 1301 to listed
        $source = AvailabilitySource::first();
        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '2310')->first();
        $this->assertSame('ready_to_list', $unit->availability);

        $service = new AvailabilityIngestService;
        $full = $service->parseText($this->amsText(), $source->parse_options);
        $service->ingest($source, array_slice($full['rows'], 3), $this->tenant->id, $this->adminUser->id);

        $unit->refresh();
        $this->assertSame('leased', $unit->availability);
    }

    public function test_re_import_does_not_create_duplicate_pending_reviews(): void
    {
        $unit = $this->publishedListedUnit();
        $source = AvailabilitySource::first();
        $service = new AvailabilityIngestService;
        $rows = $this->rentedSheet();

        $service->ingest($source, $rows, $this->tenant->id, $this->adminUser->id);
        $service->ingest($source, $rows, $this->tenant->id, $this->adminUser->id);

        $this->assertSame(1, AvailabilityReview::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('property_id', $unit->id)
            ->count());
    }

    public function test_resolve_keep_listed_keeps_the_unit_listed(): void
    {
        $unit = $this->publishedListedUnit();
        $source = AvailabilitySource::first();
        $service = new AvailabilityIngestService;
        $service->ingest($source, $this->rentedSheet(), $this->tenant->id, $this->adminUser->id);

        $review = AvailabilityReview::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('property_id', $unit->id)->first();

        $this->post(route('availability-sources.reviews.resolve', $review), ['action' => 'keep_listed'])
            ->assertRedirect(route('availability-sources.reviews'));

        $unit->refresh();
        $review->refresh();

        $this->assertSame('listed', $unit->availability);
        $this->assertSame('keep_listed', $review->status);
        $this->assertSame($this->adminUser->id, $review->decided_by);
        $this->assertNotNull($review->decided_at);
    }

    public function test_resolve_unlist_sets_the_unit_unlisted(): void
    {
        $unit = $this->publishedListedUnit();
        $source = AvailabilitySource::first();
        $service = new AvailabilityIngestService;
        $service->ingest($source, $this->rentedSheet(), $this->tenant->id, $this->adminUser->id);

        $review = AvailabilityReview::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('property_id', $unit->id)->first();

        $this->post(route('availability-sources.reviews.resolve', $review), ['action' => 'unlist'])
            ->assertRedirect(route('availability-sources.reviews'));

        $unit->refresh();
        $review->refresh();

        $this->assertSame('unlisted', $unit->availability);
        $this->assertSame('unlist', $review->status);
        $this->assertNotNull($review->decided_at);
    }

    public function test_reviews_page_lists_pending_decisions(): void
    {
        $unit = $this->publishedListedUnit();
        $source = AvailabilitySource::first();
        $service = new AvailabilityIngestService;
        $service->ingest($source, $this->rentedSheet(), $this->tenant->id, $this->adminUser->id);

        $this->get(route('availability-sources.reviews'))
            ->assertOk()
            ->assertSee('1301')
            ->assertSee('Keep Listed')
            ->assertSee('Unlist');

        $this->get(route('availability-sources.index'))
            ->assertOk()
            ->assertSee('need a decision');
    }

    public function test_quick_reimport_uploads_file_and_updates_in_place_without_duplicates(): void
    {
        $source = $this->createAmsSource();

        $this->post(route('availability-sources.import-direct', $source), [
            'file' => UploadedFile::fake()->createWithContent('ams.txt', $this->amsText()),
        ])->assertRedirect(route('availability-sources.index'));

        $this->assertSame(5, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->count());

        $this->post(route('availability-sources.import-direct', $source), [
            'file' => UploadedFile::fake()->createWithContent('ams.txt', $this->amsText()),
        ])->assertRedirect(route('availability-sources.index'));

        $this->assertSame(5, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->count());

        $runs = AvailabilityImportRun::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_id', $source->id)->orderBy('id')->get();

        $this->assertCount(2, $runs);
        $this->assertSame('completed', $runs->last()->status);
        $this->assertSame(0, $runs->last()->created_rows);
        $this->assertSame(5, $runs->last()->updated_rows);
    }

    public function test_quick_reimport_records_conflict_rows_and_keeps_listed_unit(): void
    {
        $source = $this->createAmsSource();
        $service = new AvailabilityIngestService;
        $service->ingest($source, $service->parseText($this->amsText(), $source->parse_options)['rows'], $this->tenant->id, $this->adminUser->id);

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '1301')->first();
        $unit->update(['availability' => 'listed']);

        $this->post(route('availability-sources.import-direct', $source), [
            'file' => UploadedFile::fake()->createWithContent('ams.txt', implode("\n", [
                "Bey View Tower\t1301\t4 BR + Maids room 352 Sq Mtr / 3744 Sq Foot\t240,000\t12,000\t20,000\tRented\t2 Parkings\t",
            ])),
        ])->assertRedirect(route('availability-sources.index'));

        $unit->refresh();
        $this->assertSame('listed', $unit->availability);

        $this->assertDatabaseHas('availability_import_runs', [
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
            'status' => 'completed',
            'conflict_rows' => 1,
        ]);
    }

    // ── URL-published availability (e.g. RDK portfolio JSON) ───────────────

    /**
     * Mirrors the RDK portfolio shape (https://rdk.ae/Listing/data.json): a
     * hidden unit, an inactive property's unit, and a no-tower property.
     */
    protected function rdkPayload(): array
    {
        return [
            'properties' => [
                ['id' => 1, 'name' => 'RDK Towers Najmat', 'city' => 'Abu Dhabi', 'active' => true],
                ['id' => 2, 'name' => 'GP50 Abu Dhabi', 'city' => 'Abu Dhabi', 'active' => false],
                ['id' => 3, 'name' => 'Marriott Residences', 'city' => 'Dubai', 'active' => true],
            ],
            'units' => [
                ['id' => 1, 'pid' => 1, 'tower' => 'I', 'unit' => '206', 'type' => '1BR', 'desc' => 'Standard Layout', 'view' => 'Canal', 'rent' => 100000, 'display' => 'Show'],
                ['id' => 2, 'pid' => 1, 'tower' => 'I', 'unit' => '211', 'type' => '1BR', 'desc' => 'Guest Washroom', 'view' => 'Partial Sea', 'rent' => 95000, 'display' => 'Show'],
                ['id' => 3, 'pid' => 1, 'tower' => 'I', 'unit' => '306', 'type' => 'STUDIO', 'desc' => 'Standard Layout', 'view' => 'Canal', 'rent' => 90000, 'display' => 'Hide'],
                ['id' => 4, 'pid' => 2, 'tower' => 'A', 'unit' => '101', 'type' => '2BR', 'desc' => 'City View', 'view' => 'City', 'rent' => 180000, 'display' => 'Show'],
                ['id' => 5, 'pid' => 3, 'tower' => '', 'unit' => 'RA-02', 'type' => '7BR VILLA', 'desc' => '7BHK - DRIVER ROOM - STORAGE', 'view' => 'Community', 'rent' => 230000, 'display' => 'Show'],
            ],
        ];
    }

    protected function urlSource(): AvailabilitySource
    {
        return AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'RDK Properties',
            'url' => 'https://rdk.ae/Listing/data.json',
            'default_city' => 'Abu Dhabi',
            'missing_status' => 'unlisted',
            'column_map' => [
                'Unit' => 'unit_no',
                'Tower' => 'building',
                'Property' => 'community',
                'City' => 'city',
                'Type' => 'features',
                'Remarks' => 'remarks',
                'Rent' => 'rent',
            ],
        ]);
    }

    protected function fakeRdk(array ...$payloads): void
    {
        $call = 0;
        Http::fake(function ($request) use ($payloads, &$call) {
            $payload = $payloads[min($call, count($payloads) - 1)];

            $call++;

            return Http::response(json_encode($payload), 200);
        });
    }

    public function test_fetch_url_parses_rdk_portfolio_and_only_reads_published_units(): void
    {
        $this->fakeRdk($this->rdkPayload());
        $service = new AvailabilityIngestService;

        $table = $service->fetchUrl('https://rdk.ae/Listing/data.json');

        $this->assertSame(['Unit', 'Tower', 'Property', 'City', 'Type', 'Remarks', 'Rent'], $table['header']);
        $this->assertCount(3, $table['rows']);

        $byUnit = collect($table['rows'])->keyBy('Unit');
        $this->assertArrayNotHasKey('306', $byUnit->all()); // hidden unit
        $this->assertArrayNotHasKey('101', $byUnit->all()); // inactive property
        $this->assertSame('RDK Towers Najmat Tower I', $byUnit['206']['Tower']);
        $this->assertSame('Abu Dhabi', $byUnit['206']['City']);
        $this->assertSame('1BR', $byUnit['206']['Type']);
        $this->assertSame('100000', $byUnit['206']['Rent']);
        $this->assertSame('Marriott Residences', $byUnit['RA-02']['Tower']);
        $this->assertSame('View: Community. 7BHK - DRIVER ROOM - STORAGE', $byUnit['RA-02']['Remarks']);
        $this->assertSame('Dubai', $byUnit['RA-02']['City']);
    }

    public function test_sync_url_creates_units_in_place_and_records_a_url_run(): void
    {
        $source = $this->urlSource();
        $this->fakeRdk($this->rdkPayload());

        $this->post(route('availability-sources.sync-url', $source))
            ->assertRedirect(route('availability-sources.index'));

        $this->assertSame(3, Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('availability_source_id', $source->id)->count());

        $unit = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('source_unit_ref', '206')->first();
        $this->assertSame('RDK Towers Najmat Tower I', $unit->sub_community);
        $this->assertSame('RDK Towers Najmat', $unit->community);
        $this->assertSame('Abu Dhabi', $unit->city);
        $this->assertSame(1, (int) $unit->bedrooms);
        $this->assertSame(100000.0, (float) $unit->rent_price);
        $this->assertSame('ready_to_list', $unit->availability);

        $this->assertDatabaseHas('availability_import_runs', [
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
            'format' => 'url',
            'status' => 'completed',
            'created_rows' => 3,
        ]);
    }

    public function test_sync_url_keeps_listed_units_and_unlists_others_when_they_stop_being_published(): void
    {
        $source = $this->urlSource();

        // RDK publishes 206, 211 … then stops publishing both (206 was listed
        // on portals, 211 was just ready), and a new unit 212 replaces them.
        $first = $this->rdkPayload();
        $second = $this->rdkPayload();
        $second['units'] = array_values(array_filter($second['units'], fn ($u) => $u['id'] !== 1 && $u['id'] !== 2));
        $second['units'][] = ['id' => 6, 'pid' => 1, 'tower' => 'I', 'unit' => '212', 'type' => '2BR', 'desc' => '', 'view' => 'Sea', 'rent' => 140000, 'display' => 'Show'];
        $this->fakeRdk($first, $second);

        $this->post(route('availability-sources.sync-url', $source));

        $listed = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_unit_ref', '206')->first();
        $listed->update(['availability' => 'listed']);
        $plain = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_unit_ref', '211')->first();
        $this->assertSame('ready_to_list', $plain->availability);

        $this->post(route('availability-sources.sync-url', $source))
            ->assertRedirect(route('availability-sources.index'));

        // 206 was listed on portals → stays listed pending a decision.
        $listed->refresh();
        $this->assertSame('listed', $listed->availability);
        $this->assertDatabaseHas('availability_reviews', [
            'tenant_id' => $this->tenant->id,
            'property_id' => $listed->id,
            'reason' => 'missing_from_sheet',
            'status' => 'pending',
        ]);

        // 211 was only ready-to-list → hidden in RDK means unlisted, not leased.
        $plain->refresh();
        $this->assertSame('unlisted', $plain->availability);
        $this->assertStringContainsString('Marked unlisted per RDK Properties', $plain->notes);

        // 212 came on the market.
        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'source_unit_ref' => '212',
            'availability' => 'ready_to_list',
        ]);

        $this->assertDatabaseHas('availability_import_runs', [
            'tenant_id' => $this->tenant->id,
            'source_id' => $source->id,
            'status' => 'completed',
            'created_rows' => 1,
            'missing_rows' => 1,
            'conflict_rows' => 1,
        ]);
    }

    public function test_url_source_can_still_mark_missing_units_as_leased(): void
    {
        $source = $this->urlSource();
        $source->update(['missing_status' => 'leased']);

        $first = $this->rdkPayload();
        $second = $this->rdkPayload();
        $second['units'] = array_values(array_filter($second['units'], fn ($u) => $u['id'] !== 2));
        $second['units'][] = ['id' => 6, 'pid' => 1, 'tower' => 'I', 'unit' => '212', 'type' => '2BR', 'desc' => '', 'view' => 'Sea', 'rent' => 140000, 'display' => 'Show'];
        $this->fakeRdk($first, $second);

        $this->post(route('availability-sources.sync-url', $source));

        $plain = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_unit_ref', '211')->first();
        $this->assertSame('ready_to_list', $plain->availability);

        $this->post(route('availability-sources.sync-url', $source));

        $plain->refresh();
        $this->assertSame('leased', $plain->availability);
    }

    public function test_sync_url_fails_cleanly_when_no_url_or_fetch_fails(): void
    {
        $source = $this->createAmsSource();

        $this->post(route('availability-sources.sync-url', $source))
            ->assertSessionHasErrors('url');

        $urlSource = $this->urlSource();
        Http::fake(['https://rdk.ae/Listing/data.json' => Http::response('', 500)]);
        $this->post(route('availability-sources.sync-url', $urlSource))
            ->assertSessionHasErrors('url');

        // a failed run is recorded, not silently swallowed
        $this->assertDatabaseHas('availability_import_runs', [
            'tenant_id' => $this->tenant->id,
            'source_id' => $urlSource->id,
            'status' => 'failed',
        ]);
    }

    public function test_store_prefills_mapping_and_missing_status_for_url_sources(): void
    {
        $this->post(route('availability-sources.store'), [
            'name' => 'RDK Properties',
            'url' => 'https://rdk.ae/Listing/data.json',
        ])->assertRedirect();

        $source = AvailabilitySource::where('name', 'RDK Properties')->first();
        $this->assertNotNull($source);
        $this->assertSame('unlisted', $source->missing_status);
        $this->assertSame('unit_no', $source->column_map['Unit']);
        $this->assertSame('city', $source->column_map['City']);
    }

    public function test_row_city_overrides_the_source_default_city(): void
    {
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Mix City',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['delimiter' => 'tab', 'has_header' => false],
            'column_map' => [
                'col0' => 'unit_no',
                'col1' => 'building',
                'col2' => 'city',
                'col3' => 'rent',
            ],
        ]);

        $service = new AvailabilityIngestService;
        $rows = $service->parseText(implode("\n", [
            "501\tBurj X\tDubai\t250,000",
            "502\tBurj Y\t\t150,000",
        ]), $source->parse_options)['rows'];

        $service->ingest($source, $rows, $this->tenant->id, $this->adminUser->id);

        $dubai = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_unit_ref', '501')->first();
        $this->assertSame('Dubai', $dubai->city);

        $fallback = Property::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_unit_ref', '502')->first();
        $this->assertSame('Abu Dhabi', $fallback->city);
    }
}
