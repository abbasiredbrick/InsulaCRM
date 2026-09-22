<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RecycledLead;
use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * CSV/XLSX import of previous portal contacts (Bayut / Dubizzle /
 * PropertyFinder exports) into the Recycled Leads pool.
 *
 * Rows are auto-mapped from portal-style headers, deduplicated against the
 * pool (by phone / email / portal reference) and checked against the live
 * pipeline: contacts that already exist as active leads are flagged
 * "already_active" and linked, never duplicated.
 */
class RecycledLeadsImportService
{
    /** @var array<string, string[]> Canonical field => header aliases (normalized). */
    protected const FIELD_ALIASES = [
        'first_name' => ['firstname', 'first', 'firstname name', 'given name', 'name', 'contact name', 'lead name', 'buyer name', 'client name', 'full name'],
        'last_name' => ['lastname', 'last', 'surname', 'family name'],
        'phone' => ['phone', 'mobile', 'mobilenumber', 'phone number', 'phonenumber', 'telephone', 'tel', 'contact number', 'mobile no', 'mobile number', 'whatsapp', 'whatsapp number', 'phone no'],
        'email' => ['email', 'emailaddress', 'email address', 'mail', 'e mail', 'email id'],
        'reference' => ['reference', 'lead reference', 'lead ref', 'reference no', 'reference number', 'refno', 'ref', 'lead id', 'portal reference', 'listing reference', 'property reference', 'listing ref', 'query number', 'enquiry id', 'enquiry reference'],
        'original_deal_type' => ['deal type', 'transaction type', 'purpose', 'listing type', 'type of transaction', 'buy or rent', 'sale or rent', 'listing purpose', 'intent', 'type', 'property type interest'],
        'purchased_project' => ['project', 'project name', 'property project', 'development', 'development name', 'master project', 'project title', 'property name', 'property', 'community', 'location', 'project community'],
        'unit_no' => ['unitno', 'unit no', 'unit number', 'unit', 'flatno', 'flat no', 'flat', 'apartment no', 'apartment number', 'apartment', 'unitno'],
        'expected_handover_date' => ['handover date', 'expected handover', 'expected handover date', 'handover', 'handover date expected', 'possession date', 'completion date', 'key handover', 'delivery date', 'handover date est', 'handover date estimated', 'tower completion'],
        'gross_price' => ['price', 'purchase price', 'sale price', 'gross price', 'amount', 'total price', 'unit price', 'invested amount', 'budget', 'list price', 'asking price', 'price aed', 'property price'],
        'portal' => ['portal', 'source', 'lead source', 'website', 'platform', 'submitted on', 'via'],
        'notes' => ['notes', 'comments', 'remarks', 'remark', 'note', 'message', 'requirements', 'requirement', 'description', 'details', 'additional info', 'comments notes', 'notes from customer', 'enquiry details'],
    ];

