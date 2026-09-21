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
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if (count($cells) === 1 && $cells[0] === '') {
                continue;
            }
            $grid[] = $cells;
        }

        return $this->gridToRows($grid, $parseOptions);
    }

    /**
     * Minimal dependency-free XLSX reader (shared strings + first worksheet).
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

        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $ss = simplexml_load_string($ssXml);
            if ($ss !== false) {
                foreach ($ss->xpath('//*[local-name()="si"]') as $si) {
                    $text = '';
                    foreach ($si->xpath('.//*[local-name()="t"]') as $t) {
                        $text .= (string) $t;
                    }
                    $shared[] = $text;
                }
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        if ($zip->locateName($sheetPath) === false) {
            $sheetPath = 'xl/worksheets/sheet.xml';
        }
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Excel file contains no worksheet.');
        }

        $sheet = simplexml_load_string($sheetXml);
        if ($sheet === false) {
            throw new RuntimeException('Excel file is unreadable.');
        }

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
                $cells[$idx] = trim($value);
            }
            if ($cells) {
                $grid[] = $cells;
            }
        }

        if (! $grid) {
            throw new RuntimeException('Excel file contains no data.');
        }

        return $this->gridToRows($grid, $parseOptions);
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
            $cells = array_map(fn ($c) => trim((string) $c), $line);
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
     * published "Show" units are imported) and falls back to CSV/TSV text for
     * other sources that publish their list as a file.
     *
     * @return array{header: array<int, string>, rows: array<int, array<string, string>>}
     */
    public function fetchUrl(string $url): array
    {
        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Could not fetch availability URL ({$url}): HTTP {$response->status()}.");
        }

        $body = $response->body();
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
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
    public function ingest(AvailabilitySource $source, array $rows, int $tenantId, ?int $userId = null, ?int $runId = null, bool $guardReconciliation = true): array
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
                $detected = $this->detectHeaderMap($rowKeys);
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
            $areaUnit, &$created, &$updated, &$skipped, &$total, &$conflicts, &$seenRefs, &$skippedExamples,
            &$lastBuilding, &$lastCommunity
        ) {
            foreach ($rows as $row) {
                $total++;
                $data = [];
                $featuresRaw = '';
                $amenitiesRaw = '';
                $remarksRaw = '';
                $rentRaw = '';

                foreach ($columnMap as $header => $field) {
                    if (isset($row[$header])) {
                        $raw = trim((string) $row[$header]);
                        if ($raw === '') {
                            continue;
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
                    || preg_match('/^(villa|plot|office|retail|shop|showroom|unit|penthouse)/i', $unitNo)
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
                $category = $data['property_category'] ?? ($source->default_category ?: $this->detectCategory($featuresRaw.' '.$building));
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

                $bedrooms = $data['bedrooms'] ?? $features['bedrooms'];
                $squareFootage = $data['square_footage'] ?? $features['square_footage'];
                $furnishing = $data['furnishing'] ?? $features['furnishing'];

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
                $tawtheeqFee = $data['tawtheeq'] ?? $data['tawtheeq_fee'] ?? $source->default_tawtheeq_fee ?? null;

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
                if ($deposit !== null || $adminFee !== null || $tawtheeqFee !== null) {
                    $bits = [];
                    if ($deposit !== null) {
                        $bits[] = 'Deposit: AED '.number_format($deposit);
                    }
                    if ($adminFee !== null) {
                        $bits[] = 'Admin fee: AED '.number_format($adminFee);
                    }
                    if ($tawtheeqFee !== null) {
                        $bits[] = 'Tawtheeq: AED '.number_format($tawtheeqFee);
                    }
                    $descriptionParts[] = implode(' | ', $bits);
                }
                $marketingDescription = implode(' ', array_filter($descriptionParts));

                $marketingTitle = $this->buildMarketingTitle($bedrooms, $category, $building);

                $address = trim(implode(' ', array_filter([
                    $unitNo,
                    $building,
                    $data['community'] ?? null,
                    $city,
                ])));

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
                    'square_footage' => $squareFootage,
                    'furnishing' => $furnishing,
                    'parking' => $parking,
                    'balcony' => $data['balcony'] ?? null,
                    'view' => $data['view'] ?? null,
                    'rent_price' => $rentPrice,
                    'deposit_amount' => $deposit,
                    'admin_fee' => $adminFee,
                    'tawtheeq_fee' => $tawtheeqFee,
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
            'rent_price', 'rent', 'deposit', 'deposit_amount', 'admin_fee', 'tawtheeq', 'tawtheeq_fee',
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

        if (preg_match('/\b(\d{1,2})\s*(?:BR|BD|BHK|BED(?:ROOM)?S?)\b/i', $value, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\bstudio\b/i', $value)) {
            return 0;
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
        if (str_contains($value, 'villa') || str_contains($value, 'plot')) {
            return str_contains($value, 'villa') ? 'villa' : 'land';
        }
        if (str_contains($value, 'penthouse')) {
            return 'penthouse';
        }
        if (str_contains($value, 'office') || str_contains($value, 'commercial')) {
            return str_contains($value, 'retail') || str_contains($value, 'shop') ? 'shop' : 'office';
        }
        if (str_contains($value, 'retail') || str_contains($value, 'shop')) {
            return 'shop';
        }

        return 'apartment';
    }

    /**
     * Derive bedrooms / area / furnishing / a readable one-line summary from a
     * free-text features column such as "4 BR + Maids room 352 Sq Mtr / 3744 Sq Foot".
     *
     * @return array{bedrooms: ?int, square_footage: ?int, furnishing: ?string, summary: string}
     */
    protected function featuresFromRaw(string $raw): array
    {
        $raw = trim($raw);
        $summary = preg_replace('/\s{2,}/', ' ', $raw) ?: $raw;

        $bedrooms = null;
        if (preg_match('/\b(\d+)\s*(?:BR|BD|BHK|BED(?:ROOM)?S?)/i', $raw, $m)) {
            $bedrooms = (int) $m[1];
        } elseif (preg_match('/\b(\d+)\s*PH(?:[\/+.\s-]|$)/i', $raw, $m)) {
            $bedrooms = (int) $m[1];
        } elseif (preg_match('/\b(\d+)\s*\+/i', $raw, $m)) {
            $bedrooms = (int) $m[1];
        } elseif (preg_match('/\bstudio\b/i', $raw)) {
            $bedrooms = 0;
        }

        $squareFootage = $this->areaNumeric($raw);
        $furnishing = $this->furnishing($raw);

        return [
            'bedrooms' => $bedrooms,
            'square_footage' => $squareFootage,
            'furnishing' => $furnishing,
            'summary' => $summary,
        ];
    }

    protected function buildMarketingTitle(?int $bedrooms, string $category, string $building): string
    {
        $bed = match (true) {
            $bedrooms === null => '',
            $bedrooms === 0 => 'Studio ',
            $bedrooms === 1 => '1BR ',
            default => $bedrooms.'BR ',
        };
        $label = Property::CATEGORIES[$category] ?? ucwords(str_replace('_', ' ', $category));

        return trim("{$bed}{$label} for Rent in {$building}");
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
     * Normalize a column header so it is easy to map and match: strip the BOM,
     * trim surrounding whitespace and collapse any inner runs (Excel cells keep
     * embedded newlines, e.g. "Location (please\nclick)").
     */
    protected function normalizeHeaderName(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($this->stripBom($value))) ?: '';
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
            'listing price' => 'rent',
            'listing price (aed)' => 'rent',
            'deposit' => 'deposit',
            'security deposit' => 'deposit',
            'admin fee' => 'admin_fee',
            'admin charges' => 'admin_fee',
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
            'remarks' => 'remarks',
            'notes' => 'remarks',
            'city' => 'city',
        ];
    }

    /**
     * Build a column map from a parsed header row using the known synonyms.
     *
     * @param  array<int, string>  $header
     * @return array<string, string>
     */
    protected function detectHeaderMap(array $header): array
    {
        $known = $this->knownFieldColumns();
        $map = [];
        foreach ($header as $name) {
            $key = strtolower((string) $name);
            if ($key !== '' && isset($known[$key])) {
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
            '/security\s*deposit/' => 'deposit',
            '/square\s*(?:foot|feet|meter)|sq\.?\s*(?:ft|m)/' => 'square_footage',
            '/bedrooms?/' => 'bedrooms',
            '/balcony/' => 'balcony',
            '/^view(?:ing|s)?$/' => 'view',
        ];
    }

    /**
     * Decide whether the first grid row is a header row rather than data (used
     * when a source's saved parse_options say "no header" but the uploaded
     * file actually starts with named columns, e.g. an Excel-exported CSV).
     *
     * @param  array<int, mixed>  $cells
     */
    protected function looksLikeHeaderRow(array $cells): bool
    {
        $known = $this->knownFieldColumns();
        $hits = 0;
        foreach (array_slice($cells, 0, 20) as $cell) {
            $name = $this->normalizeHeaderName((string) $cell);
            if ($name !== '' && isset($known[strtolower($name)])) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    protected function gridToRows(array $grid, array $parseOptions): array
    {
        $hasHeader = (bool) ($parseOptions['has_header'] ?? false);
        $inherit = (array) ($parseOptions['inherit_columns'] ?? []);

        // Sources created for the old paste-text format store "has_header: false".
        // If the file actually starts with recognizable named columns, promote it
        // to a header row so columns line up instead of shifting data around.
        if (! $hasHeader && $grid !== [] && $this->looksLikeHeaderRow($grid[0])) {
            $hasHeader = true;
        }

        $max = 0;
        foreach ($grid as $row) {
            $max = max($max, count($row));
        }

        if ($hasHeader) {
            $headerRow = array_slice((array) (array_shift($grid) ?? []), 0, $max);
            $header = [];
            for ($i = 0; $i < $max; $i++) {
                $name = $this->normalizeHeaderName((string) ($headerRow[$i] ?? ''));
                $header[] = $name !== '' ? $name : 'col'.$i;
            }
        } else {
            $header = [];
            for ($i = 0; $i < $max; $i++) {
                $header[] = 'col'.$i;
            }
        }

        $rows = [];
        foreach ($grid as $cells) {
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = trim((string) ($cells[$i] ?? ''));
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

        return ['header' => $header, 'rows' => $rows];
    }
}
