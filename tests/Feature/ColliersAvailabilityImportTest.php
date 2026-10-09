<?php

namespace Tests\Feature;

use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Services\AvailabilityIngestService;
use Tests\TestCase;
use ZipArchive;

/**
 * Colliers ships its availability as one workbook whose sheets are per-area
 * ("AUH1", "AL RAHA", "YAS"…), each with banner/title rows above the header,
 * plus two bulk staff-accommodation sheets. These tests pin the parsing
 * behaviour that makes such a workbook importable.
 */
class ColliersAvailabilityImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function colName(int $index): string
    {
        $name = '';
        while ($index >= 0) {
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26) - 1;
        }

        return $name;
    }

    /**
     * Build a minimal multi-sheet xlsx (inline strings) with the given rows,
     * mirroring the Colliers shape: workbook.xml + rels resolving each sheet
     * in tab order. A sheet may declare `leading_cols` empty columns before
     * its data (Colliers prints a blank column A on most sheets) — those are
     * omitted as cells, matching the real file, so the parser must preserve
     * the header's true column offset instead of re-indexing from 0.
     *
     * @param  array<string, array{leading_cols?: int, rows: array<int, array<int, string>>}>  $sheets
     */
    protected function makeWorkbook(array $sheets): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'colliers_').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetNodes = [];
        $rels = [];
        $index = 1;
        foreach ($sheets as $name => $spec) {
            $leadingCols = (int) ($spec['leading_cols'] ?? 0);
            $rid = 'rId'.$index;
            $sheetNodes[] = '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$index.'" r:id="'.$rid.'"/>';
            $rels[] = '<Relationship Id="'.$rid.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';

            $rowsXml = '';
            foreach ($spec['rows'] as $rowIndex => $cells) {
                $cellsXml = '';
                foreach ($cells as $colIndex => $value) {
                    $value = (string) $value;
                    if ($value === '') {
                        continue;
                    }
                    $cellsXml .= '<c r="'.$this->colName($leadingCols + $colIndex).$rowIndex.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                }
                if ($cellsXml === '') {
                    continue;
                }
                $rowsXml .= '<row r="'.$rowIndex.'">'.$cellsXml.'</row>';
            }

            $zip->addFromString(
                'xl/worksheets/sheet'.$index.'.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rowsXml.'</sheetData></worksheet>'
            );
            $index++;
        }

        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $sheetNodes).'</sheets></workbook>'
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', $rels).'</Relationships>'
        );

        $zip->close();

        return $tmp;
    }

    /**
     * @return array<string, array{leading_cols?: int, rows: array<int, array<int, string>>}>
     */
    protected function colliersSheets(): array
    {
        $nbsp = "\xC2\xA0";

        return [
            'AUH1' => [
                'rows' => [
                    1 => ['AVAILABILITY LIST AS OF OCTOBER 02, 2026'],
                    2 => ['APARTMENTS'],
                    3 => ['Property Name', 'Code', 'Unit Number', 'LOCATION', 'MAP', 'NUMBER OF BR', 'ANNUAL RENT (AED)', 'Remarks', 'AMENITIES', '', '', '', 'KEYS AVAILABILITY'],
                    4 => ['Swimming Pool', 'Gym', 'Parking'],
                    5 => ['STUDIOS'],
                    6 => ['Studio Tower', 'B9999', '101', 'Muroor', $nbsp.'https://maps.app.goo.gl/studio-map', 'Studio', '50000', '', '', '', '', '', 'Security'],
                    7 => ['2 BEDROOMS'],
                    8 => ['ALGHADEER - Dhabi (FreeHold)', 'B1514', 'ALG-SABIL-B1-1-20', 'Al Ghadeer', 'https://maps.app.goo.gl/wzc4umsUp4FPppPS6', '2', '66853', 'with 1 month free', '', '', '', '', 'Habib'],
                ],
            ],
            'AL RAHA' => [
                'leading_cols' => 1,
                'rows' => [
                    1 => ['AL RAHA'],
                    2 => ['AVAILABLE FOR RENT'],
                    3 => ['PROPERTY NAME', 'CODE', 'UNIT NO', 'LOCATION', 'GPS MAP', 'NO. OF BEDROOMS', 'ANNUAL RENT (AED)'],
                    4 => ['Golf Gardens Villas -Dhabi (FreeHold)', 'B1511', 'VILLA 3', 'Khalifa City', 'https://maps.app.goo.gl/ByqCs1EFNoXMqrbm8', '5', '400000'],
                ],
            ],
            'Staff Accomodation-Bulk' => [
                'rows' => [
                    1 => ['EMIRATES HUMANITARIAN CITY (STAFF ACCOMODATION - BULK)'],
                    2 => ['ID', 'Project/ Cluster', 'Building', 'Room', 'No. of Beds', 'Room Type', 'Features', 'Remarks'],
                    3 => ['1', 'CL1', 'A1', 'GF-001', '4', 'Type1', 'Common Bathroom', ''],
                ],
            ],
        ];
    }

    public function test_parses_every_area_sheet_and_skips_staff_blocks(): void
    {
        $path = $this->makeWorkbook($this->colliersSheets());
        $table = (new AvailabilityIngestService)->parseXlsx($path, ['has_header' => false]);

        $units = array_map(function ($row) {
            return ($row['Unit Number'] ?? '') !== ''
                ? $row['Unit Number']
                : ($row['UNIT NO'] ?? '');
        }, $table['rows']);
        $this->assertContains('101', $units, 'studio unit from AUH1 should parse');
        $this->assertContains('ALG-SABIL-B1-1-20', $units, '2BR unit from AUH1 should parse');
        $this->assertContains('VILLA 3', $units, 'villa from AL RAHA should parse');
        $this->assertNotContains('GF-001', $units, 'staff accommodation rows must never import');

        $this->assertContains('MAP', $table['header'], 'union header keeps each sheet\'s map column');
        $this->assertContains('GPS MAP', $table['header']);

        $studio = array_values(array_filter($table['rows'], fn ($row) => ($row['Unit Number'] ?? '') !== '' ? ($row['Unit Number'] === '101') : ($row['UNIT NO'] ?? '') === '101'))[0];
        $this->assertSame('Studio Tower', $studio['Property Name']);
        $this->assertSame('Studio', $studio['NUMBER OF BR']);
        $this->assertSame('https://maps.app.goo.gl/studio-map', $studio['MAP'], 'leading NBSP must be stripped from the map URL');
        $this->assertSame('Security', $studio['KEYS AVAILABILITY']);

        $villa = array_values(array_filter($table['rows'], fn ($row) => ($row['Unit Number'] ?? '') !== '' ? ($row['Unit Number'] === 'VILLA 3') : ($row['UNIT NO'] ?? '') === 'VILLA 3'))[0];
        $this->assertSame('400000', $villa['ANNUAL RENT (AED)']);
        $this->assertSame('5', $villa['NO. OF BEDROOMS']);

        @unlink($path);
    }

    public function test_header_row_is_found_below_the_banner_block(): void
    {
        $path = $this->makeWorkbook($this->colliersSheets());
        $table = (new AvailabilityIngestService)->parseXlsx($path, ['has_header' => true]);

        $this->assertSame('Unit Number', $table['header'][2] ?? null, 'row 3 of AUH1 must become the header, not the title rows above');
        $this->assertSame('ANNUAL RENT (AED)', $table['header'][6] ?? null);
        $this->assertContains('VILLA 3', array_map(fn ($row) => $row['UNIT NO'] ?? '', $table['rows']));

        @unlink($path);
    }

    public function test_auto_detected_mapping_covers_colliers_headers(): void
    {
        $service = new AvailabilityIngestService;
        $path = $this->makeWorkbook($this->colliersSheets());
        $table = $service->parseXlsx($path, ['has_header' => false]);
        $map = $service->detectColumnMap($table['header'], $table['rows']);

        $this->assertSame('building', $map['Property Name'] ?? null);
        $this->assertSame('unit_no', $map['Unit Number'] ?? null);
        $this->assertSame('community', $map['LOCATION'] ?? null);
        $this->assertSame('map_url', $map['MAP'] ?? null);
        $this->assertSame('map_url', $map['GPS MAP'] ?? null);
        $this->assertSame('bedrooms', $map['NUMBER OF BR'] ?? null);
        $this->assertSame('rent', $map['ANNUAL RENT (AED)'] ?? null);
        $this->assertSame('remarks', $map['KEYS AVAILABILITY'] ?? null);

        @unlink($path);
    }

    public function test_full_ingest_rebuilds_the_mapping_and_creates_the_units(): void
    {
        $source = AvailabilitySource::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Colliers',
            'default_city' => 'Abu Dhabi',
            'parse_options' => ['has_header' => false],
            'column_map' => [],
        ]);

        $path = $this->makeWorkbook($this->colliersSheets());
        $service = new AvailabilityIngestService;
        $table = $service->parseXlsx($path, ['has_header' => false]);

        $result = $service->ingest($source, $table['rows'], $this->tenant->id, $this->adminUser->id);

        $this->assertTrue($result['mapping_rebuilt'], 'empty saved map should be rebuilt from the headers');
        $this->assertSame(3, $result['created'], 'created='.$result['created'].' skipped='.json_encode($result['skipped_examples'] ?? []));
        $this->assertSame(3, $result['skipped'], 'the amenities sub-header and both section banners are not units');

        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'availability_source_id' => $source->id,
            'source_unit_ref' => '101',
            'sub_community' => 'Studio Tower',
            'unit_no' => '101',
            'bedrooms' => 0,
            'property_category' => 'apartment',
            'community' => 'Muroor',
            'rent_price' => 50000,
        ]);
        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'availability_source_id' => $source->id,
            'source_unit_ref' => 'ALG-SABIL-B1-1-20',
            'sub_community' => 'ALGHADEER - Dhabi (FreeHold)',
            'unit_no' => 'ALG-SABIL-B1-1-20',
            'bedrooms' => 2,
            'community' => 'Al Ghadeer',
            'rent_price' => 66853,
        ]);
        $this->assertDatabaseHas('properties', [
            'tenant_id' => $this->tenant->id,
            'availability_source_id' => $source->id,
            'source_unit_ref' => 'VILLA 3',
            'sub_community' => 'Golf Gardens Villas -Dhabi (FreeHold)',
            'unit_no' => 'VILLA 3',
            'property_category' => 'villa',
            'bedrooms' => 5,
            'community' => 'Khalifa City',
            'rent_price' => 400000,
        ]);

        $studio = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('source_unit_ref', '101')->first();
        $this->assertDatabaseHas('map_locations', [
            'tenant_id' => $this->tenant->id,
            'sub_community' => 'Studio Tower',
            'map_url' => 'https://maps.app.goo.gl/studio-map',
        ]);
        $this->assertStringContainsString('Security', (string) $studio->marketing_description, 'KEYS AVAILABILITY folds into remarks');

        $twoBed = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)->where('source_unit_ref', 'ALG-SABIL-B1-1-20')->first();
        $this->assertStringContainsString('with 1 month free / Habib', (string) $twoBed->marketing_description);

        @unlink($path);
    }
}
