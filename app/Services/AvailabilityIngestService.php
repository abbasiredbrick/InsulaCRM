<?php

namespace App\Services;

use App\Models\AvailabilityReview;
use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AvailabilityConflictAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

class AvailabilityIngestService
{
    /**
     * Turn an uploaded file into a table: ['header' => [...], 'rows' => [[header => value]]].
     */
    public function parseFile(string $path, string $format, array $parseOptions): array
    {
        return match (strtolower($format)) {
            'xlsx' => $this->parseXlsx($path, $parseOptions),
            'csv', 'txt' => $this->parseCsv($path, $parseOptions),
            default => throw new RuntimeException("Unsupported format: {$format}"),
        };
    }

    /**
     * Turn pasted text (e.g. from an emailed/PDF table) into a table.
     */
    public function parseText(string $text, array $parseOptions): array
    {
        $delimiter = $parseOptions['delimiter'] ?? 'multi_space';
        $hasHeader = (bool) ($parseOptions['has_header'] ?? false);

        $lines = preg_split('/\R/', $text) ?: [];
        $grid = [];
        foreach ($lines as $line) {
            $cells = match ($delimiter) {
                'tab' => str_getcsv($line, "\t", '"', '\\'),
                'comma' => str_getcsv($line),
                'semicolon' => str_getcsv($line, ';'),
                default => preg_split('/[ \t]{2,}/', trim($line)) ?: [],
            };
            $cells = array_map(fn ($c) => $this->cleanCell((string) $c), $cells);
            if (count($cells) === 1 && $cells[0] === '') {
                continue;
            }
            $grid[] = $cells;
        }

        return $this->gridToRows($grid, $parseOptions);
    }

