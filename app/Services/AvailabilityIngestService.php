<?php

namespace App\Services;

use App\Models\AvailabilitySource;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                'tab' => str_getcsv($line, "\t"),
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
        if (!class_exists(ZipArchive::class)) {
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

        if (!$grid) {
            throw new RuntimeException('Excel file contains no data.');
        }

        return $this->gridToRows($grid, $parseOptions);
    }

    public function parseCsv(string $path, array $parseOptions): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException('Unable to read file.');
        }

        $delimiter = $parseOptions['delimiter'] ?? $this->detectDelimiter($path);
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
     * Apply a saved column map + normalizers and upsert into the property inventory,
     * reconciling units that disappeared from the sheet.
     *
     * @return array<string, mixed>
     */
    public function ingest(AvailabilitySource $source, array $rows, int $tenantId, ?int $userId = null): array
    {
        $columnMap = $source->column_map ?: [];
        $parseOptions = $source->parse_options ?: [];
        $statusMap = $this->normalizedStatusMap($source->status_map ?: []);
        $city = $source->default_city ?: 'Abu Dhabi';

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $total = 0;
        $seenRefs = [];
        $skippedExamples = [];

        $forbiddenStatuses = ['leased', 'sold'];

        DB::transaction(function () use (
            $rows, $columnMap, $statusMap, $city, $source, $tenantId, $userId, $forbiddenStatuses,
            &$created, &$updated, &$skipped, &$total, &$seenRefs, &$skippedExamples
        ) {
            foreach ($rows as $row) {
                $total++;
                $data = [];
                $featuresRaw = '';
                $amenitiesRaw = '';
                $remarksRaw = '';

                foreach ($columnMap as $header => $field) {
                    if (isset($row[$header])) {
                        $raw = $row[$header];
                        if ($field === 'features') {
                            $featuresRaw = $raw;
                            continue;
                        }
                        if ($field === 'amenities') {
                            $amenitiesRaw = $raw;
                            continue;
                        }
                        if ($field === 'remarks') {
                            $remarksRaw = $raw;
                            continue;
                        }
                        $data[$field] = $this->normalizeField($field, $raw);
                    }
                }

                $unitNo = trim((string) ($data['unit_no'] ?? ''));
                $looksLikeUnit = $unitNo !== '' && (
                    preg_match('/[0-9]/', $unitNo)
                    || preg_match('/^(villa|plot|office|retail|shop|showroom|unit|penthouse)/i', $unitNo)
                );
                if (!$looksLikeUnit) {
                    $skipped++;
                    if (count($skippedExamples) < 5) {
                        $skippedExamples[] = implode(' | ', array_values(array_filter(array_map(
                            fn ($row) => is_scalar($row) ? trim((string) $row) : '',
                            $row
                        ))));
                    }
                    continue;
                }
                $building = trim((string) ($data['building'] ?? $data['sub_community'] ?? ''));
                if ($building === '') {
                    $building = $source->default_building ?: '';
                }
                if ($building === '') {
                    $building = 'Other';
                }

                $features = $this->featuresFromRaw($featuresRaw);
                $category = $data['property_category'] ?? ($source->default_category ?: $this->detectCategory($featuresRaw . ' ' . $building));
                $category = $category ?: 'apartment';

                $rawStatus = (string) ($data['status'] ?? $data['source_status'] ?? '');
                $availability = $statusMap[$this->normalizeWord($rawStatus)] ?? 'ready_to_list';

                $handover = null;
                $keyNotes = [];
                foreach (['key_date', 'handover_date'] as $fd) {
                    if (!empty($data[$fd])) {
                        $parsed = $this->parseFlexibleDate($data[$fd]);
                        if ($parsed) {
                            $handover = $parsed;
                        } else {
                            $keyNotes[] = $data[$fd];
                        }
                    }
                }

                $bedrooms = $data['bedrooms'] ?? $features['bedrooms'];
                $squareFootage = $data['square_footage'] ?? $features['square_footage'];
                $furnishing = $data['furnishing'] ?? $features['furnishing'];

                $parking = isset($data['parking']) && $data['parking'] !== '' ? $data['parking'] : null;

                $remarkLine = trim((string) $remarksRaw);
                $commissionNote = preg_match('/commission/i', $remarkLine) ? $remarkLine : null;
                $amenityLine = trim((string) $amenitiesRaw);

                $deposit = $data['deposit'] ?? $data['deposit_amount'] ?? null;
                $adminFee = $data['admin_fee'] ?? null;
                $rentPrice = $data['rent'] ?? $data['rent_price'] ?? null;

                $notesParts = ["Source: {$source->name} availability sheet."];
                if ($rawStatus !== '') {
                    $notesParts[] = "Status on sheet: {$rawStatus}.";
                }
                if ($handover) {
                    $notesParts[] = "Vacant by: {$handover->format('d.m.Y')}.";
                }
                foreach ($keyNotes as $keyNote) {
                    $notesParts[] = "Keys: {$keyNote}.";
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
                if ($remarkLine !== '') {
                    $descriptionParts[] = $remarkLine;
                }
                if ($deposit !== null || $adminFee !== null) {
                    $bits = [];
                    if ($deposit !== null) {
                        $bits[] = "Deposit: AED " . number_format($deposit);
                    }
                    if ($adminFee !== null) {
                        $bits[] = "Admin fee: AED " . number_format($adminFee);
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
                    'community' => $data['community'] ?? null,
                    'sub_community' => $building,
                    'city' => $city,
                    'unit_no' => $unitNo,
                    'bedrooms' => $bedrooms !== null ? (int) $bedrooms : null,
                    'square_footage' => $squareFootage,
                    'furnishing' => $furnishing,
                    'parking' => $parking,
                    'rent_price' => $rentPrice,
                    'deposit_amount' => $deposit,
                    'admin_fee' => $adminFee,
                    'rent_period' => 'yearly',
                    'handover_date' => $handover ? $handover->toDateString() : null,
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

                if ($existing) {
                    // Never auto-mark a unit leased/sold downwards while the sheet
                    // still lists it; only refresh the factual fields.
                    $record['availability'] = in_array($availability, $forbiddenStatuses) ? $existing->availability : $availability;
                    $existing->update($record);
                    $updated++;
                } else {
                    $record['availability'] = in_array($availability, $forbiddenStatuses) ? 'unlisted' : $availability;
                    Property::create($record);
                    $created++;
                }
                $seenRefs["{$building}|{$unitNo}"] = true;
            }
        });

        $missing = 0;
        if ($created > 0 || $updated > 0 || $total > 0) {
            $linked = Property::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('availability_source_id', $source->id)
                ->get();

            foreach ($linked as $property) {
                $key = ($property->sub_community ?? '') . '|' . ($property->source_unit_ref ?? '');
                if (!isset($seenRefs[$key]) && $property->availability_synced_at !== null) {
                    $append = "Removed from {$source->name} availability sheet on " . now()->format('d.m.Y') . '.';
                    $property->update([
                        'availability' => 'unlisted',
                        'notes' => trim(($property->notes ?? '') . ' ' . $append),
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
            'skipped' => $skipped,
            'skipped_examples' => $skippedExamples,
        ];
    }

    // ── Normalizers ─────────────────────────────────────────────────────────

    protected function normalizeField(string $field, $raw): mixed
    {
        $value = trim((string) $raw);

        return match ($field) {
            'unit_no', 'building', 'floor_no', 'plot_no', 'community',
            'rera_permit_no', 'title_deed_no', 'owner_name' => $value,
            'rent_price', 'rent', 'deposit', 'deposit_amount', 'admin_fee',
            'service_charge', 'list_price' => $this->moneyNumeric($value),
            'parking' => $this->parkingCount($value),
            'bedrooms', 'bathrooms' => $this->intOrNull($value),
            'square_footage' => $this->areaNumeric($value),
            'handover_date', 'key_date' => $value,
            'source_status', 'status' => $value,
            'furnishing' => $this->furnishing($value),
            'property_category' => $this->detectCategory($value),
            default => $value,
        };
    }

    protected function moneyNumeric(string $value): ?float
    {
        $value = preg_replace('/[^0-9.]/', '', $value);
        $value = rtrim((string) $value, '.');
        return $value === '' || $value === '.' ? null : (float) $value;
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

    protected function intOrNull(string $value): ?int
    {
        $value = preg_replace('/[^0-9]/', '', $value);
        return $value === '' ? null : (int) $value;
    }

    protected function areaNumeric(string $value): ?int
    {
        if (preg_match('/([\d]+(?:[.,][\d]+)?)\s*(sqm|square\s*m(?:eter|etre)s?|sq\s*m|m²)/i', $value, $m)) {
            $num = (float) str_replace(',', '', $m[1]);
            return (int) round($num * 10.7639);
        }
        if (preg_match('/([\d]+(?:[.,][\d]+)?)\s*(sq\.?\s*ft|square\s*feet?|sq\s*foot|foot|sqft|sqf)/i', $value, $m)) {
            return (int) round((float) str_replace(',', '', $m[1]));
        }
        return null;
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
        if (preg_match('/\b(\d+)\s*(?:BR|BD|bed(?:room)?s?)\b/i', $raw, $m)) {
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
            default => $bedrooms . 'BR ',
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
            'upcoming' => 'ready_to_list',
            'upcomingsoon' => 'ready_to_list',
            'underoffer' => 'reserved',
            'reserved' => 'reserved',
            'rented' => 'leased',
            'leased' => 'leased',
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
        foreach (['d.m.Y', 'd/m/Y', 'd-m-Y', 'Y-m-d', 'd M Y'] as $format) {
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

    // ── Grid to rows ────────────────────────────────────────────────────────

    protected function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
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

    protected function gridToRows(array $grid, array $parseOptions): array
    {
        $hasHeader = (bool) ($parseOptions['has_header'] ?? false);
        $inherit = (array) ($parseOptions['inherit_columns'] ?? []);
        $max = 0;
        foreach ($grid as $row) {
            $max = max($max, count($row));
        }

        if ($hasHeader) {
            $header = array_slice(array_shift($grid) ?? [], 0, $max);
            $header = array_values(array_slice(array_pad($header, $max, ''), 0, $max));
        } else {
            $header = [];
            for ($i = 0; $i < $max; $i++) {
                $header[] = 'col' . $i;
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