    /**
     * Parse the uploaded file and persist pool records.
     *
     * @param  string|null  $primaryPortal  Fallback portal when the file has no portal column.
     * @return array{imported: int, skipped: int, already_active: int, duplicates: int}
     */
    public function import(string $path, string $format, int $tenantId, ?int $userId = null, ?string $primaryPortal = null, ?int $defaultAssigneeId = null, ?string $importName = null): array
    {
        try {
            $rows = $this->parseFile($path, $format);
        } catch (RuntimeException $e) {
            throw $e;
        }

        $imported = 0;
        $skipped = 0;
        $alreadyActive = 0;
        $duplicates = 0;

        foreach ($rows as $row) {
            $hasAnything = trim((string) ($row['first_name'] ?? '')) !== ''
                || trim((string) ($row['phone'] ?? '')) !== ''
                || trim((string) ($row['email'] ?? '')) !== '';

            if (! $hasAnything) {
                $skipped++;

                continue;
            }

            $phone = trim((string) ($row['phone'] ?? ''));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $reference = trim((string) ($row['reference'] ?? ''));

            // No phone and no email means there is no way to reach the contact —
            // keep the pool clean of un-actionable rows.
            if ($phone === '' && $email === '') {
                $skipped++;

                continue;
            }

            // 1) Already represented in the pool?
            if ($this->poolMatch($tenantId, $phone, $email, $reference)) {
                $duplicates++;

                continue;
            }

            // 2) Already an active (non-recycled) lead in the pipeline?
            $activeLead = $this->activeLeadMatch($tenantId, $phone, $email);
            $status = $activeLead ? 'already_active' : 'pending';

            RecycledLead::create([
                'tenant_id' => $tenantId,
                'created_by' => $userId,
                'source' => 'csv_import',
                'portal' => $this->normalizePortal($row['portal'] ?? null) ?? $primaryPortal,
                'reference' => $reference ?: null,
                'first_name' => trim((string) ($row['first_name'] ?? '')) ?: null,
                'last_name' => trim((string) ($row['last_name'] ?? '')) ?: null,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'original_deal_type' => $this->normalizeDealType($row['original_deal_type'] ?? null),
                'purchased_project' => trim((string) ($row['purchased_project'] ?? '')) ?: null,
                'unit_no' => trim((string) ($row['unit_no'] ?? '')) ?: null,
                'gross_price' => $this->moneyNumeric($row['gross_price'] ?? null),
                'expected_handover_date' => $this->parseDate($row['expected_handover_date'] ?? null)?->toDateString(),
                'status' => $status,
                'assignee_id' => $activeLead ? null : $defaultAssigneeId,
                'linked_lead_id' => $activeLead?->id,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'recycled_at' => now(),
                'raw_data' => array_filter([
                    'import_name' => $importName,
                    'source_row' => $this->rawDataSummary($row),
                ]),
            ]);

            if ($activeLead) {
                $alreadyActive++;
            } else {
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'already_active' => $alreadyActive,
            'duplicates' => $duplicates,
        ];
    }

    // ── Parsing & mapping ─────────────────────────────────────────

    /**
     * @return array<int, array<string, string>> Canonicalized rows.
     */
    protected function parseFile(string $path, string $format): array
    {
        $parser = new AvailabilityIngestService;
        $table = $parser->parseFile($path, $format, ['has_header' => true]);

        $columnMap = $this->mapHeaders($table['header']);

        $rows = [];
        foreach ($table['rows'] as $raw) {
            $rows[] = $this->canonicalizeRow($raw, $columnMap);
        }

        return $rows;
    }

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

    // ── Normalizers ───────────────────────────────────────────────

    protected function normalizePortal(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        return match (true) {
            str_contains($value, 'bayut') => 'bayut',
            str_contains($value, 'dubizzle') || str_contains($value, 'dubai dubizzle') => 'dubizzle',
            str_contains($value, 'propertyfinder'), str_contains($value, 'property finder'),
            str_contains($value, 'property-finder') => 'property_finder',
            default => null,
        };
    }

    protected function normalizeDealType(?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        return match (true) {
            str_contains($value, 'buy'), str_contains($value, 'sale'), str_contains($value, 'sales'),
            str_contains($value, 'sell'), str_contains($value, 'own'), str_contains($value, 'invest'),
            str_contains($value, 'purchase'), str_contains($value, 'off plan'), str_contains($value, 'offplan'),
            str_contains($value, 'secondary') => 'sale',
            str_contains($value, 'rent'), str_contains($value, 'lease'), str_contains($value, 'leas'),
            str_contains($value, 'annual'), str_contains($value, 'leasing'), str_contains($value, 'monthly'),
            str_contains($value, 'residential rent') => 'rent',
            default => null,
        };
    }

    protected function moneyNumeric(mixed $value): ?float
    {
        $value = preg_replace('/[^0-9.]/', '', (string) $value);
        $value = rtrim((string) $value, '.');

        return $value === '' || $value === '.' ? null : (float) $value;
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $raw = trim((string) $value);

        $formats = ['Y-m-d', 'Y/m/d', 'm/d/Y', 'd/m/Y', 'd-m-Y', 'j M Y', 'd M Y', 'M j, Y', 'F j, Y', 'j F Y'];
        foreach ($formats as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $raw);

                if ($parsed) {
                    return $parsed->startOfDay();
                }
            } catch (\Throwable) {
                // try the next format
            }
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Dedup ─────────────────────────────────────────────────────

    protected function poolMatch(int $tenantId, string $phone, string $email, string $reference): bool
    {
        if ($phone === '' && $email === '' && $reference === '') {
            return false;
        }

        return RecycledLead::where('tenant_id', $tenantId)
            ->where(function ($q) use ($phone, $email, $reference) {
                if ($phone !== '') {
                    $q->orWhere(function ($q2) use ($phone) {
                        $q2->whereNotNull('phone')->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, "+", ""), ".", ""), " ", ""), "-", ""), "(", "") = ?', [preg_replace('/\D+/', '', $phone)]);
                    });
                }
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
                if ($reference !== '') {
                    $q->orWhere('reference', $reference);
                }
            })
            ->exists();
    }

    protected function activeLeadMatch(int $tenantId, string $phone, string $email): ?Lead
    {
        if ($phone === '' && $email === '') {
            return null;
        }

        return Lead::where('tenant_id', $tenantId)
            ->whereNull('recycled_at')
            ->where(function ($q) use ($phone, $email) {
                if ($phone !== '') {
                    $q->where('phone', $phone);
                }
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
            })
            ->first();
    }

    /**
     * Keep a lightweight provenance fingerprint of the source row.
     */
    protected function rawDataSummary(array $row): array
    {
        $friendly = collect($row)
            ->filter()
            ->mapWithKeys(fn ($value, $key) => [Str::slug((string) $key) => $value]);

        return $friendly->all();
    }
}
