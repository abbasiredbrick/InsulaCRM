<?php

namespace App\Services;

use App\Helpers\TenantFormatHelper;
use App\Models\Lead;
use App\Models\RecycledLead;
use App\Models\Tenant;
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
    /**
     * Prefix of the review reason used when a file carries no purpose column
     * and the listing reference does not say whether the deal was a rental or
     * a sale. Stored on the record, so it is also the marker for "this flag
     * only ever meant the type was unknown".
     */
    protected const NO_PURPOSE_REASON = 'No Purpose column';

    /** @var array<string, string[]> Canonical field => header aliases (normalized). */
    protected const FIELD_ALIASES = [
        'first_name' => ['firstname', 'first', 'firstname name', 'given name', 'name', 'contact name', 'lead name', 'sender name', 'buyer name', 'client name', 'customer name', 'full name'],
        'last_name' => ['lastname', 'last', 'surname', 'family name'],
        'phone' => ['phone', 'mobile', 'mobilenumber', 'phone number', 'phonenumber', 'telephone', 'tel', 'contact number', 'mobile no', 'mobile number', 'whatsapp', 'whatsapp number', 'whatsapp no', 'whatsapp number', 'phone no', 'lead number', 'lead no', 'leadnumber', 'leadno', 'sender number', 'sender phone', 'caller number', 'caller phone'],
        'email' => ['email', 'emailaddress', 'email address', 'mail', 'e mail', 'email id', 'lead email', 'sender email'],
        'enquirer_type' => ['enquiry from', 'enquirer type', 'enquiry type', 'enquirer', 'enquiry source', 'enquiry origin', 'lead origin', 'enquiry kind', 'contact type', 'buyer type', 'customer type'],
        'original_deal_type' => ['deal type', 'transaction type', 'purpose', 'listing type', 'type of transaction', 'buy or rent', 'sale or rent', 'listing purpose', 'intent', 'type', 'property type interest'],
        'purchased_project' => ['project', 'project name', 'property project', 'development', 'development name', 'master project', 'project title', 'property name', 'property', 'community', 'location', 'sub location', 'project community'],
        'unit_no' => ['unitno', 'unit no', 'unit number', 'unit', 'flatno', 'flat no', 'flat', 'apartment no', 'apartment number', 'apartment', 'unitno'],
        'expected_handover_date' => ['handover date', 'expected handover', 'expected handover date', 'handover', 'handover date expected', 'possession date', 'completion date', 'key handover', 'delivery date', 'handover date est', 'handover date estimated', 'tower completion'],
        'lead_date' => ['date', 'lead date', 'enquiry date', 'submitted date', 'received date', 'created date', 'created at', 'date received', 'enquiry received', 'lead date received'],
        'gross_price' => ['price', 'purchase price', 'sale price', 'gross price', 'amount', 'total price', 'unit price', 'invested amount', 'budget', 'list price', 'asking price', 'price aed', 'property price'],
        'portal' => ['portal', 'source', 'lead source', 'website', 'platform', 'submitted on', 'via'],
        'enquiry_kind' => ['enquiry from', 'enquirer type', 'enquiry type', 'enquirer', 'enquiry source', 'enquirer type', 'enquiry type', 'contact type', 'buyer type', 'customer type', 'type of enquiry', 'enquiry origin', 'channel', 'lead channel', 'contact channel'],
        'whatsapp_username' => ['whatsapp username', 'whatsappusername', 'whatsapp handle', 'wa username', 'whatsapp id', 'whatsappid'],
        'call_recording_url' => ['call record file', 'call recording', 'call recording url', 'call record', 'recording url', 'recording file', 'call audio', 'audio url'],
        'listing_reference' => ['listing reference', 'listingreference', 'listing ref', 'property reference', 'listing reference no'],
        'tags' => ['tags', 'tag', 'lead tags', 'labels'],
        'notes' => ['notes', 'comments', 'remarks', 'remark', 'note', 'message', 'message from lead', 'requirements', 'requirement', 'description', 'details', 'additional info', 'comments notes', 'notes from customer', 'enquiry details'],
    ];

    /**
     * Parse the uploaded file and persist pool records.
     *
     * @param  string|null  $primaryPortal  Fallback portal when the file has no portal column.
     * @return array{imported: int, skipped: int, skipped_no_contact: int, dropped_internal: int, dropped_agent: int, already_active: int, duplicates: int, flagged_review: int, flagged_no_purpose: int}
     */
    public function import(string $path, string $format, int $tenantId, ?int $userId = null, ?string $primaryPortal = null, ?int $defaultAssigneeId = null, ?string $importName = null, ?string $category = null): array
    {
        try {
            $parsed = $this->parseFile($path, $format);
        } catch (RuntimeException $e) {
            throw $e;
        }

        $rows = $parsed['rows'];
        $category = $category ?? $parsed['category'];

        $tenant = Tenant::query()->whereKey($tenantId)->first();
        $tenantCountry = $tenant?->country;

        $imported = 0;
        $skipped = 0;
        $skippedNoContact = 0;
        $droppedInternal = 0;
        $droppedAgent = 0;
        $flaggedReview = 0;
        $flaggedNoPurpose = 0;
        $alreadyActive = 0;
        $duplicates = 0;

        foreach ($rows as $row) {
            // Property Finder tags a row "from_agent" when the enquiry was
            // created by one of our own agents rather than by a buyer. Those
            // are our own working rows, not leads to work, so they never enter
            // the pool. Dropped silently, like the agent-mirror rows below.
            if ($this->hasTag($row['tags'] ?? null, 'from_agent')) {
                $droppedAgent++;

                continue;
            }

            $whatsappUsername = $this->normalizeWhatsappUsername($row['whatsapp_username'] ?? null);

            $hasAnything = trim((string) ($row['first_name'] ?? '')) !== ''
                || trim((string) ($row['phone'] ?? '')) !== ''
                || trim((string) ($row['email'] ?? '')) !== ''
                || $whatsappUsername !== '';

            if (! $hasAnything) {
                $skipped++;

                continue;
            }

            $phone = $this->normalizePhone($row['phone'] ?? null, $tenantCountry);
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $callRecordingUrl = $this->normalizeCallRecordingUrl($row['call_recording_url'] ?? null);

            // The "reference" in report exports is the listing/property
            // reference (e.g. "10219-…"), which is the unit's portal id — not
            // a lead id and not needed in the pool. It is deliberately not
            // stored; dedup must rely on phone/email only.

            // Bayut embeds the WhatsApp contact into the email slot as
            // "whatsapp.<number>@id.bayut.com" — that number is the callable
            // signal, so promote it into the phone column.
            if ($phone === '' && preg_match('/^whatsapp\.(\d{7,})@/', $email, $m)) {
                $phone = $this->normalizePhone($m[1], $tenantCountry);

                // The "whatsapp…@id.bayut.com" address is a relay that only
                // carries the number we just promoted — it is not a real
                // mailbox, so drop it (do not store a fake address).
                $email = '';
            }

            // No phone, no email and no WhatsApp username means there is no way
            // to reach the contact — keep the pool clean of un-actionable rows.
            // The listing/case reference ("10219-…") is the property's
            // reference, not a way to reach anyone, so it never rescues a row
            // on its own.
            if ($phone === '' && $email === '' && $whatsappUsername === '') {
                $skipped++;
                $skippedNoContact++;

                continue;
            }

            // Internal / self-tests: a company-domain address or the office's
            // own number is the team testing imports, not a real buyer.
            if ($this->matchesInternalContact($tenant, $phone, $email)) {
                $droppedInternal++;

                continue;
            }

            // Bayut flags agent-to-agent rows: "Enquiry From" / "Enquirer Type"
            // = "Agent Profile" / "Agent Account" means another agent's row was
            // mirrored to us — not an end-user enquiry. Drop silently (no
            // counter): it is not a real buyer and never gets reviewed or
            // contacted. Matched on the full label on purpose: a bare "agent"
            // is Property Finder's agent-profile lead type, which is a real
            // buyer who enquired through an agent's page.
            $enquirerType = strtolower(trim((string) ($row['enquirer_type'] ?? '')));
            if ($enquirerType !== '' && (str_contains($enquirerType, 'agent profile') || str_contains($enquirerType, 'agent account'))) {
                continue;
            }

            // Database enrichment parsed from the row — the review flag, the
            // lease/sale type and the enquiry date. Parsed before dedup so a
            // re-upload of the same file backfills fields that were not
            // captured when the row was first imported (Date / Purpose). An
            // existing pool record never gets re-created; it is updated.
            $dealType = $this->normalizeDealType($row['original_deal_type'] ?? null);
            $leadDate = $this->parseDate($row['lead_date'] ?? null)?->toDateString();

            // Pristine encodes the transaction in the listing reference itself
            // ("AD-R-11313326" is a rental, "MK-S-13688726" a sale), which is
            // the only rent/buy signal a Property Finder export carries. Read
            // it for Pristine so those leads do not all land as "unknown
            // purpose" needing a manual review.
            if ($dealType === null && $this->usesPristineListingReferenceScheme($tenant)) {
                $dealType = $this->dealTypeFromListingReference($row['listing_reference'] ?? null);
            }

            // A file-level channel wins only when the row carries none of its
            // own. Property Finder exports mix WhatsApp and call rows in one
            // file, so the per-row channel is the accurate label there.
            $rowCategory = $this->normalizeChannel($row['enquiry_kind'] ?? null) ?? $category;

            // Already an active (non-recycled) lead in the pipeline?
            $activeLead = $this->activeLeadMatch($tenantId, $phone, $email);
            $status = $activeLead ? 'already_active' : 'pending';

            // Suspicious / non-standard emails (masked relays, "whatsapp@…"
            // placeholders) still get imported, but flagged so an agent reviews
            // the contact before burning outreach time.
            $reviewReasons = [];

            $emailReason = $this->emailReviewReason($phone, $email);
            if ($emailReason !== null) {
                $reviewReasons[] = $emailReason;
            }

            // Email leads usually carry no "Purpose" column, so the lease/sale
            // type cannot be inferred from the file. Flag the row for review
            // instead of guessing a type; the price hints at the likely answer
            // (studio sales start around AED 500,000, so cheaper rows read as
            // lease) but an agent decides before regenerating.
            if ($status === 'pending' && $dealType === null) {
                $price = $this->moneyNumeric($row['gross_price'] ?? null);
                $hint = $price === null
                    ? 'no price to infer'
                    : ($price < 500000
                        ? 'price AED '.number_format($price).' suggests a lease (under AED 500,000)'
                        : 'price AED '.number_format($price).' suggests a sale (AED 500,000+)');

                $reviewReasons[] = self::NO_PURPOSE_REASON.' — '.$hint.'; confirm lease/sale before regenerating.';
            }

            $reviewReason = $reviewReasons === [] ? null : mb_substr(implode(' ', $reviewReasons), 0, 150);

            // 1) Already represented in the pool? Backfill the newly-parseable
            // fields (lead_date, type, review flag) instead of skipping.
            $existing = $this->poolMatched($tenantId, $phone, $email, $whatsappUsername);
            if ($existing) {
                $duplicates++;

                $patch = [];

                // 1) The contact's first enquiry date is the one that matters, so
                // a later row carrying an EARLIER date corrects the record
                // instead of the stored date staying "whatever the creating row
                // happened to say".
                if ($leadDate !== null && ($existing->lead_date === null || $leadDate < $existing->lead_date->toDateString())) {
                    $patch['lead_date'] = $leadDate;
                }
                if ($existing->original_deal_type === null && $dealType !== null) {
                    $patch['original_deal_type'] = $dealType;
                }

                // 2) Same person, both intents. Exports split one contact's
                // rental and sale enquiries across rows, and a single type
                // column silently dropped the second one. Keep the first type as
                // the record's own and remember the other one, so the pool shows
                // "Rent + Sale" and regeneration can pick either intent.
                $storedType = $patch['original_deal_type'] ?? $existing->original_deal_type;
                $storedAlternate = $existing->alternate_deal_type;
                if ($dealType !== null) {
                    if ($storedType === null && $storedAlternate !== null) {
                        $patch['original_deal_type'] = $storedAlternate;
                        $patch['alternate_deal_type'] = null;
                    } elseif ($storedType !== null && $dealType !== $storedType && $storedAlternate === null) {
                        $patch['alternate_deal_type'] = $dealType;
                    }
                }

                // 3) A re-upload often holds a better copy of the contact than
                // the row that created the record — the creating row was a
                // truncated call entry. Fill what is missing and let a fuller
                // name win, so the pool stops holding "Omar" for a contact
                // whose export says "Omar Sherif".
                $newFirstName = trim((string) ($row['first_name'] ?? ''));
                $newLastName = trim((string) ($row['last_name'] ?? ''));
                if ($this->nameIsRicher($existing->first_name, $existing->last_name, $newFirstName, $newLastName)) {
                    if ($newFirstName !== '') {
                        $patch['first_name'] = $newFirstName;
                    }
                    if ($newLastName !== '') {
                        $patch['last_name'] = $newLastName;
                    }
                }
                if ($email !== '' && $this->isPlaceholderValue($email) === false && $this->isPlaceholderValue($existing->email)) {
                    $patch['email'] = $email;
                }

                // The "no purpose" flag only ever meant "nobody could tell rent
                // from sale here". Once a listing reference resolves the type
                // that reason is stale — an earlier upload may even have
                // backfilled the type while leaving the flag behind. Drop the
                // flag when that was the only reason; when the row is flagged
                // for something else too (a masked portal email), keep the flag
                // and drop just the stale clause.
                if ($dealType !== null && $existing->needs_review) {
                    $remaining = $this->withoutNoPurposeReason($existing->review_reason);

                    if ($remaining === null) {
                        $patch['needs_review'] = false;
                        $patch['review_reason'] = null;
                    } elseif ($remaining !== (string) $existing->review_reason) {
                        $patch['review_reason'] = $remaining;
                    }
                }
                if ($existing->category === null && $rowCategory !== null) {
                    $patch['category'] = $rowCategory;
                }
                if ($existing->whatsapp_username === null && $whatsappUsername !== '') {
                    $patch['whatsapp_username'] = $whatsappUsername;
                }
                if ($existing->call_recording_url === null && $callRecordingUrl !== '') {
                    $patch['call_recording_url'] = $callRecordingUrl;
                }

                // A "no purpose" flag only means anything while the type is
                // unknown, and the same contact can turn up twice in one
                // upload — once against a listing reference, once without. Judge
                // it on the type the record ends up with, otherwise the row
                // without a reference re-flags a record that another row of the
                // same upload just resolved, and the flag depends on row order.
                $effectiveDealType = $dealType ?? $existing->original_deal_type;
                $flagReason = $effectiveDealType === null
                    ? $reviewReason
                    : $this->withoutNoPurposeReason($reviewReason);

                if (! $existing->needs_review && $flagReason !== null) {
                    $patch['needs_review'] = true;
                    $patch['review_reason'] = $flagReason;
                }

                if ($patch !== []) {
                    $existing->update($patch);
                }

                continue;
            }

            // Count self-import flags only for rows that actually land in the pool.
            if ($activeLead) {
                $alreadyActive++;
            } else {
                if ($emailReason !== null) {
                    $flaggedReview++;
                }
                if ($dealType === null) {
                    $flaggedNoPurpose++;
                }
                $imported++;
            }

            RecycledLead::create([
                'tenant_id' => $tenantId,
                'created_by' => $userId,
                'source' => 'csv_import',
                'portal' => $this->normalizePortal($row['portal'] ?? null) ?? $primaryPortal,
                'category' => $rowCategory,
                'first_name' => trim((string) ($row['first_name'] ?? '')) ?: null,
                'last_name' => trim((string) ($row['last_name'] ?? '')) ?: null,
                'phone' => $phone ?: null,
                'whatsapp_username' => $whatsappUsername ?: null,
                'email' => $email ?: null,
                'original_deal_type' => $dealType,
                'purchased_project' => trim((string) ($row['purchased_project'] ?? '')) ?: null,
                'unit_no' => trim((string) ($row['unit_no'] ?? '')) ?: null,
                'gross_price' => $this->moneyNumeric($row['gross_price'] ?? null),
                'expected_handover_date' => $this->parseDate($row['expected_handover_date'] ?? null)?->toDateString(),
                'lead_date' => $leadDate,
                'status' => $status,
                'assignee_id' => $activeLead ? null : $defaultAssigneeId,
                'linked_lead_id' => $activeLead?->id,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'call_recording_url' => $callRecordingUrl ?: null,
                'needs_review' => $reviewReason !== null,
                'review_reason' => $reviewReason,
                'recycled_at' => now(),
                'raw_data' => array_filter([
                    'import_name' => $importName,
                    'source_row' => $this->rawDataSummary($row),
                ]),
            ]);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'skipped_no_contact' => $skippedNoContact,
            'dropped_internal' => $droppedInternal,
            'dropped_agent' => $droppedAgent,
            'flagged_review' => $flaggedReview,
            'flagged_no_purpose' => $flaggedNoPurpose,
            'already_active' => $alreadyActive,
            'duplicates' => $duplicates,
        ];
    }

    // ── Parsing & mapping ─────────────────────────────────────────

    /**
     * @return array{rows: array<int, array<string, string>>, category: ?string}
     */
    protected function parseFile(string $path, string $format): array
    {
        $parser = new AvailabilityIngestService;

        // Fast path: a table whose header is on the first row. Availability's
        // parser auto-promotes such a first row, so the map is always complete.
        $table = $parser->parseFile($path, $format, ['has_header' => true]);
        $map = $this->mapHeaders($table['header']);

        if (count($map) >= 3) {
            return [
                'rows' => $this->canonicalizeRows($table['rows'], $map),
                'category' => $this->detectCategory($table['rows']),
            ];
        }

        // Report-style exports (Bayut / Dubizzle / PropertyFinder) carry a
        // "Report Title / Prepared On / SEARCH CRITERIA" preamble before the
        // real table header. Parse positionally and locate that header row.
        $grid = $parser->parseFile($path, $format, ['has_header' => false]);

        $headerIndex = $this->locateHeaderRow($grid['rows']);

        if ($headerIndex === null) {
            return ['rows' => [], 'category' => $this->detectCategory($grid['rows'])];
        }

        $columnMap = $this->mapHeaders(array_values($grid['rows'][$headerIndex]));
        $rows = array_slice(array_values($grid['rows']), $headerIndex + 1);

        return [
            'rows' => $this->canonicalizeRows($rows, $columnMap),
            'category' => $this->detectCategory($grid['rows']),
        ];
    }

    /**
     * Which channel the export belongs to (WhatsApp / Phone / Email). Bayut
     * ships one log per channel, so the message-type column or the report
     * title tells us the category for every row in the file.
     *
     * @param  array<int, array<int, mixed>>  $grid
     */
    protected function detectCategory(array $grid): ?string
    {
        $scores = ['whatsapp' => 0, 'phone' => 0, 'email' => 0];

        foreach ($grid as $row) {
            foreach (array_values($row) as $cell) {
                $lower = strtolower(trim((string) $cell));

                if (str_contains($lower, 'whatsapp')) {
                    $scores['whatsapp']++;
                } elseif (preg_match('/(phone call|phone view|phone message|phone leads|missed call|phone log)/', $lower)) {
                    $scores['phone']++;
                } elseif (str_contains($lower, 'email')) {
                    $scores['email']++;
                }
            }
        }

        $winner = null;
        $top = 0;

        foreach ($scores as $channel => $score) {
            if ($score > $top) {
                $top = $score;
                $winner = $channel;
            }
        }

        return $winner;
    }

    /**
     * Scan the grid for the row that is the actual lead-table header,
     * skipping report-preamble rows that precede it.
     */
    protected function locateHeaderRow(array $rows): ?int
    {
        $bestIndex = null;
        $bestScore = 0;

        foreach ($rows as $index => $raw) {
            $score = count($this->mapHeaders(array_values($raw)));

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }
        }

        return $bestScore >= 2 ? $bestIndex : null;
    }

    /**
     * @return array<int, string> Position => canonical field.
     */
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
                        $map[(int) $key] = $field;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function canonicalizeRows(array $rows, array $columnMap): array
    {
        $hasLastNameColumn = in_array('last_name', $columnMap, true);

        return array_map(
            fn (array $raw) => $this->canonicalizeRow(array_values($raw), $columnMap, $hasLastNameColumn),
            $rows
        );
    }

    protected function canonicalizeRow(array $raw, array $columnMap, bool $hasLastNameColumn): array
    {
        $row = [];

        foreach ($columnMap as $index => $field) {
            $value = trim((string) ($raw[$index] ?? ''));
            if ($value === '') {
                continue;
            }

            $value = $this->cleanField($field, $value);

            if ($value === '') {
                continue;
            }

            // A single "name" column carries first and last; split it when the
            // file offers no separate last-name column.
            if ($field === 'first_name' && ! $hasLastNameColumn) {
                $parts = preg_split('/\s+/', $value, 2) ?: [];
                $row['first_name'] = $parts[0] ?? $value;
                $row['last_name'] = $parts[1] ?? null;

                continue;
            }

            $row[$field] = $value;
        }

        // Bayut exports split the location across two columns; when both map
        // to purchased_project, keep the pair so the project reads naturally.
        if (isset($row['purchased_project'])) {
            $positions = $this->positionsFor($columnMap, 'purchased_project');
            if (count($positions) >= 2) {
                $location = trim((string) ($raw[$positions[0]] ?? ''));
                $sub = trim((string) ($raw[$positions[1]] ?? ''));
                if ($sub !== '' && $sub !== $location) {
                    $row['purchased_project'] = $location.' - '.$sub;
                }
            }
        }

        return $row;
    }

    /**
     * @return array<int, int> Positions in a column map that map to a field.
     */
    protected function positionsFor(array $columnMap, string $field): array
    {
        return array_keys(array_filter($columnMap, fn ($mapped) => $mapped === $field));
    }

    /**
     * Normalize a phone from a portal export to international E.164 form
     * ("+971501234567"). Local numbers (leading 0 or missing the country code)
     * are promoted to the tenant's dialing code; an explicit international
     * number keeps its country code.
     */
    protected function normalizePhone(mixed $phone, ?string $country): string
    {
        $raw = trim((string) $phone);

        if ($raw === '' || $raw === 'null' || str_contains($raw, 'y/')) {
            return '';
        }

        $code = TenantFormatHelper::dialingCode($country);
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        // Explicit "+971..." already carries the country code.
        if (str_starts_with($raw, '+')) {
            return $this->stripNationalTrunkZero('+'.$digits);
        }

        // "00..." international access prefix → 0 + country code.
        if (str_starts_with($digits, '00')) {
            return $this->stripNationalTrunkZero('+'.substr($digits, 2));
        }

        // Leading 0 → local format ("0501..."), promote to the dialing code.
        if (str_starts_with($raw, '0') && $code !== null) {
            return '+'.$code.ltrim($digits, '0');
        }

        // Bare "971..." already in international form (no separator) → +.
        if ($code !== null && str_starts_with($digits, $code)) {
            return $this->stripNationalTrunkZero('+'.$digits);
        }

        // Anything else: country code unknown for this digit length → keep digits.
        return '+'.$digits;
    }

    /**
     * Country codes whose national numbers never begin with a trunk zero. In
     * Italy (+39 06 …), Kenya (+254 …) and elsewhere the leading zero is part
     * of the number, so it must never be stripped there.
     */
    protected const NO_TRUNK_ZERO_COUNTRIES = ['965', '966', '968', '971', '973', '974'];

    /**
     * E.164 national numbers never start with a trunk zero, but portal exports
     * sometimes write one in after the country code — Property Finder ships
     * "+9710521339166" for "+971521339166". Left alone that files the same
     * person twice and splits their enquiries across two pool rows. The code is
     * read off the number itself, not the tenant, because these exports carry
     * international numbers too.
     */
    protected function stripNationalTrunkZero(string $e164): string
    {
        $digits = ltrim($e164, '+');

        foreach (self::NO_TRUNK_ZERO_COUNTRIES as $code) {
            if (! str_starts_with($digits, $code)) {
                continue;
            }

            $national = substr($digits, strlen($code));

            // Only a single stray zero in front of an otherwise complete number.
            if (str_starts_with($national, '0') && strlen(ltrim($national, '0')) >= 6) {
                return '+'.$code.ltrim($national, '0');
            }
        }

        return $e164;
    }

    /**
     * Strip report placeholders (Unknown, N/A, "-", numbers masquerading as
     * names) from contact / identity columns so they read as blank.
     */
    protected function cleanField(string $field, string $value): string
    {
        if ($field === 'email' && strtolower($value) === 'unknown') {
            return '';
        }

        if (in_array($field, ['phone', 'email'], true)) {
            $lower = strtolower($value);

            if (in_array($lower, ['unknown', 'n/a', 'na', 'null', 'none', '-', '.'], true)) {
                return '';
            }
        }

        if (in_array($field, ['first_name', 'last_name'], true)) {
            $lower = strtolower($value);

            if (in_array($lower, ['unknown', 'n/a', 'na', 'null', 'none', '-', '.'], true)) {
                return '';
            }

            // A raw phone number in the name column (Bayut phone exports put
            // "971549999999"-style values there on call rows).
            if (preg_match('/^[+()\d\s\-]{7,}$/', $value)) {
                return '';
            }
        }

        return $value;
    }

    protected function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));

        return (string) preg_replace('/[^a-z0-9]+/', '', $header);
    }

    // ── Normalizers ───────────────────────────────────────────────

    /**
     * Whether a row carries a portal tag (Property Finder ships a "tags"
     * column; multiple tags arrive comma- or space-separated).
     */
    /**
     * Strip the "No Purpose column" clause from a review reason, returning what
     * is left — or null when that clause was all there was. The clause is always
     * appended last, so anything before it is a different (still valid) reason.
     */
    protected function withoutNoPurposeReason(mixed $reason): ?string
    {
        $reason = trim((string) $reason);

        if (! str_contains($reason, self::NO_PURPOSE_REASON)) {
            return $reason === '' ? null : $reason;
        }

        $stripped = trim(preg_replace(
            '/\s*'.preg_quote(self::NO_PURPOSE_REASON, '/').'\b.*$/us',
            '',
            $reason
        ));

        return $stripped === '' ? null : $stripped;
    }

    protected function hasTag(mixed $raw, string $tag): bool
    {
        if (blank($raw)) {
            return false;
        }

        $needle = strtolower($tag);
        $parts = preg_split('/[,;|\s]+/', strtolower(trim((string) $raw))) ?: [];

        return in_array($needle, array_map('trim', $parts), true);
    }

    /**
     * A WhatsApp username is a handle, not a phone number: keep it as typed
     * apart from surrounding whitespace and any leading "@".
     */
    protected function normalizeWhatsappUsername(mixed $raw): string
    {
        $value = ltrim(trim((string) $raw), '@');

        return trim($value);
    }

    /**
     * Keep a portal call recording only when it is a playable audio URL.
     * Property Finder hands out presigned links, so the URL is stored as-is
     * and simply stops working once the portal's signature expires.
     */
    protected function normalizeCallRecordingUrl(mixed $raw): string
    {
        $value = trim((string) $raw);

        if ($value === '' || ! str_starts_with($value, 'http')) {
            return '';
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    /**
     * Pristine Properties encodes the transaction as one dash-separated
     * segment of the listing reference: "AD-R-11313326" and "NF-R" are
     * rentals, "MK-S-13688726" and "LS-BR-S-KIUYTFDC" are sales. Everything
     * around that segment varies by project — project code, building, unit,
     * even a size in square feet — so the marker segment on its own is the
     * signal; the number of segments is not. References without one
     * ("GRN28-NZ", "PRISTINE-PROPERTIES-41820") carry no signal: null.
     */
    protected function dealTypeFromListingReference(mixed $raw): ?string
    {
        $reference = strtoupper(trim((string) $raw));

        if ($reference === '') {
            return null;
        }

        // Segments are trimmed before matching: real exports carry padding
        // around the type code ("NF-R -12251230" for a rental), and an
        // untrimmed segment would read as unknown and flag a known intent.
        foreach (explode('-', $reference) as $segment) {
            $segment = trim($segment);

            if ($segment === 'R') {
                return 'rent';
            }
            if ($segment === 'S') {
                return 'sale';
            }
        }

        return null;
    }

    /**
     * Whether an imported name says strictly more than the stored one.
     *
     * The stored name wins unless it is missing/placeholder, or the imported
     * name carries more of the person's name (a full first name, or the surname
     * the creating row never captured). Judged on the combined name because a
     * later row may split the parts differently.
     */
    protected function nameIsRicher(?string $currentFirst, ?string $currentLast, string $newFirst, string $newLast): bool
    {
        $current = trim(($currentFirst ?? '').' '.($currentLast ?? ''));
        $incoming = trim($newFirst.' '.$newLast);

        if ($incoming === '' || $this->isPlaceholderValue($incoming)) {
            return false;
        }

        if ($this->isPlaceholderValue($current)) {
            return true;
        }

        return mb_strlen($incoming) > mb_strlen($current);
    }

    /**
     * Values that carry no contact information, so a real value from another
     * row always replaces them.
     */
    protected function isPlaceholderValue(?string $value): bool
    {
        $normal = mb_strtolower(trim((string) $value));

        return in_array($normal, ['', 'unknown', 'n/a', 'na', 'none', 'null', 'nil', '-', '--', 'no name', 'no email'], true);
    }

    /**
     * The listing-reference convention above is Pristine's own, so it is only
     * applied for that tenant — other agencies' references are left alone.
     */
    protected function usesPristineListingReferenceScheme(?Tenant $tenant): bool
    {
        if ($tenant === null) {
            return false;
        }

        foreach ([$tenant->name, $tenant->website, $tenant->email] as $value) {
            if (str_contains(strtolower((string) $value), 'pristine')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The channel a single row arrived through, when the file names it
     * (Property Finder exports a "channel" column: whatsapp / call / email).
     */
    protected function normalizeChannel(mixed $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        $value = strtolower(trim((string) $raw));

        return match (true) {
            str_contains($value, 'whatsapp') => 'whatsapp',
            str_contains($value, 'sms') => 'sms',
            str_contains($value, 'call'), str_contains($value, 'phone'), str_contains($value, 'voice'), str_contains($value, 'tel') => 'phone',
            str_contains($value, 'email'), str_contains($value, 'mail') => 'email',
            default => null,
        };
    }

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

    protected function matchesInternalContact(Tenant $tenant, string $phone, string $email): bool
    {
        // The office's own number, or an address on the company's domain, is
        // the team running an import self-test — not a real buyer.
        $tenantPhone = $this->normalizePhone($tenant->phone ?? null, $tenant->country);

        if ($phone !== '' && $tenantPhone !== '' && $this->sameE164($phone, $tenantPhone)) {
            return true;
        }

        return $this->matchesInternalDomain($tenant, $email);
    }

    protected function matchesInternalDomain(Tenant $tenant, string $email): bool
    {
        if ($email === '') {
            return false;
        }

        foreach ([$tenant->country, $tenant->country ?? null] as $unused) {
            break;
        }

        $companyDomains = $this->internalDomains($tenant);

        if ($companyDomains === []) {
            return false;
        }

        $domain = strtolower(trim((string) substr($email, (int) strrpos($email, '@') + 1)));

        return in_array($domain, $companyDomains, true);
    }

    /**
     * Domains owned by the tenant (email MX passes, website, brand names) —
     * mail sent here is the team testing the pool, never a portal buyer.
     *
     * @return array<int, string> Lowercased domains.
     */
    protected function internalDomains(Tenant $tenant): array
    {
        $candidates = [];

        foreach ([$tenant->email, $tenant->website] as $raw) {
            $candidate = strtolower(trim((string) $raw));

            if ($candidate === '') {
                continue;
            }

            $candidate = preg_replace('#^https?://(www\.)?#', '', $candidate);
            $candidate = (string) preg_replace('#^www\.#', '', $candidate);
            $candidate = (string) preg_replace('#[/?].*$#', '', $candidate);

            if ($candidate !== '') {
                $candidates[] = trim($candidate, '.');
            }
        }

        // Extract the domain part of any @addresses.
        foreach ($candidates as $index => $candidate) {
            if (str_contains($candidate, '@') && $candidate !== '') {
                $candidates[$index] = (string) substr($candidate, (int) strrpos($candidate, '@') + 1);
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Structural review flag for an address that is not a normal mailbox:
     *
     *  - Apple private relay / masked relay placeholders — real hidden address,
     *    but an agent should confirm it before outreach.
     *  - Bayut masked "whatsapp.<n>@id.bayut.com" — not an email, the number
     *    was already promoted to the phone column during normalizePhone.
     *  - Anything that clearly is not a standard email (no @, spaces, angle
     *    brackets, "n/a", etc.) — keep the pool clean of junk.
     *
     * Returns the review reason, or null when the address is clean enough to
     * upload straight away.
     */
    protected function emailReviewReason(string $phone, string $email): ?string
    {
        if ($email === '') {
            return null;
        }

        $lower = strtolower($email);

        if (str_contains($lower, '@id.bayut.com') || str_contains($lower, '@id.dubizzle.com')) {
            return 'Masked portal email — "whatsapp.<n>@id.bayut.com" is a WhatsApp number, not a real address';
        }

        if (str_contains($lower, '@privaterelay.apple')) {
            return 'Apple private relay — the buyer hides their real address, confirm before outreach';
        }

        if (str_contains($lower, '@privaterelay.icloud')) {
            return 'iCloud private relay — hidden address, confirm before outreach';
        }

        $domain = strtolower(trim((string) (str_contains($lower, '@') ? (string) substr($lower, (int) strrpos($lower, '@') + 1) : $lower)));

        if ($domain === '' || str_contains($email, ' ') || str_contains($email, '<') || str_contains($email, '>')) {
            return 'Not a standard email structure — review before upload';
        }

        if (in_array(strtolower($email), ['unknown', 'n/a', 'na', 'null', 'none', '-', '.'], true)) {
            return 'No real email — placeholder value';
        }

        $domain = strtolower((string) (str_contains($email, '@') ? substr($email, (int) strrpos($email, '@') + 1) : ''));

        if ($domain === '' || ! str_contains($domain, '.')) {
            return 'Not a standard email structure — missing TLD';
        }

        return null;
    }

    protected function sameE164(string $a, string $b): bool
    {
        return preg_replace('/\D+/', '', $a) === preg_replace('/\D+/', '', $b);
    }

    protected function poolMatched(int $tenantId, string $phone, string $email, string $whatsappUsername = ''): ?RecycledLead
    {
        if ($phone === '' && $email === '' && $whatsappUsername === '') {
            return null;
        }

        return RecycledLead::where('tenant_id', $tenantId)
            ->where(function ($q) use ($phone, $email, $whatsappUsername) {
                if ($phone !== '') {
                    // The number is the identity when the row carries one: a
                    // different number is a different lead even when the email
                    // matches (people carry old and new numbers through
                    // exports). Email/username only identify a contact that
                    // arrives with no number to call at all.
                    $q->whereNotNull('phone')->whereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, "+", ""), ".", ""), " ", ""), "-", ""), "(", "") = ?', [preg_replace('/\D+/', '', $phone)]);

                    return;
                }

                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
                if ($whatsappUsername !== '') {
                    $q->orWhere('whatsapp_username', $whatsappUsername);
                }
            })
            ->first();
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

                    return;
                }

                $q->where('email', $email);
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
