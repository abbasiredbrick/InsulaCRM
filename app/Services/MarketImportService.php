<?php

namespace App\Services;

use App\Models\MarketContact;
use App\Models\MarketImport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MarketImportService
{
    /** @var array<string, string[]> Canonical field => header aliases (normalized). */
    protected const FIELD_ALIASES = [
        'first_name' => ['name', 'firstname', 'first', 'contactname', 'contact person', 'contactname person', 'owner', 'landlord name', 'investor name', 'client name'],
        'last_name' => ['lastname', 'last', 'surname', 'family name'],
        'phone' => ['phone', 'mobile', 'mobilenumber', 'phone number', 'phonenumber', 'telephone', 'tel', 'contact number', 'whatsapp', 'whatsapp number', 'mobile no'],
        'email' => ['email', 'emailaddress', 'email address', 'mail', 'e mail'],
        'company' => ['company', 'organisation', 'organization', 'business', 'company name', 'business name'],
        'type' => ['type', 'record type', 'list type', 'kind', 'role'],
        'unit_no' => ['unitno', 'unit no', 'unit', 'unit number', 'flat no', 'flatno', 'apartment no', 'apartment number', 'room no'],
        'building' => ['building', 'building name', 'buildingname', 'tower', 'tower name', 'complex', 'building no', 'buildingno'],
        'address' => ['address', 'unit address', 'full address', 'property address', 'street', 'location'],
        'community' => ['community', 'area', 'district', 'neighbourhood', 'neighborhood', 'region', 'sub community', 'subcommunity'],
        'property_category' => ['category', 'property category', 'property type', 'unit type', 'project type', 'apartment type'],
        'bedrooms' => ['bedrooms', 'bedrooms', 'bed', 'beds', 'br', 'bedroom', 'bhk', 'no of bedrooms'],
        'bathrooms' => ['bathrooms', 'bath', 'baths', 'bathroom', 'washroom', 'washrooms'],
        'rent_price' => ['rent', 'rent price', 'rentprice', 'asking rent', 'annual rent', 'yearly rent', 'monthly rent', 'list price', 'advertised rent', 'price'],
        'unit_status' => ['status', 'availability', 'unit status', 'listing status'],
        'budget' => ['budget', 'max budget', 'maxbudget', 'investment budget', 'budget range', 'price range'],
        'preferred_type' => ['preferred type', 'preferred property', 'property interest', 'looking for', 'interest', 'preferred unit type'],
        'requirements' => ['requirements', 'requirement', 'notes', 'remarks', 'remark', 'comment', 'comments', 'message', 'details', 'note'],
        'ownername' => ['owner name', 'owner', 'landlord', 'contact'],
    ];

    /**
     * Read the uploaded file into a table grid and auto-map columns.
     *
     * @return array<int, array<string, mixed>> Canonicalized rows.
     */
    public function parseFile(string $path, string $format, array $parseOptions = []): array
    {
        $parser = new AvailabilityIngestService;
        $table = $parser->parseFile($path, $format, array_merge(['has_header' => true], $parseOptions));

        $headers = $table['header'];
        $columnMap = $this->mapHeaders($headers);

        $rows = [];
        foreach ($table['rows'] as $raw) {
            $rows[] = $this->canonicalizeRow($raw, $columnMap);
        }

        return $rows;
    }

    /**
     * Persist an uploaded cold-call list.
     *
     * @return array{import: MarketImport, imported: int, skipped: int}|array{error: string}
     */
    public function import(string $path, string $format, string $name, string $importType, int $tenantId, ?int $userId = null): array
    {
        try {
            $rows = $this->parseFile($path, $format);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $imported = 0;
        $skipped = 0;

        $import = DB::transaction(function () use ($rows, $path, $format, $name, $importType, $tenantId, $userId, &$imported, &$skipped) {
            $import = MarketImport::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $name,
                'type' => $importType,
                'filename' => basename($path),
                'format' => $format,
                'status' => 'completed',
            ]);

            foreach ($rows as $row) {
                $type = ($row['type'] ?? '') !== ''
                    ? $this->resolveType($row['type'])
                    : ($importType === 'investors' ? 'investor' : 'landlord');

                $hasAnything = trim((string) ($row['first_name'] ?? '')) !== ''
                    || trim((string) ($row['phone'] ?? '')) !== ''
                    || trim((string) ($row['email'] ?? '')) !== '';

                if (! $hasAnything) {
                    $skipped++;

                    continue;
                }

                MarketContact::create([
                    'tenant_id' => $tenantId,
                    'import_id' => $import->id,
                    'type' => $type,
                    'first_name' => $row['first_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'phone' => $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                    'company' => $row['company'] ?? null,
                    'unit_no' => $row['unit_no'] ?? null,
                    'building' => $row['building'] ?? null,
                    'address' => $row['address'] ?? null,
                    'community' => $row['community'] ?? null,
                    'property_category' => $this->normalizeCategory($row['property_category'] ?? null),
                    'bedrooms' => $this->intOrNull($row['bedrooms'] ?? null),
                    'bathrooms' => $this->intOrNull($row['bathrooms'] ?? null),
                    'rent_price' => $this->moneyNumeric($row['rent_price'] ?? null),
                    'unit_status' => $row['unit_status'] ?? null,
                    'budget' => $this->moneyNumeric($row['budget'] ?? null),
                    'preferred_type' => $row['preferred_type'] ?? null,
                    'requirements' => $row['requirements'] ?? null,
                    'status' => 'pending',
                    'notes' => $row['requirements'] ?? null,
                ]);

                $imported++;
            }

            $import->update([
                'total_rows' => $imported + $skipped,
                'imported_rows' => $imported,
                'skipped_rows' => $skipped,
            ]);

            return $import;
        });

        return [
            'import' => $import,
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    // ── Column mapping ────────────────────────────────────────────

    protected function mapHeaders(array $headers): array
    {
        $map = [];

        foreach ($headers as $key => $header) {
            $normalized = $this->normalizeHeader((string) $header);
            if ($normalized === '') {
                continue;
            }

            foreach (self::FIELD_ALIASES as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if ($normalized === $this->normalizeHeader($alias)) {
                        $map[(string) $header] = $field;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    protected function canonicalizeRow(array $raw, array $columnMap): array
    {
        $row = [];

        foreach ($columnMap as $index => $field) {
            $value = trim((string) ($raw[$index] ?? ''));
            if ($value === '') {
                continue;
            }

            // Lands a full "owner name" / "landlord" column.
            if ($field === 'ownername') {
                $parts = preg_split('/\s+/', $value, 2) ?: [];
                $row['first_name'] = $parts[0] ?? null;
                if (! empty($parts[1])) {
                    $row['last_name'] = $parts[1];
                }

                continue;
            }

            // A single "name" column carries first and last; split it when the
            // file offers no separate last-name column.
            if ($field === 'first_name' && ! isset($columnMap['last_name'])) {
                $parts = preg_split('/\s+/', $value, 2) ?: [];
                $row['first_name'] = $parts[0] ?? $value;
                $row['last_name'] = $parts[1] ?? null;

                continue;
            }

            $row[$field] = $value;
        }

        return $row;
    }

    protected function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));

        return (string) preg_replace('/[^a-z0-9]+/', '', $header);
    }

    protected function resolveType(string $raw): string
    {
        $raw = strtolower(trim($raw));

        if (in_array($raw, ['buyer', 'investor', 'end buyer', 'endbuyer', 'investors', 'buyers'], true)) {
            return 'investor';
        }

        return 'landlord';
    }

    protected function normalizeCategory(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $value = strtolower($raw);

        return match (true) {
            str_contains($value, 'townhouse'), str_contains($value, 'town house') => 'townhouse',
            str_contains($value, 'villa compound') => 'villa_compound',
            str_contains($value, 'villa') => 'villa',
            str_contains($value, 'penthouse') => 'penthouse',
            str_contains($value, 'office') => 'office',
            str_contains($value, 'shop') || str_contains($value, 'retail') || str_contains($value, 'showroom') => 'shop',
            str_contains($value, 'commercial') => 'commercial_building',
            str_contains($value, 'plot') || str_contains($value, 'land') => 'land',
            default => 'apartment',
        };
    }

    protected function moneyNumeric(mixed $value): ?float
    {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);
        $value = rtrim((string) $value, '.');

        return $value === '' || $value === '.' ? null : (float) $value;
    }

    protected function intOrNull(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (preg_match('/study/i', (string) $value)) {
            return 0;
        }
        $value = preg_replace('/[^0-9]/', '', (string) $value);

        return $value === '' ? null : (int) $value;
    }
}