    /**
     * Minimal dependency-free XLSX reader: shared strings + every worksheet.
     *
     * Workbooks such as Colliers' split their availability into per-area sheets
     * ("AUH1", "AL RAHA", "YAS"…) and put a banner block ("AVAILABILITY LIST…",
     * "2 BEDROOMS") above each table's header row. Every worksheet is parsed
     * through gridToRows() (which now finds the real header row below any
     * banner) and the results are merged onto a union of column headers.
     *
     * Sheets whose name marks bulk staff accommodation are skipped: those rows
     * are rooms ("FF-143", "Type2", "Private Bathroom") that carry no rent or
     * community and are not market inventory.
     */
    public function parseXlsx(string $path, array $parseOptions): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to read Excel files.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open Excel file.');
        }

        $shared = $this->readSharedStrings($zip);
        $sheets = $this->resolveWorkbookSheets($zip);
        if ($sheets === []) {
            $sheets = [[
                'name' => null,
                'target' => $zip->locateName('xl/worksheets/sheet1.xml') !== false
                    ? 'xl/worksheets/sheet1.xml'
                    : 'xl/worksheets/sheet.xml',
            ]];
        }

        $header = [];
        $parsedRows = [];
        $contextUrl = null;

        foreach ($sheets as $sheet) {
            if (($sheet['name'] ?? null) !== null && preg_match('/staff|accommodation/i', (string) $sheet['name'])) {
                continue;
            }

            $sheetXml = $zip->getFromName((string) $sheet['target']);
            if ($sheetXml === false) {
                continue;
            }
            $sheetNode = simplexml_load_string($sheetXml);
            if ($sheetNode === false) {
                continue;
            }

            $grid = $this->sheetXmlToGrid($sheetNode, $shared);
            if ($grid === []) {
                continue;
            }

            $parsed = $this->gridToRows($grid, $parseOptions);
            if ($contextUrl === null) {
                $contextUrl = $parsed['context_url'] ?? null;
            }
            foreach ($parsed['header'] as $name) {
                if ($name !== '' && ! in_array($name, $header, true)) {
                    $header[] = $name;
                }
            }
            foreach ($parsed['rows'] as $row) {
                $parsedRows[] = $row;
            }
        }

        // Re-key every row against the FINAL union header. Merging to the header
        // as it grows would leave rows[0] short of the later sheets' columns —
        // and ingest derives its detection header from array_keys($rows[0]).
        $rows = [];
        foreach ($parsedRows as $row) {
            $merged = [];
            foreach ($header as $name) {
                $merged[$name] = isset($row[$name]) ? $row[$name] : '';
            }
            $rows[] = $merged;
        }

        $zip->close();

        if ($rows === []) {
            throw new RuntimeException('Excel file contains no data.');
        }

        return ['header' => $header, 'rows' => $rows, 'context_url' => $contextUrl];
    }

    /**
     * Load the shared strings table once (it is shared by all worksheets).
     *
     * @return array<int, string>
     */
    protected function readSharedStrings(ZipArchive $zip): array
    {
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml === false) {
            return $shared;
        }

        $ss = simplexml_load_string($ssXml);
        if ($ss === false) {
            return $shared;
        }

        foreach ($ss->xpath('//*[local-name()="si"]') as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                $text .= (string) $t;
            }
            $shared[] = $text;
        }

        return $shared;
    }

    /**
     * Resolve the workbook's sheets in tab order: names come from
     * xl/workbook.xml, each sheet's r:id is mapped to its worksheet XML by
     * xl/_rels/workbook.xml.rels.
     *
     * @return array<int, array{name: string|null, target: string}>
     */
    protected function resolveWorkbookSheets(ZipArchive $zip): array
    {
        $targets = [];
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml !== false) {
            $rels = simplexml_load_string($relsXml);
            if ($rels !== false) {
                foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                    if ((string) $rel['Type'] !== 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet') {
                        continue;
                    }
                    $target = ltrim((string) $rel['Target'], '/');
                    $targets[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        $sheets = [];
        $wbXml = $zip->getFromName('xl/workbook.xml');
        if ($wbXml === false) {
            return $sheets;
        }
        $wb = simplexml_load_string($wbXml);
        if ($wb === false) {
            return $sheets;
        }

        $relsNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        foreach ($wb->xpath('//*[local-name()="sheet"]') as $sheet) {
            $rid = (string) $sheet->attributes($relsNs)['id'];
            if ($rid === '') {
                $rid = (string) $sheet['id'];
            }
            $target = $targets[$rid] ?? null;
            if ($target === null) {
                continue;
            }
            $sheets[] = ['name' => (string) $sheet['name'], 'target' => $target];
        }

        return $sheets;
    }

    /**
     * Turn one worksheet into a grid of column-indexed cells.
     *
     * @return array<int, array<int, string>>
     */
    protected function sheetXmlToGrid(\SimpleXMLElement $sheet, array $shared): array
    {
        $grid = [];
        foreach ($sheet->xpath('//*[local-name()="row"]') as $row) {
            $cells = [];
            foreach ($row->xpath('./*[local-name()="c"]') as $c) {
                $ref = (string) $c['r'];
                $letters = preg_replace('/\d+/', '', $ref);
                $idx = 0;
                foreach (str_split($letters ?: 'A') as $ch) {
                    $idx = $idx * 26 + (ord($ch) - 64);
                }
                $idx--;

                $type = (string) $c['t'];
                $raw = (string) $c->v;
                $value = '';
                if ($type === 's') {
                    $value = $shared[(int) $raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    foreach ($c->xpath('.//*[local-name()="t"]') as $t) {
                        $value .= (string) $t;
                    }
                } else {
                    $value = $raw;
                }
                $cells[$idx] = $this->cleanCell($value);
            }
            if ($cells) {
                $grid[] = $cells;
            }
        }

        return $grid;
    }

    public function parseCsv(string $path, array $parseOptions): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            throw new RuntimeException('Unable to read file.');
        }

        $delimiter = $this->normalizeDelimiter($parseOptions['delimiter'] ?? null, $path);
        $grid = [];
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            $cells = array_map(fn ($c) => $this->cleanCell((string) $c), $line);
            if (count($cells) === 1 && $cells[0] === '') {
                continue;
            }
            $grid[] = $cells;
        }
        fclose($handle);

        return $this->gridToRows($grid, $parseOptions);
    }

    /**
     * Fetch a PM's published availability from a public URL.
     *
     * Supports RDK-style portfolio JSON ({properties, units} — only the
     * published "Show" units are imported), TrueRentor's RESO-flavoured feed
     * ({spec: "truerentor.availability", organization, units}), a flat row-list
     * JSON, and falls back to CSV/TSV text for other sources.
     *
     * A bearer token may be supplied for authenticated feeds (TrueRentor).
     *
     * @return array{header: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function fetchUrl(string $url, ?string $token = null): array
    {
        $request = $token
            ? Http::withToken($token)->timeout(30)
            : Http::timeout(30);
        $response = $request->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Could not fetch availability URL ({$url}): HTTP {$response->status()}.");
        }

        $body = $response->body();
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            if (($decoded['spec'] ?? null) === 'truerentor.availability') {
                return $this->truerentorJsonToRows($decoded);
            }

            if (isset($decoded['units'], $decoded['properties']) && is_array($decoded['units'])) {
                return $this->rdkJsonToRows($decoded);
            }

            if ($this->looksLikeRowList($decoded)) {
                $header = array_keys($decoded[0]);
                $rows = [];
                foreach ($decoded as $item) {
                    $row = [];
                    foreach ($header as $column) {
                        $value = $item[$column] ?? '';
                        $row[$column] = is_scalar($value) ? trim((string) $value) : '';
                    }
                    $rows[] = $row;
                }

                return ['header' => $header, 'rows' => $rows];
            }

            throw new RuntimeException('Availability URL returned JSON, but not a recognised listings shape.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'avail_url_');
        file_put_contents($tmp, $body);

        try {
            return $this->parseCsv($tmp, ['delimiter' => 'auto']);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Turn an RDK portfolio payload (https://rdk.ae/Listing/data.json) into
     * rows. Only units published as available ("display": "Show") inside an
     * active property appear — "Hide" units are inventory, not availability.
     *
     * @return array{header: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function rdkJsonToRows(array $data): array
    {
        $properties = [];
        foreach ($data['properties'] ?? [] as $property) {
            if (is_array($property) && isset($property['id'])) {
                $properties[(string) $property['id']] = $property;
            }
        }

        $header = ['Unit', 'Tower', 'Property', 'City', 'Type', 'Remarks', 'Rent'];
        $rows = [];

        foreach ($data['units'] ?? [] as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            if (($unit['display'] ?? 'Hide') !== 'Show') {
                continue;
            }

            $property = $properties[(string) ($unit['pid'] ?? '')] ?? null;
            if ($property && ($property['active'] ?? true) === false) {
                continue;
            }

            $name = trim((string) ($property['name'] ?? ''));
            $tower = trim((string) ($unit['tower'] ?? ''));
            $building = trim($name.($tower !== '' ? ' Tower '.$tower : ''));

            $view = trim((string) ($unit['view'] ?? ''));
            $desc = trim((string) ($unit['desc'] ?? ''));
            $remarks = trim(($view !== '' ? 'View: '.$view : '').($desc !== '' ? (($view !== '' ? '. ' : '').$desc) : ''));

            $rent = (int) ($unit['rent'] ?? 0);

            $rows[] = [
                'Unit' => trim((string) ($unit['unit'] ?? '')),
                'Tower' => $building,
                'Property' => $name,
                'City' => trim((string) ($property['city'] ?? '')),
                'Type' => trim((string) ($unit['type'] ?? '')),
                'Remarks' => $remarks,
                'Rent' => $rent > 0 ? (string) $rent : '',
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('The availability URL lists no published ("Show") units.');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Turn a TrueRentor availability feed into rows keyed by Keystone's
     * canonical field names. The feed's `share_path` already carries the
     * broker's referral code, so leads submitted through it are attributed.
     *
     * @return array{header: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function truerentorJsonToRows(array $data): array
    {
        $header = ['unit_no', 'building', 'community', 'features', 'bedrooms', 'square_footage', 'rent', 'furnishing', 'status', 'remarks'];
        $rows = [];

        foreach ($data['units'] ?? [] as $u) {
            if (! is_array($u)) {
                continue;
            }

            $rows[] = [
                'unit_no' => trim((string) ($u['label'] ?? '')),
                'building' => trim((string) ($u['building_name'] ?? '')),
                'community' => trim((string) ($u['building_address'] ?? '')),
                'features' => trim((string) ($u['type'] ?? '')),
                'bedrooms' => (string) ($u['bedrooms'] ?? ''),
                'square_footage' => (string) ($u['size_sqm'] ?? ''),
                'rent' => (string) ($u['listing_rate'] ?? ''),
                'furnishing' => ! empty($u['furnished']) ? 'Furnished' : 'Unfurnished',
                'status' => 'listed',
                'remarks' => trim(implode(' · ', array_filter([
                    isset($u['listing_id']) ? "listing_id={$u['listing_id']}" : null,
                    $u['share_path'] ?? null,
                    $u['cover_url'] ?? null,
                ]))),
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('The TrueRentor availability feed lists no units.');
        }

        return ['header' => $header, 'rows' => $rows];
    }

    protected function looksLikeRowList(array $decoded): bool
    {
        if ($decoded === [] || ! array_is_list($decoded) || ! is_array($decoded[0])) {
            return false;
        }

        return $decoded[0] !== [];
    }

    /**
     * Apply a saved column map + normalizers and upsert into the property inventory,
     * reconciling units that disappeared from the sheet.
     *
     * @return array<string, mixed>
     */
    public function ingest(AvailabilitySource $source, array $rows, int $tenantId, ?int $userId = null, ?int $runId = null, bool $guardReconciliation = true, ?string $contextUrl = null): array
    {
        $columnMap = $source->column_map ?: [];
        $parseOptions = $source->parse_options ?: [];
        $statusMap = $this->normalizedStatusMap($source->status_map ?: []);
        $city = $source->default_city ?: 'Abu Dhabi';

        // If the saved column map shares NO columns with the parsed rows, the file
        // must be laid out differently (e.g. a legacy positional "colN" map fed a
        // proper header CSV). Rebuild the map automatically from the header names.
        // Positional "colN" keys are ignored here: headerless files name every bucket
        // "col%d", but a designed header CSV also pads trailing columns as col8…col25,
        // so an empty overlap test would falsely "succeed" on the padding columns.
        $mappingRebuilt = false;
        if ($rows !== []) {
            $rowKeys = array_keys($rows[0]);
            $savedMapKeys = array_filter(
                array_keys($columnMap),
                fn ($key) => ! preg_match('/^col\d+$/i', (string) $key)
            );
            if ($savedMapKeys === [] || array_intersect($savedMapKeys, $rowKeys) === []) {
                $detected = $this->detectHeaderMap($rowKeys, $rows);
                if ($detected !== []) {
                    $columnMap = $detected;
                    $mappingRebuilt = true;
                }
            }
        }

        $areaUnit = $this->inferAreaUnit($columnMap);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;
        $conflicts = 0;
        $seenRefs = [];
        $skippedExamples = [];
        $lastBuilding = '';
        $lastCommunity = '';
        $ensuredBuildings = [];

        // Snapshot the source's current units so we can tell whether this run
        // actually matched them (broken mappings silently create "Other"
        // duplicates and leave the real units untouched).
        $beforeKeys = [];
        Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('availability_source_id', $source->id)
            ->select('id', 'sub_community', 'source_unit_ref')
            ->chunkById(500, function ($rows) use (&$beforeKeys) {
                foreach ($rows as $property) {
                    $beforeKeys[($property->sub_community ?? '').'|'.($property->source_unit_ref ?? '')] = true;
                }
            });

        DB::transaction(function () use (
            $rows, $columnMap, $parseOptions, $statusMap, $city, $source, $tenantId, $runId,
            $contextUrl, $areaUnit, &$created, &$updated, &$skipped, &$total, &$conflicts, &$seenRefs,
            &$skippedExamples, &$lastBuilding, &$lastCommunity, &$ensuredBuildings
        ) {
            foreach ($rows as $row) {
                $total++;
                $data = [];
                $featuresRaw = '';
                $amenitiesRaw = '';
                $remarksRaw = '';
                $rentRaw = '';
                $bedroomsRaw = '';
                $rentRaw = '';
                $bedroomsRaw = '';

                foreach ($columnMap as $header => $field) {
                    if (isset($row[$header])) {
                        $raw = trim((string) $row[$header]);
                        if ($raw === '') {
                            continue;
                        }
                        if ($field === 'bedrooms') {
                            $bedroomsRaw = trim(($bedroomsRaw !== '' ? $bedroomsRaw.' / ' : '').$raw);
                        }
                        if ($field === 'features') {
                            $featuresRaw = trim(($featuresRaw !== '' ? $featuresRaw.' / ' : '').$raw);

                            continue;
                        }
                        if ($field === 'amenities') {
                            $amenitiesRaw = trim(($amenitiesRaw !== '' ? $amenitiesRaw.' / ' : '').$raw);

                            continue;
                        }
                        if ($field === 'remarks') {
                            $remarksRaw = trim(($remarksRaw !== '' ? $remarksRaw.' / ' : '').$raw);

                            continue;
                        }
                        if ($field === 'square_footage') {
                            $data[$field] = $this->areaNumeric($raw, $areaUnit === 'sqft');

                            continue;
                        }
                        if ($field === 'rent' || $field === 'rent_price') {
                            $rentRaw = $raw;
                        }
                        $data[$field] = $this->normalizeField($field, $raw);
                    }
                }

                $unitNo = trim((string) ($data['unit_no'] ?? ''));
                if ($unitNo !== '' && preg_match('/\b(unit\s*no|no\.?\s*of\s*b|number|s\.?\s*no|sr\.?\s*no)\b/i', $unitNo) && ! preg_match('/[0-9]/', $unitNo)) {
                    $unitNo = '';
                }

                // Block-style sheets (AMS etc.) only print the building/area on the
                // first row of a group — even on a building-only header row with no
                // unit number — so capture the carry BEFORE the row is skipped.
                $building = trim((string) ($data['building'] ?? $data['sub_community'] ?? ''));
                if ($building === '') {
                    $building = $lastBuilding;
                } else {
                    $lastBuilding = $building;
                }
                $community = trim((string) ($data['community'] ?? ''));
                if ($community === '') {
                    $community = $lastCommunity;
                } else {
                    $lastCommunity = $community;
                }

                $looksLikeUnit = $unitNo !== '' && (
                    preg_match('/[0-9]/', $unitNo)
                    || preg_match('/^(villa|plot|office|retail|shop|showroom|store|kiosk|booth|commercial|unit|penthouse)/i', $unitNo)
                );
                if (! $looksLikeUnit) {
                    $skipped++;
                    if (count($skippedExamples) < 5) {
                        $skippedExamples[] = implode(' | ', array_values(array_filter(array_map(
                            fn ($row) => is_scalar($row) ? trim((string) $row) : '',
                            $row
                        ))));
                    }

                    continue;
                }
                if ($building === '') {
                    $building = $source->default_building ?: '';
                }
                if ($building === '') {
                    $building = 'Other';
                }

                $features = $this->featuresFromRaw($featuresRaw);
                if (($features['bedrooms'] ?? null) === null && $rentRaw !== '' && preg_match('/\b(\d+)\s*(?:BR|BD|BHK|BED(?:ROOM)?S?)/i', $rentRaw, $m)) {
                    $features['bedrooms'] = (int) $m[1];
                }
                // Category detection sees the unit number too, not just the
                // features and building: AMS writes "Retail 3" purely in the
                // unit column, and a sheet with no type text would otherwise
                // import every such row as an apartment.
                $category = $data['property_category'] ?? ($source->default_category ?: $this->detectCategory(trim(implode(' ', array_filter([$featuresRaw, $building, $unitNo])))));
                $category = $category ?: 'apartment';

                $rawStatus = (string) ($data['status'] ?? $data['source_status'] ?? '');
                $availability = $this->resolveAvailability($statusMap, $rawStatus);

                $handover = null;
                $explicitAvailableFrom = null;
                $keyNotes = [];
                $availableNow = false;
                foreach (['key_date', 'handover_date', 'available_from'] as $fd) {
                    if (! empty($data[$fd])) {
                        $parsed = $this->parseFlexibleDate($data[$fd]);
                        if ($parsed) {
                            if ($fd === 'available_from') {
                                $explicitAvailableFrom = $parsed;
                            } else {
                                $handover = $parsed;
                            }
                        } elseif ($this->isAvailableNow($data[$fd])) {
                            $availableNow = true;
                        } else {
                            $keyNotes[] = $data[$fd];
                        }
                    }
                }

                // "Upcoming" units carry the date they become available (the sheet's
                // expected vacating date) in the dedicated available_from field; that
                // date is not a handover date, so keep handover_date clear for them.
                $isUpcoming = $availability === 'upcoming';
                $availableFrom = $explicitAvailableFrom ?: ($isUpcoming ? $handover : null);
                $handoverDate = $isUpcoming ? null : $handover;

                // "Studio" in the unit type beats a Bedrooms column that says 1 -
                // otherwise a re-import publishes the studio as a 1BR.
                $bedrooms = ! empty($features['is_studio']) ? 0 : ($data['bedrooms'] ?? $features['bedrooms']);

                // Sheets do not agree on which column carries the size. Bloom
                // (and others) leave the unit type blank and write "Flat Studio"
                // into the remarks prose, so a studio would import with no bedroom
                // count at all and stay invisible in the studio filter. When no
                // bedroom count could be derived anywhere, fall back to the studio
                // token in any free-text column. Only applied to an undetermined
                // count: remarks are prose ("studio available next door") and must
                // never override a real bedroom number.
                if ($bedrooms === null && $this->mentionsStudio($featuresRaw, $remarksRaw, $amenitiesRaw)) {
                    $bedrooms = 0;
                }
                $squareFootage = $data['square_footage'] ?? $features['square_footage'];
                $furnishing = $data['furnishing'] ?? $features['furnishing'];

                // A maid's room is read from the features/unit type first; the
                // Bedrooms cell (AMS writes "3 BR + Maid - (309 SQM)") and the
                // remarks prose are consulted when nothing was found there
                // (RDK puts "2BHK + MAID - BALCONY" in the description).
                $maidsRoom = ! empty($features['maids_room']);
                if (! $maidsRoom && $this->mentionsMaid($bedroomsRaw)) {
                    $maidsRoom = true;
                }
                if (! $maidsRoom && $this->mentionsMaid($remarksRaw.' '.$amenitiesRaw)) {
                    $maidsRoom = true;
                }

                $parking = isset($data['parking']) && $data['parking'] !== '' ? $data['parking'] : null;

                $remarkLine = trim((string) $remarksRaw);
                $commissionNote = preg_match('/commission/i', $remarkLine) ? $remarkLine : null;
                $amenityLine = trim((string) $amenitiesRaw);

                $rentPrice = $data['rent'] ?? $data['rent_price'] ?? null;
                $perSqm = (bool) ($parseOptions['rent_is_per_sqm'] ?? false);
                if ($rentPrice !== null && ($perSqm || ($rentRaw !== '' && preg_match('/\bper\s*sq/', strtolower($rentRaw))))) {
                    $sqm = ($squareFootage !== null && $squareFootage > 0) ? $squareFootage / 10.7639 : null;
                    if ($sqm !== null && $sqm > 0) {
                        $rentPrice = (int) round($rentPrice * $sqm);
                    }
                }
                if ($rentPrice === null && $remarkLine !== '') {
                    $rentPrice = $this->rentFromRemarks($remarkLine);
                }
                // Deposit is resolved after rent so a "max(min, pct × rent)"
                // source default (e.g. AED 5,000 or 5% of annual rent,
                // whichever is higher) can be computed per row.
                $deposit = $data['deposit'] ?? $data['deposit_amount'] ?? $this->defaultDeposit($source, $rentPrice);
                $adminFee = $data['admin_fee'] ?? $source->default_admin_fee ?? null;
                // Source sheets spell this several ways; all of them land in
                // contract_fee. default_tawtheeq_fee keeps its name because it
                // mirrors the sheet's own column heading.
                $contractFee = $data['contract_fee'] ?? $data['tawtheeq'] ?? $data['tawtheeq_fee'] ?? $source->default_tawtheeq_fee ?? null;

                $notesParts = ["Source: {$source->name} availability sheet."];

                $city = trim((string) ($data['city'] ?? ''));
                if ($city === '') {
                    $city = $source->default_city ?: 'Abu Dhabi';
                }
                if ($rawStatus !== '') {
                    $notesParts[] = "Status on sheet: {$rawStatus}.";
                }
                if ($availableFrom) {
                    $notesParts[] = "Available from: {$availableFrom->format('d.m.Y')}.";
                } elseif ($handover) {
                    $notesParts[] = "Vacant by: {$handover->format('d.m.Y')}.";
                }
                foreach ($keyNotes as $keyNote) {
                    $notesParts[] = "Keys: {$keyNote}.";
                }
                if ($availableNow) {
                    $notesParts[] = 'Available immediately.';
                }
                if ($commissionNote) {
                    $notesParts[] = "{$commissionNote}.";
                } elseif (preg_match('/commission/i', $remarkLine)) {
                    $notesParts[] = "{$remarkLine}.";
                }
                if ($amenityLine !== '') {
                    $notesParts[] = "Amenities: {$amenityLine}.";
                }

                $descriptionParts = [];
                if ($features['summary'] !== '') {
                    $descriptionParts[] = $features['summary'];
                }
                if ($amenityLine !== '') {
                    $descriptionParts[] = "Amenities: {$amenityLine}.";
                }
                if (! empty($data['view'])) {
                    $descriptionParts[] = 'View: '.$data['view'].'.';
                }
                if (! empty($data['balcony'])) {
                    $descriptionParts[] = 'Balcony: '.ucfirst((string) $data['balcony']).'.';
                }
                if ($remarkLine !== '') {
                    $descriptionParts[] = $remarkLine;
                }
                if ($deposit !== null || $adminFee !== null || $contractFee !== null) {
                    $bits = [];
                    if ($deposit !== null) {
                        $bits[] = 'Deposit: AED '.number_format($deposit);
                    }
                    if ($adminFee !== null) {
                        $bits[] = 'Admin fee: AED '.number_format($adminFee);
                    }
                    if ($contractFee !== null) {
                        $bits[] = 'Contract Fee: AED '.number_format($contractFee);
                    }
                    $descriptionParts[] = implode(' | ', $bits);
                }
                if ($maidsRoom && ! $this->mentionsMaid(implode(' ', $descriptionParts))) {
                    $descriptionParts[] = "Maid's room.";
                }
                $marketingDescription = implode(' ', array_filter($descriptionParts));

                $marketingTitle = $this->buildMarketingTitle($bedrooms, $category, $building, $maidsRoom);

                $address = trim(implode(' ', array_filter([
                    $unitNo,
                    $building,
                    $data['community'] ?? null,
                    $city,
                ])));

                // ── Map location (per building) ─────────────────────────────
                // A source-provided link (URL column, AY pre-table line) is
                // resolved here. The map_locations entry is ensured after the
                // row is written — first-write-wins, so a correction made in
                // Settings survives every re-import.
                $sourceMapUrl = $data['map_url'] ?? $contextUrl ?? null;
                if ($sourceMapUrl !== null && $sourceMapUrl !== '') {
                    $sourceMapUrl = $this->normalizeField('map_url', $sourceMapUrl);
                }

                $record = [
                    'tenant_id' => $tenantId,
                    'availability_source_id' => $source->id,
                    'source_unit_ref' => $unitNo,
                    'intent' => 'rent',
                    'state' => '',
                    'zip_code' => '',
                    'market_class' => 'ready',
                    'property_category' => $category,
                    'community' => $community ?: null,
                    'sub_community' => $building,
                    'city' => $city,
                    'unit_no' => $unitNo,
                    'floor_no' => $data['floor_no'] ?? null,
                    'plot_no' => $data['plot_no'] ?? null,
                    'bedrooms' => $bedrooms !== null ? (int) $bedrooms : null,
                    'maids_room' => $maidsRoom,
                    'square_footage' => $squareFootage,
                    'furnishing' => $furnishing,
                    'parking' => $parking,
                    'balcony' => $data['balcony'] ?? null,
                    'view' => $data['view'] ?? null,
                    'rent_price' => $rentPrice,
                    'deposit_amount' => $deposit,
                    'admin_fee' => $adminFee,
                    'contract_fee' => $contractFee,
                    'rent_period' => 'yearly',
                    'handover_date' => $handoverDate ? $handoverDate->toDateString() : null,
                    'available_from' => $availableFrom ? $availableFrom->toDateString() : null,
                    'address' => $address,
                    'marketing_title' => $marketingTitle,
                    'marketing_description' => $marketingDescription,
                    'notes' => implode(' ', $notesParts),
                    'availability_synced_at' => now()->toDateString(),
                    'owner_name' => $source->name,
                ];

                $existing = Property::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('availability_source_id', $source->id)
                    ->where('source_unit_ref', $unitNo)
                    ->where('sub_community', $building)
                    ->first();

                // Link a pre-existing unit (created manually / from another source)
                // so re-imports update it instead of creating a duplicate.
                if (! $existing) {
                    $existing = Property::withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('intent', 'rent')
                        ->where('unit_no', $unitNo)
                        ->whereNull('availability_source_id')
                        ->where(function ($query) use ($building) {
                            $query->where('sub_community', $building)
                                ->orWhereNull('sub_community')
                                ->orWhere('sub_community', '');
                        })
                        ->first();
                }

                if ($existing) {
                    // A currently-listed unit the sheet now leases must NOT be
                    // pulled off the portals automatically (madhmoun/marmoom
                    // permits are costly to re-issue). Flag it for a human
                    // decision and keep it listed so leads keep flowing in and
                    // can be diverted to other units meanwhile.
                    $leaseConflict = $existing->availability === 'listed' && $availability === 'leased';

                    if ($leaseConflict) {
                        $record['availability'] = 'listed';
                        $record['notes'] = trim(($record['notes'] ?? '').' '.sprintf(
                            'PM sheet marks this unit as leased per %s update on %s — kept listed pending a decision.',
                            $source->name,
                            now()->format('d.m.Y')
                        ));
                        $existing->update($record);
                        $updated++;

                        $this->flagConflict($existing, $source, 'sheet_says_leased', $runId);
                        $conflicts++;
                    } else {
                        $record['availability'] = $availability;
                        $existing->update($record);
                        $updated++;
                    }
                } else {
                    // New units get the exact status shared by the PM.
                    $record['availability'] = $availability;
                    Property::create($record);
                    $created++;
                }

                if ($building !== '' && $building !== null && ! isset($ensuredBuildings[$building])) {
                    $ensuredBuildings[$building] = true;
                    $location = app(MapLocationService::class)->ensureMapLocation(
                        $tenantId,
                        $building,
                        $community,
                        $city,
                        $sourceMapUrl
                    );
                    if ($location) {
                        app(MapLocationService::class)->linkBuildingUnits($tenantId, $building, $location);
                    }
                }

                $seenRefs["{$building}|{$unitNo}"] = true;
            }
        });

        $missing = 0;
        $reconciliationSkipped = false;
        if ($created > 0 || $updated > 0 || $total > 0) {
            $linked = Property::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('availability_source_id', $source->id)
                ->get();

            // Safety net: when a run matched only a small fraction of the
            // source's pre-existing units, a broken mapping/parse is far more
            // likely than a mass lease — never bulk-mark the rest as leased on
            // a run like that. URL-published lists legitimately shrink, so the
            // guard is disabled there.
            $beforeCount = count($beforeKeys);
            $matchedBefore = count(array_intersect_key($beforeKeys, $seenRefs));
            if ($guardReconciliation && $beforeCount > 0 && $matchedBefore < max(1, (int) ceil($beforeCount * 0.3))) {
                $reconciliationSkipped = true;
            }

            if (! $reconciliationSkipped) {
                foreach ($linked as $property) {
                    $key = ($property->sub_community ?? '').'|'.($property->source_unit_ref ?? '');
                    if (isset($seenRefs[$key]) || $property->availability_synced_at === null) {
                        continue;
                    }

                    // Listed units that dropped off the sheet keep their portal
                    // listing until someone decides what to do with them — the
                    // re-listing permit is expensive to regenerate.
                    if ($property->availability === 'listed') {
                        $this->flagConflict($property, $source, 'missing_from_sheet', $runId);
                        $conflicts++;

                        continue;
                    }

                    // Otherwise mirror what the PM shared: not on the sheet → apply
                    // the source's "missing" status (leased by default, unlisted for
                    // URL-published lists whose "hide" just means not published).
                    $missingStatus = $source->missing_status ?: 'leased';
                    if (! in_array($missingStatus, ['listed', 'ready_to_list', 'reserved', 'leased', 'sold', 'unlisted', 'draft'], true)) {
                        $missingStatus = 'leased';
                    }
                    $append = $missingStatus === 'leased'
                        ? 'Leased per '.$source->name.' availability update on '.now()->format('d.m.Y').'.'
                        : 'Marked '.$missingStatus.' per '.$source->name.' availability update on '.now()->format('d.m.Y').'.';
                    $property->update([
                        'availability' => $missingStatus,
                        'notes' => trim(($property->notes ?? '').' '.$append),
                    ]);
                    $missing++;
                }
            }
        }

        $source->update(['last_imported_at' => now()]);

        return [
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'missing' => $missing,
            'conflicts' => $conflicts,
            'skipped' => $skipped,
            'skipped_examples' => $skippedExamples,
            'reconciliation_skipped' => $reconciliationSkipped,
            'mapping_rebuilt' => $mappingRebuilt,
            'column_map' => $columnMap,
        ];
    }

    // ── Listed-unit conflict review ─────────────────────────────────────────

    /**
     * Record (once) that a listed unit needs an unlist / keep-listed decision,
     * then notify the assigned agent and admins the first time only.
     */
    protected function flagConflict(Property $property, AvailabilitySource $source, string $reason, ?int $runId): void
    {
        $review = AvailabilityReview::withoutGlobalScopes()
            ->where('tenant_id', $property->tenant_id)
            ->where('property_id', $property->id)
            ->where('status', 'pending')
            ->first();

        if ($review) {
            $review->update([
                'run_id' => $runId,
                'notes' => trim(($review->notes ?? '').' '.sprintf(
                    'Again flagged on %s (%s).',
                    now()->format('d.m.Y'),
                    AvailabilityReview::REASONS[$reason] ?? $reason
                )),
            ]);

            return;
        }

        AvailabilityReview::create([
            'tenant_id' => $property->tenant_id,
            'property_id' => $property->id,
            'source_id' => $source->id,
            'run_id' => $runId,
            'reason' => $reason,
            'availability_before' => $property->availability ?? 'listed',
            'status' => 'pending',
            'notes' => sprintf(
                'Flagged on %s: %s.',
                now()->format('d.m.Y'),
                AvailabilityReview::REASONS[$reason] ?? $reason
            ),
        ]);

        $this->notifyConflict($property, $source);
    }

    protected function notifyConflict(Property $property, AvailabilitySource $source): void
    {
        $tenant = $property->tenant;
        if (! $tenant || ! $tenant->wantsNotification('availability_conflict')) {
            return;
        }

        $review = AvailabilityReview::withoutGlobalScopes()
            ->where('tenant_id', $property->tenant_id)
            ->where('property_id', $property->id)
            ->latest('id')
            ->first();

        if (! $review) {
            return;
        }

        $recipients = collect();

        if ($property->assigned_agent_id && $property->assignedAgent) {
            $recipients->push($property->assignedAgent);
        }

        $admins = User::where('tenant_id', $property->tenant_id)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin']))
            ->get();

        $recipients = $recipients->merge($admins)->unique('id')->filter(
            fn ($user) => $user instanceof User
        );

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new AvailabilityConflictAlert($review, $source->name));
            } catch (\Throwable $e) {
                Log::error("AvailabilityConflictAlert failed for user {$recipient->id}: {$e->getMessage()}");
            }
        }
    }

    // ── Normalizers ─────────────────────────────────────────────────────────

    protected function normalizeField(string $field, $raw): mixed
    {
        $value = trim((string) $raw);

        return match ($field) {
            'unit_no', 'building', 'floor_no', 'plot_no', 'community',
            'rera_permit_no', 'title_deed_no', 'owner_name' => $value,
            'rent_price', 'rent', 'deposit', 'deposit_amount', 'admin_fee',
            'contract_fee', 'tawtheeq', 'tawtheeq_fee',
            'service_charge', 'list_price' => $this->moneyNumeric($value),
            'parking' => $this->parkingCount($value),
            'bedrooms' => $this->bedroomCount($value),
            'bathrooms' => $this->bathroomCount($value),
            'balcony' => $this->balcony($value),
            'square_footage' => $this->areaNumeric($value),
            'handover_date', 'available_from', 'key_date' => $value,
            'source_status', 'status' => $value,
            'furnishing' => $this->furnishing($value),
            'property_category' => $this->detectCategory($value),
            'map_url' => app(MapLocationService::class)->cleanUrl($value),
            default => $value,
        };
    }

    protected function moneyNumeric(string $value): ?float
    {
        $multiplier = 1;

        if (preg_match('/(\d)\s*[Mm]/', $value)) {
            $multiplier = 1_000_000;
        } elseif (preg_match('/(\d)\s*[Kk]/', $value)) {
            $multiplier = 1_000;
        }

        if (preg_match('/^([\d]+(?:[.,][\d]{3})*(?:[.,][\d]+)?)\s+\d+\s*(?:BR|BHK|BD|BED(?:ROOM)?S?)/i', $value, $m)) {
            $value = $m[1];
        }

        $value = preg_replace('/[^0-9.]/', '', $value);
        $value = rtrim((string) $value, '.');

        if ($value === '' || $value === '.') {
            return null;
        }

        return (float) $value * $multiplier;
    }

    protected function rentFromRemarks(string $value): ?float
    {
        if (preg_match('/^\s*(?:d|aed|dh|dhs|dirham|درهم)\s*([\d][\d,]*(?:\.\d+)?)/i', $value, $m)
            || preg_match('/\brent(?:al)?\s*(?:of\s*|\:)?\s*(?:d|aed|dh|dhs)?\s*([\d][\d,]*(?:\.\d+)?)/i', $value, $m)) {
            return $this->moneyNumeric($m[1]);
        }

        return null;
    }

    /**
     * Deposit default for a row with no explicit deposit column: either the
     * fixed default_deposit or the formula max(default_deposit_min,
     * default_deposit_pct% × annual rent) used by Relevate-style sheet policies.
     */
    protected function defaultDeposit(AvailabilitySource $source, $rentPrice): ?float
    {
        $pct = $source->default_deposit_pct;
        if ($pct !== null && $rentPrice !== null && (float) $rentPrice > 0) {
            $amount = max((float) ($source->default_deposit_min ?? 0), (float) $rentPrice * ((float) $pct / 100));

            return round($amount, 2);
        }

        return $source->default_deposit !== null ? (float) $source->default_deposit : null;
    }

    protected function parkingCount(string $value): ?int
    {
        $value = strtolower(trim($value));
        if ($value === '' || in_array($value, ['—', '-', 'n/a', 'none'])) {
            return null;
        }
        $words = ['zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3];
        foreach ($words as $word => $n) {
            if (str_starts_with($value, $word)) {
                return $n;
            }
        }
        if (preg_match('/^(\d+)/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Does this sheet text call the unit a studio?
     *
     * Single definition shared by every size-detection path so the studio token
     * cannot drift: the unit type column, the Bedrooms column, and the free-text
     * fallback applied when no bedroom count could be derived at all.
     */
    protected function mentionsStudio(string ...$texts): bool
    {
        foreach ($texts as $text) {
            if (preg_match('/\bstudio\b/i', (string) $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a bedroom count from a "No. of Bedrooms" style cell, which often
     * carries extra digits that are NOT part of the count, e.g.
     * "3 BR + Maid - (309 SQM)", "1 BR - 871.34 Square Foot",
     * "2 BR + Laundry/1665.95 Sq. Ft.". Only the number bound to a
     * BR/BD/BHK/Bedroom keyword (or a bare integer / studio) is returned, so
     * trailing area figures are never concatenated into the count.
     */
    protected function bedroomCount(string $value): ?int
    {
        $value = trim($value);

        // Studio first: a "Studio" written into the Bedrooms column outranks a
        // stray number next to it, and 0 is how a studio is stored.
        if ($this->mentionsStudio($value)) {
            return 0;
        }

        if (preg_match('/\b(\d{1,2})\s*(?:BR|BD|BHK|BED(?:ROOM)?S?)\b/i', $value, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^\s*(\d{1,2})\s*$/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function bathroomCount(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/\b(\d{1,2})\s*(?:BA|BATH(?:ROOM)?S?)\b/i', $value, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^\s*(\d{1,2})\s*$/', $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function intOrNull(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^(\d+)\s*\+/', $value, $m)) {
            return (int) $m[1];
        }
        $value = preg_replace('/[^0-9]/', '', $value);

        return $value === '' ? null : (int) $value;
    }

    protected function areaNumeric(string $value, bool $bareIsSqft = false): ?int
    {
        if (preg_match('/([\d]+(?:[.,][\d]+)?)\s*(sqm|square\s*m(?:eter|etre)s?|sq\s*m|m²)/i', $value, $m)) {
            $num = (float) str_replace(',', '', $m[1]);

            return (int) round($num * 10.7639);
        }
        if (preg_match('/([\d]+(?:[.,][\d]+)?)\s*(sq\.?\s*ft|square\s*feet?|sq\s*foot|foot|sqft|sqf)/i', $value, $m)) {
            return (int) round((float) str_replace(',', '', $m[1]));
        }
        if (preg_match('/^\s*([\d]+(?:[.,][\d]+)?)\s*$/', $value, $m)) {
            $num = (float) str_replace(',', '', $m[1]);

            return (int) round($bareIsSqft ? $num : $num * 10.7639);
        }

        return null;
    }

    protected function balcony(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['yes', 'y', 'true', '1', '1st', 'have', 'with'], true)) {
            return 'yes';
        }
        if (in_array($value, ['no', 'n', 'false', '0', 'none', 'nil', 'without'], true)) {
            return 'no';
        }

        return null;
    }

    /**
     * Decide whether an "Area (Sqft)"-style header means bare area numbers are
     * already square feet (as Relevate provides them) rather than square metres.
     * Falls back to the historical square-metre interpretation.
     */
    protected function inferAreaUnit(array $columnMap): string
    {
        foreach ($columnMap as $header => $field) {
            if ($field !== 'square_footage') {
                continue;
            }
            $header = strtolower((string) $header);
            if (preg_match('/\bsqft\b|sq\.?\s*ft|square\s*foot|square\s*feet/', $header)) {
                return 'sqft';
            }
            if (preg_match('/\bsqm\b|sq\.?\s*m|square\s*m(?:eter|etre)|m²/', $header)) {
                return 'sqm';
            }
        }

        return 'sqm';
    }

    protected function furnishing(string $value): ?string
    {
        $value = strtolower($value);
        if (str_contains($value, 'semi-furnished') || str_contains($value, 'semi furnished') || str_contains($value, 'semi_furnished')) {
            return 'semi_furnished';
        }
        if (str_contains($value, 'furnished')) {
            return 'furnished';
        }

        return null;
    }

    protected function detectCategory(string $value): ?string
    {
        $value = strtolower($value);
        if (str_contains($value, 'town house') || str_contains($value, 'townhouse')) {
            return 'townhouse';
        }
        if (str_contains($value, 'villa compound')) {
            return 'villa_compound';
        }
        if (str_contains($value, 'penthouse')) {
            return 'penthouse';
        }
        // A whole commercial building ("ICT Commercial's 'Full Building' row,
        // 78k sq ft) is commercial, not a collection of flats. It must win
        // before the generic office/commercial branch below.
        if (str_contains($value, 'full building')) {
            return 'commercial_building';
        }
        if (str_contains($value, 'showroom')) {
            return 'showroom';
        }
        if (str_contains($value, 'storage') || str_contains($value, 'warehouse')) {
            return 'warehouse';
        }
        if (str_contains($value, 'factory')) {
            return 'factory';
        }
        if (str_contains($value, 'office') || str_contains($value, 'commercial')) {
            return str_contains($value, 'retail') || str_contains($value, 'shop') ? 'shop' : 'office';
        }
        if (str_contains($value, 'retail') || str_contains($value, 'shop')) {
            return 'shop';
        }
        if (str_contains($value, 'villa') || str_contains($value, 'plot')) {
            return str_contains($value, 'villa') ? 'villa' : 'land';
        }

        // "Studio" is a size, not a category - it stays an apartment and is
        // carried by bedrooms = 0 (see bedroomCount()/featuresFromRaw()).
        return 'apartment';
    }

    /**
     * Whether a features/free-text value names a maid's room. Sheets spell it
     * every way: "2BHK + MAID", "2 BR + Maids - Sea View", "Flat Maids
     * Room/Terrace", "BALCONY,MAID'S ROOM". "+M" alone is accepted only as a
     * short form of the same token.
     */
    protected function mentionsMaid(string $value): bool
    {
        if (preg_match('/\+\s*m(?:aid|aids|aid\s*room|aids\s*room)?\b/i', $value)) {
            return true;
        }

        return (bool) preg_match("/\bmaid(?:s|'?s)?\s+room\b/i", $value);
    }

    /**
     * Derive bedrooms / area / furnishing / maid's room / a readable one-line
     * summary from a free-text features column such as
     * "4 BR + Maids room 352 Sq Mtr / 3744 Sq Foot".
     *
     * @return array{bedrooms: ?int, is_studio: bool, maids_room: bool, square_footage: ?int, furnishing: ?string, summary: string}
     */
    protected function featuresFromRaw(string $raw): array
    {
        $raw = trim($raw);
        $summary = preg_replace('/\s{2,}/', ' ', $raw) ?: $raw;

        // A PM sheet says "Studio" in the Unit Type column and then quite often
        // puts 1 in Bedrooms. The unit type is the intent, so a studio is read
        // before any bedroom number - and wins over $data['bedrooms'].
        $isStudio = $this->mentionsStudio($raw);

        $bedrooms = null;
        if ($isStudio) {
            $bedrooms = 0;
        } elseif (preg_match('/\b(\d+)\s*(?:BR|BD|BHK|BED(?:ROOM)?S?)/i', $raw, $m)) {
            $bedrooms = (int) $m[1];
        } elseif (preg_match('/\b(\d+)\s*PH(?:[\/+.\s-]|$)/i', $raw, $m)) {
            $bedrooms = (int) $m[1];
        } elseif (preg_match('/\b(\d+)\s*\+/i', $raw, $m)) {
            $bedrooms = (int) $m[1];
        }

        $squareFootage = $this->areaNumeric($raw);
        $furnishing = $this->furnishing($raw);

        return [
            'bedrooms' => $bedrooms,
            'is_studio' => $isStudio,
            'maids_room' => $this->mentionsMaid($raw),
            'square_footage' => $squareFootage,
            'furnishing' => $furnishing,
            'summary' => $summary,
        ];
    }

    protected function buildMarketingTitle(?int $bedrooms, string $category, string $building, bool $maidsRoom = false): string
    {
        $label = Property::CATEGORIES[$category] ?? ucwords(str_replace('_', ' ', $category));

        // Read the size through the same helper the UI uses, so an imported
        // title and a computed one can never disagree ("3BR" vs "3 BR") and a
        // maid's room lands on the title exactly where the label prints it.
        $bed = (new Property)->forceFill(['bedrooms' => $bedrooms, 'maids_room' => $maidsRoom])->sizeLabel();

        return trim(($bed !== '' ? $bed.' ' : '')."{$label} for Rent in {$building}");
    }

    protected function normalizedStatusMap(array $statusMap): array
    {
        $defaults = [
            'vacant' => 'ready_to_list',
            'ready' => 'ready_to_list',
            'available' => 'ready_to_list',
            'availableforviewing' => 'ready_to_list',
            'availableforviewingnow' => 'ready_to_list',
            'openforviewing' => 'ready_to_list',
            'readyforviewing' => 'ready_to_list',
            'upcoming' => 'upcoming',
            'upcomingsoon' => 'upcoming',
            'underoffer' => 'reserved',
            'reserved' => 'reserved',
            'booked' => 'reserved',
            'hold' => 'reserved',
            'undermaintenance' => 'reserved',
            'unavailable' => 'reserved',
            'notavailable' => 'reserved',
            'rented' => 'leased',
            'leased' => 'leased',
            'let' => 'leased',
            'sold' => 'sold',
            'withdrawn' => 'unlisted',
            'offmarket' => 'unlisted',
            'unlisted' => 'unlisted',
        ];

        foreach ($statusMap as $raw => $availability) {
            $defaults[$this->normalizeWord((string) $raw)] = $availability;
        }

        return $defaults;
    }

    protected function resolveAvailability(array $statusMap, string $rawStatus): string
    {
        $norm = $this->normalizeWord($rawStatus);
        if ($norm !== '' && isset($statusMap[$norm])) {
            return $statusMap[$norm];
        }

        $tokens = array_values(array_filter(preg_split('/[^a-z]+/i', strtolower(trim($rawStatus)))));
        $joined = '';
        $fallback = null;
        foreach ($tokens as $token) {
            $joined .= $token;
            if (isset($statusMap[$joined])) {
                $fallback = $statusMap[$joined];
            }
        }

        return $fallback ?? 'ready_to_list';
    }

    protected function isUpcoming(string $rawStatus): bool
    {
        return str_contains(strtolower($rawStatus), 'upcoming')
            || str_contains(strtolower($rawStatus), 'up-coming')
            || str_contains(strtolower($rawStatus), 'up coming')
            || str_contains(strtolower($rawStatus), 'coming');
    }

    protected function normalizeWord(string $value): string
    {
        $value = strtolower(trim($value));

        return (string) preg_replace('/[^a-z]/', '', $value);
    }

    protected function parseFlexibleDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'M d, Y', 'M j, Y', 'd M Y', 'j M Y', 'F d, Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date;
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        return null;
    }

    /**
     * A sheet's date column sometimes says "Available" / "Immediate" instead of
     * a concrete date — the unit is ready now, not a noisy "Keys: …" note.
     */
    protected function isAvailableNow(string $value): bool
    {
        $value = strtolower(trim($value));

        return (bool) preg_match('/^(available|available\s+now|immediate|immediately|now|vacant(\s+now)?|ready(\s+now)?)$/', $value);
    }

    // ── Grid to rows ────────────────────────────────────────────────────────

    protected function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return ',';
        }
        $line = (string) (fgets($handle, 4096) ?: '');
        fclose($handle);

        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
        foreach ($candidates as $delimiter => $_) {
            $candidates[$delimiter] = substr_count($line, $delimiter);
        }
        arsort($candidates);

        return array_key_first($candidates) ?: ',';
    }

    /**
     * Turn a stored delimiter name (or auto-detection) into the single
     * character fgetcsv() needs.
     */
    protected function normalizeDelimiter(?string $delimiter, string $path): string
    {
        return match ($delimiter) {
            'tab' => "\t",
            'comma' => ',',
            'semicolon' => ';',
            'multi_space', null, 'auto' => $this->detectDelimiter($path),
            default => $delimiter,
        };
    }

    /**
     * Drop a leading UTF-8 BOM (Excel "save as CSV" files open with one) so
     * header/cell matching is not offset by an invisible character.
     */
    protected function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?: $value;
    }

    /**
     * Trim a cell the way Excel users mean it. trim() is byte-oriented, so the
     * UTF-8 non-breaking space (\xC2\xA0) needs an explicit charlist entry —
     * ColliersMAP URLs arrive with a leading \xA0 and would otherwise fail the
     * URL check and land as unmappable text.
     */
    protected function cleanCell(string $value): string
    {
        return trim($value, " \t\n\r\0\x0B\xC2\xA0");
    }

    /**
     * Normalize a column header so it is easy to map and match: strip the BOM,
     * trim surrounding whitespace (incl. NBSP) and collapse any inner runs
     * (Excel cells keep embedded newlines, e.g. "Location (please\nclick)").
     * The /u flag makes \s include \xC2\xA0, so a header padded with
     * non-breaking spaces normalizes too.
     */
    protected function normalizeHeaderName(string $value): string
    {
        return preg_replace('/\s+/u', ' ', $this->cleanCell($this->stripBom($value))) ?: '';
    }

    /**
     * Known header-name synonyms (lowercased, whitespace collapsed) so a file
     * with a real header row can be mapped automatically. Sources created for
     * the old paste-text format store a positional "colN" map — when a proper
     * CSV/XLSX is uploaded through them, columns would otherwise misalign.
     *
     * @return array<string, string>
     */
    protected function knownFieldColumns(): array
    {
        return [
            'property name' => 'building',
            'building' => 'building',
            'project' => 'building',
            'tower' => 'building',
            'sub community' => 'building',
            'location' => 'community',
            'community' => 'community',
            'area' => 'community',
            'district' => 'community',
            'unit' => 'unit_no',
            'unit no' => 'unit_no',
            'unit no.' => 'unit_no',
            'unit number' => 'unit_no',
            'room no' => 'unit_no',
            'no. of bedrooms' => 'bedrooms',
            'number of bedrooms' => 'bedrooms',
            'bedrooms' => 'bedrooms',
            'beds' => 'bedrooms',
            'no. of br' => 'bedrooms',
            'no of br' => 'bedrooms',
            'number of br' => 'bedrooms',
            'number of beds' => 'bedrooms',
            'bathrooms' => 'bathrooms',
            'bath' => 'bathrooms',
            'unit features' => 'features',
            'features' => 'features',
            'property type' => 'features',
            'type' => 'features',
            'unit type' => 'features',
            'area (sqft)' => 'square_footage',
            'area (sqm)' => 'square_footage',
            'balcony' => 'balcony',
            'balconies' => 'balcony',
            'view' => 'view',
            'view type' => 'view',
            'rent' => 'rent',
            'rent (aed)' => 'rent',
            'rent price' => 'rent',
            'asking rent' => 'rent',
            'annual rent' => 'rent',
            'annual rent (aed)' => 'rent',
            'monthly rent' => 'rent',
            'monthly rent (aed)' => 'rent',
            'listing price' => 'rent',
            'listing price (aed)' => 'rent',
            'deposit' => 'deposit',
            'security deposit' => 'deposit',
            'admin fee' => 'admin_fee',
            'admin charges' => 'admin_fee',
            'tawtheeq' => 'tawtheeq',
            'tawtheeq fee' => 'tawtheeq',
            'status' => 'status',
            'availability' => 'status',
            'property status' => 'status',
            'parking' => 'parking',
            'parking spaces' => 'parking',
            'parkings' => 'parking',
            'key location' => 'key_date',
            'key date' => 'key_date',
            'vacancy date' => 'key_date',
            'vacating date' => 'key_date',
            'expected vacating date' => 'key_date',
            'expected vacancy date' => 'key_date',
            'expected availability date' => 'available_from',
            'expected available date' => 'available_from',
            'availability date' => 'available_from',
            'available date' => 'available_from',
            'vacant from' => 'key_date',
            'available from' => 'available_from',
            'facilities' => 'amenities',
            'amenities' => 'amenities',
            'furnishing' => 'furnishing',
            'furnished' => 'furnishing',
            'category' => 'property_category',
            'property category' => 'property_category',
            'handover date' => 'handover_date',
            'remarks' => 'remarks',
            'notes' => 'remarks',
            'keys availability' => 'remarks',
            'city' => 'city',
            'map link' => 'map_url',
            'maps link' => 'map_url',
            'location link' => 'map_url',
            'location url' => 'map_url',
            'map url' => 'map_url',
            'map' => 'map_url',
            'gps map' => 'map_url',
            'gps location' => 'map_url',
            'gps link' => 'map_url',
            'google maps link' => 'map_url',
        ];
    }

    /**
     * Build a column map from a parsed header row using the known synonyms.
     * URL-valued columns (Reelam's map/location link) are caught first and
     * pinned to map_url regardless of their header text.
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    protected function detectHeaderMap(array $header, array $rows = []): array
    {
        $known = $this->knownFieldColumns();
        $map = [];

        foreach ($header as $name) {
            if ($this->isUrlColumn($name, $rows)) {
                $map[$name] = 'map_url';
            }
        }

        foreach ($header as $name) {
            $key = strtolower((string) $name);
            if ($key !== '' && isset($known[$key]) && ! isset($map[$name])) {
                $map[$name] = $known[$key];
            }
        }

        // Second pass for near-miss headers (e.g. Relevate's "Unit Type / Balcony"
        // or "Expected Move-In Date") — only unmatched columns get a fuzzy hit.
        foreach ($header as $name) {
            $key = strtolower((string) $name);
            if ($key === '' || isset($map[$name])) {
                continue;
            }
            foreach ($this->fuzzyFieldColumns() as $pattern => $field) {
                if (preg_match($pattern, $key)) {
                    $map[$name] = $field;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Public wrapper for detectHeaderMap() so the review screen can show the
     * mapping a fresh sheet will get before the import actually runs (the run
     * itself rebuilds the same map when the saved one shares no columns).
     *
     * @param  array<int, string>  $header
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    public function detectColumnMap(array $header, array $rows = []): array
    {
        return $this->detectHeaderMap($header, $rows);
    }

    /**
     * Whether a column's values are dominated by http(s) links — the reliable
     * way to recognise a map/location column whose header text varies per
     * source. Looks at up to the first 25 non-empty cells; a link wins when
     * it is the only hit, so mis-detection of a mixed text column is avoided.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function isUrlColumn(string $name, array $rows): bool
    {
        $matches = 0;
        $total = 0;
        foreach ($rows as $row) {
            $value = $this->cleanCell((string) ($row[$name] ?? ''));
            if ($value === '') {
                continue;
            }
            $total++;
            if ($this->looksLikeUrl($value)) {
                $matches++;
            }
            if ($total >= 25) {
                break;
            }
        }

        return $total > 0 && $matches === $total;
    }

    protected function looksLikeUrl(string $value): bool
    {
        $value = $this->cleanCell($value);

        return preg_match('#^https?://|^www\.#i', $value) === 1
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Regex fallbacks used when a header is not one of the known exact synonyms.
     * Order matters: "Unit Type / Balcony" must map to features, not balcony.
     *
     * @return array<string, string>
     */
    protected function fuzzyFieldColumns(): array
    {
        return [
            '/type.*balcony/' => 'features',
            '/unit\s*type/' => 'features',
            '/property\s*type/' => 'features',
            '/available\s*(?:from|date)/' => 'available_from',
            '/availability\s*date/' => 'available_from',
            '/expected\s*(?:vacan(?:t|cy)|vacating|availability|available|move[\s-]?in)/' => 'key_date',
            '/listing\s*price/' => 'rent',
            '/asking\s*(?:rent|price)/' => 'rent',
            '/annual\s*rent|monthly\s*rent/' => 'rent',
            '/no\.?\s*of\s*brs?\b|number\s*of\s*brs?\b/' => 'bedrooms',
            '/keys\s*availability/' => 'remarks',
            '/security\s*deposit/' => 'deposit',
            '/square\s*(?:foot|feet|meter)|sq\.?\s*(?:ft|m)/' => 'square_footage',
            '/bedrooms?/' => 'bedrooms',
            '/balcony/' => 'balcony',
            '/^view(?:ing|s)?$/' => 'view',
            '/google\s*maps|maps?\s*(?:link|url)|location\s*(?:link|url|map)|map\s*(?:link|url)/' => 'map_url',
        ];
    }

    /**
     * First grid row that reads as a header: at least three non-empty cells and
     * two or more recognised column names (exact synonyms or fuzzy patterns).
     * Returns null for a headerless grid so the legacy positional "colN"
     * behaviour around it is preserved untouched.
     *
     * @param  array<int, array<int|string, mixed>>  $grid
     */
    protected function findHeaderRowIndex(array $grid): ?int
    {
        foreach ($grid as $index => $cells) {
            $nonEmpty = count(array_filter($cells, fn ($cell) => $this->cleanCell((string) $cell) !== ''));
            if ($nonEmpty < 3) {
                continue;
            }
            if ($this->countRecognizedHeaderNames($cells) < 2) {
                continue;
            }

            return $index;
        }

        return null;
    }

    /**
     * How many cells in a row match a known or fuzzy column name. The
     * amenities sub-header Colliers prints ("Swimming Pool / Gym / Parking")
     * and the "STUDIOS"/"VILLAS" section banners score zero, so they can never
     * be mistaken for a header.
     *
     * @param  array<int, mixed>  $cells
     */
    protected function countRecognizedHeaderNames(array $cells): int
    {
        $known = $this->knownFieldColumns();
        $fuzzy = $this->fuzzyFieldColumns();
        $hits = 0;

        foreach (array_slice($cells, 0, 20) as $cell) {
            $key = strtolower($this->normalizeHeaderName((string) $cell));
            if ($key === '') {
                continue;
            }
            if (isset($known[$key])) {
                $hits++;

                continue;
            }
            foreach ($fuzzy as $pattern => $field) {
                if (preg_match($pattern, $key)) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits;
    }

    protected function gridToRows(array $grid, array $parseOptions): array
    {
        $hasHeader = (bool) ($parseOptions['has_header'] ?? false);
        $inherit = (array) ($parseOptions['inherit_columns'] ?? []);

        // AY-style sheets print a line carrying the location link before the
        // table ("https://maps.app.goo.gl/..."). Single-cell URL rows lead the
        // grid and would otherwise shift the columns, so lift them out and
        // return them as a per-file context URL that applies to every row.
        $contextUrl = $this->extractContextUrl($grid);
        $grid = array_values($grid);

        // Sources created for the old paste-text format store "has_header: false".
        // If the file actually starts with recognizable named columns, promote it
        // to a header row so columns line up instead of shifting data around.
        // The scan covers the ENTIRE grid, not just row 0: Colliers-style block
        // workbooks print banner/title rows ("AVAILABILITY LIST…", "2 BEDROOMS")
        // above the column names, and the real header must be found below them.
        $headerIndex = $this->findHeaderRowIndex($grid);
        if (! $hasHeader && $headerIndex !== null) {
            $hasHeader = true;
        }

        $max = 0;
        foreach ($grid as $row) {
            $max = max($max, count($row));
        }

        if ($hasHeader) {
            // Drop any banner rows above the located header so the table starts
            // exactly at the column names. Falls back to row 0 (the historic
            // behaviour) when no row qualified as a header.
            $grid = array_slice($grid, $headerIndex ?? 0);
            $headerRow = (array) (array_shift($grid) ?? []);
            // The header row may start at a non-zero column (Colliers prints a
            // blank column A), and array_slice() would re-index it back to 0 —
            // silently dropping that offset while the data rows below keep their
            // true column keys, shifting every value one column left of its name.
            // Capture the offset here and read the header AND the rows through it.
            $offset = $headerRow !== [] ? min(array_keys($headerRow)) : 0;
            // Span the header by its true column keys, not by count(): trailing
            // gaps (unwritten/empty cells) would otherwise truncate the last
            // columns before they ever got a name.
            $lastIndex = $headerRow !== [] ? max(array_keys($headerRow)) : $offset;
            foreach ($grid as $cells) {
                if ($cells !== []) {
                    $lastIndex = max($lastIndex, max(array_keys($cells)));
                }
            }
            $header = [];
            for ($i = $offset; $i <= $lastIndex; $i++) {
                $name = $this->normalizeHeaderName((string) ($headerRow[$i] ?? ''));
                $header[] = $name !== '' ? $name : 'col'.($i - $offset);
            }
        } else {
            $offset = 0;
            $header = [];
            for ($i = 0; $i < $max; $i++) {
                $header[] = 'col'.$i;
            }
        }

        $rows = [];
        foreach ($grid as $cells) {
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = $this->cleanCell((string) ($cells[$i + $offset] ?? ''));
            }
            if (empty(array_filter($row))) {
                continue;
            }
            $rows[] = $row;
        }

        if ($inherit) {
            $carry = array_fill_keys($inherit, '');
            foreach ($rows as &$row) {
                foreach ($inherit as $col) {
                    if (isset($row[$col]) && $row[$col] !== '') {
                        $carry[$col] = $row[$col];
                    } elseif ($carry[$col] !== '') {
                        $row[$col] = $carry[$col];
                    }
                }
            }
            unset($row);
        }

        return ['header' => $header, 'rows' => $rows, 'context_url' => $contextUrl];
    }

    /**
     * Shift any leading single-cell URL rows out of the grid and return the
     * first one found (the AY pre-table link line). Non-URL single-cell rows
     * are left in place so the header/data shapes are untouched.
     *
     * @param  array<int, array<int|string, mixed>>  $grid
     */
    protected function extractContextUrl(array &$grid): ?string
    {
        foreach (array_keys($grid) as $key) {
            $row = $grid[$key];
            $values = array_filter($row, fn ($cell) => trim((string) $cell) !== '');
            if (count($values) !== 1) {
                break;
            }
            $value = trim((string) (reset($values) ?: ''));
            if (! $this->looksLikeUrl($value)) {
                break;
            }
            unset($grid[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
