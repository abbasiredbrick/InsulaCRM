<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\RecycledLead;
use App\Models\RecycledLeadSourceEvent;
use App\Models\RecycledPortalImportRun;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Portals\PortalLeadSourceFactory;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecycledPortalLeadImportService
{
    public function __construct(
        protected ContactNormalizer $contacts,
        protected PortalLeadSourceFactory $sources,
    ) {}

    public function processNext(RecycledPortalImportRun $run): array
    {
        $run->refresh();

        if (! in_array($run->status, ['queued', 'running'], true)) {
            return ['dispatch_next' => false];
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
        ]);

        $criteria = (array) $run->criteria;
        $integration = PortalIntegration::query()
            ->where('tenant_id', $run->tenant_id)
            ->where('portal', $run->portal === 'property_finder' ? 'propertyfinder' : 'bayut')
            ->whereKey($run->portal_integration_id)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return $this->fail($run, 'The active portal integration for this import is no longer available.');
        }

        try {
            $result = $this->sources->make($run->portal, $integration)->fetchNext($criteria, (array) $run->cursor);
        } catch (Throwable $e) {
            return $this->fail($run, $e->getMessage());
        }

        if ($result['fatal'] ?? false) {
            return $this->fail($run, $result['error'] ?? 'The portal API request failed.');
        }

        if (filled($result['error'] ?? null) && ! ($result['done'] ?? true)) {
            throw new RuntimeException($result['error']);
        }

        $run->refresh();

        if ($run->status === 'cancelled') {
            return ['dispatch_next' => false];
        }

        $errors = array_values(array_filter((array) $run->errors));
        $newError = $result['error'] ?? null;

        if (filled($newError)) {
            $errors[] = $newError;
        }

        $done = (bool) ($result['done'] ?? true);
        $status = $done ? $this->completionStatus($run->mode, $errors !== []) : 'running';
        $updates = [
            'status' => $status,
            'cursor' => $result['cursor'] ?? [],
            'errors' => array_slice($errors, 0, 50),
            'error_count' => $run->error_count + (filled($newError) ? 1 : 0),
        ];

        DB::transaction(function () use ($run, $result, $criteria, $updates, $done, &$delta) {
            $delta = $this->processRecords($run, (array) ($result['records'] ?? []), $criteria);

            foreach ($delta as $field => $value) {
                $updates[$field] = $run->{$field} + $value;
            }

            if ($done) {
                $updates['completed_at'] = now();

                if ($run->mode === 'preview') {
                    $updates['preview_counts'] = $this->counts($run, $delta);
                }
            }

            $run->update($updates);
        });

        if ($done) {
            $this->audit($run, $run->mode === 'preview' ? 'recycled.portal_import.previewed' : 'recycled.portal_import.completed');
        }

        return ['dispatch_next' => ! $done];
    }

    protected function processRecords(RecycledPortalImportRun $run, array $records, array $criteria): array
    {
        $delta = [
            'observed_count' => 0,
            'out_of_range_count' => 0,
            'skipped_invalid_count' => 0,
            'skipped_no_contact_count' => 0,
            'contactable_count' => 0,
            'imported_count' => 0,
            'already_active_count' => 0,
            'duplicate_count' => 0,
            'enriched_count' => 0,
        ];

        foreach ($records as $record) {
            if (! is_array($record)) {
                $delta['skipped_invalid_count']++;

                continue;
            }

            $delta['observed_count']++;
            $prepared = $this->prepare($record, $criteria);

            if (($prepared['outcome'] ?? null) !== null) {
                $delta[$prepared['outcome']]++;

                continue;
            }

            $delta['contactable_count']++;
            $outcome = $run->mode === 'preview'
                ? $this->preview($run, $prepared)
                : $this->ingest($run, $prepared, $criteria);

            if ($outcome === 'enriched') {
                $delta['duplicate_count']++;
                $delta['enriched_count']++;
            } else {
                $delta[$outcome]++;
            }
        }

        return $delta;
    }

    protected function prepare(array $record, array $criteria): array
    {
        $date = $this->parseDate($record['received_at'] ?? null, $criteria['timezone'] ?? null);

        if ($date === null) {
            return ['outcome' => 'skipped_invalid_count'];
        }

        $date = $date->toDateString();

        if ($date < $criteria['date_from'] || $date > $criteria['date_to']) {
            return ['outcome' => 'out_of_range_count'];
        }

        $phone = $this->contacts->phone(
            filled($record['phone'] ?? null) ? (string) $record['phone'] : null,
            $criteria['country'] ?? null
        );
        $email = $this->contacts->email(filled($record['email'] ?? null) ? (string) $record['email'] : null);

        if ($phone === null && $email !== null && preg_match('/^whatsapp\.(\d{7,})@id\.(?:bayut|dubizzle)\.com$/i', $email, $matches)) {
            $phone = $this->contacts->phone($matches[1], $criteria['country'] ?? null);
            $email = null;
        }

        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }

        if ($phone === null && $email === null) {
            return ['outcome' => 'skipped_no_contact_count'];
        }

        $externalId = filled($record['external_id'] ?? null) ? Str::limit((string) $record['external_id'], 191, '') : null;
        $category = (string) ($record['provider_type'] ?? '');
        $category = isset(RecycledLead::CATEGORIES[$category]) ? $category : (filled($record['phone'] ?? null) ? 'phone' : 'email');
        $fallback = [
            $category,
            $record['provider_target'] ?? null,
            $date,
            $phone,
            $email,
            $record['first_name'] ?? null,
            $record['last_name'] ?? null,
        ];
        $hash = hash('sha256', $externalId ?? json_encode($fallback, JSON_UNESCAPED_SLASHES));

        return [
            'external_id' => $externalId,
            'external_id_hash' => $hash,
            'provider_type' => $category,
            'provider_target' => $record['provider_target'] ?? null,
            'provider_status' => $record['provider_status'] ?? null,
            'event_at' => $date,
            'first_name' => $this->limited($record['first_name'] ?? null, 100),
            'last_name' => $this->limited($record['last_name'] ?? null, 100),
            'phone' => $phone,
            'email' => $email,
            'message' => $record['message'] ?? null,
            'reference' => $this->limited($record['reference'] ?? null, 100),
            'listing_url' => $record['listing_url'] ?? null,
            'original_deal_type' => in_array($record['original_deal_type'] ?? null, ['rent', 'sale'], true)
                ? $record['original_deal_type']
                : null,
            'raw' => $record['raw'] ?? [],
        ];
    }

    protected function preview(RecycledPortalImportRun $run, array $lead): string
    {
        $event = $this->eventMatch($run, $lead);

        if ($event) {
            return 'duplicate_count';
        }

        $existing = $this->poolMatch($run, $lead);

        if ($existing) {
            return 'duplicate_count';
        }

        return $this->activeMatch($run, $lead) ? 'already_active_count' : 'imported_count';
    }

    protected function ingest(RecycledPortalImportRun $run, array $lead, array $criteria): string
    {
        try {
            return DB::transaction(function () use ($run, $lead, $criteria) {
                if ($this->eventMatch($run, $lead)) {
                    return 'duplicate_count';
                }

                $existing = $this->poolMatch($run, $lead);
                $active = $this->activeMatch($run, $lead);

                if ($existing) {
                    $patch = $this->enrichment($existing, $lead, $active);

                    if ($patch !== []) {
                        $existing->update($patch);
                    }

                    $this->createEvent($run, $existing, $lead);

                    return $patch === [] ? 'duplicate_count' : 'enriched_count';
                }

                $needsReview = $lead['original_deal_type'] === null;
                $record = RecycledLead::create([
                    'tenant_id' => $run->tenant_id,
                    'source' => 'api_import',
                    'portal' => $run->portal,
                    'category' => $lead['provider_type'],
                    'reference' => $lead['reference'],
                    'first_name' => $lead['first_name'],
                    'last_name' => $lead['last_name'],
                    'phone' => $lead['phone'] ? '+'.$lead['phone'] : null,
                    'email' => $lead['email'],
                    'normalized_phone' => $lead['phone'],
                    'normalized_email' => $lead['email'],
                    'original_deal_type' => $lead['original_deal_type'],
                    'status' => $active ? 'already_active' : 'pending',
                    'assignee_id' => $active ? null : ($criteria['agent_id'] ?? null),
                    'linked_lead_id' => $active?->id,
                    'created_by' => $run->user_id,
                    'notes' => $lead['message'],
                    'raw_data' => [
                        'portal_api' => [
                            'import_run_id' => $run->id,
                            'external_id' => $lead['external_id'],
                            'provider_type' => $lead['provider_type'],
                            'provider_target' => $lead['provider_target'],
                            'provider_status' => $lead['provider_status'],
                            'listing_url' => $lead['listing_url'],
                        ],
                    ],
                    'recycled_at' => now(),
                    'lead_date' => $lead['event_at'],
                    'needs_review' => $needsReview,
                    'review_reason' => $needsReview ? 'Portal API did not specify whether this enquiry was for sale or rent.' : null,
                ]);

                $this->createEvent($run, $record, $lead);

                return $active ? 'already_active_count' : 'imported_count';
            });
        } catch (QueryException $e) {
            if ($this->eventMatch($run, $lead)) {
                return 'duplicate_count';
            }

            throw $e;
        }
    }

    protected function enrichment(RecycledLead $existing, array $lead, ?Lead $active): array
    {
        $patch = [];

        foreach (['first_name', 'last_name', 'reference', 'notes', 'original_deal_type'] as $field) {
            if (blank($existing->{$field}) && filled($lead[$field])) {
                $patch[$field] = $lead[$field];
            }
        }

        if (blank($existing->category) && filled($lead['provider_type'])) {
            $patch['category'] = $lead['provider_type'];
        }

        if ($existing->lead_date === null) {
            $patch['lead_date'] = $lead['event_at'];
        }

        if ($existing->normalized_phone === null && $lead['phone'] !== null) {
            $patch['normalized_phone'] = $lead['phone'];
            $patch['phone'] = '+'.$lead['phone'];
        }

        if ($existing->normalized_email === null && $lead['email'] !== null) {
            $patch['normalized_email'] = $lead['email'];
            $patch['email'] = $lead['email'];
        }

        if ($active && $existing->status === 'pending') {
            $patch['status'] = 'already_active';
            $patch['linked_lead_id'] = $active->id;
        }

        if (! $existing->needs_review && blank($existing->original_deal_type) && $lead['original_deal_type'] === null) {
            $patch['needs_review'] = true;
            $patch['review_reason'] = 'Portal API did not specify whether this enquiry was for sale or rent.';
        }

        return $patch;
    }

    protected function createEvent(RecycledPortalImportRun $run, RecycledLead $record, array $lead): void
    {
        RecycledLeadSourceEvent::create([
            'tenant_id' => $run->tenant_id,
            'recycled_lead_id' => $record->id,
            'import_run_id' => $run->id,
            'portal' => $run->portal,
            'external_id' => $lead['external_id'],
            'external_id_hash' => $lead['external_id_hash'],
            'provider_type' => $lead['provider_type'],
            'provider_target' => $lead['provider_target'],
            'provider_status' => $lead['provider_status'],
            'event_at' => $lead['event_at'],
            'raw_data' => $lead['raw'],
        ]);
    }

    protected function eventMatch(RecycledPortalImportRun $run, array $lead): ?RecycledLeadSourceEvent
    {
        return RecycledLeadSourceEvent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $run->tenant_id)
            ->where('portal', $run->portal)
            ->where('external_id_hash', $lead['external_id_hash'])
            ->first();
    }

    protected function poolMatch(RecycledPortalImportRun $run, array $lead): ?RecycledLead
    {
        return RecycledLead::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $run->tenant_id)
            ->where(function ($query) use ($lead) {
                if ($lead['phone'] !== null) {
                    $query->where(function ($phoneQuery) use ($lead) {
                        $phoneQuery->where('normalized_phone', $lead['phone'])
                            ->orWhereRaw($this->phoneExpression().' = ?', [$lead['phone']]);
                    });
                }

                if ($lead['email'] !== null) {
                    $query->orWhere(function ($emailQuery) use ($lead) {
                        $emailQuery->where('normalized_email', $lead['email'])
                            ->orWhereRaw('LOWER(email) = ?', [$lead['email']]);
                    });
                }
            })
            ->first();
    }

    protected function activeMatch(RecycledPortalImportRun $run, array $lead): ?Lead
    {
        $country = Tenant::query()->whereKey($run->tenant_id)->value('country');
        $match = null;

        Lead::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $run->tenant_id)
            ->whereNull('recycled_at')
            ->where(function ($query) {
                $query->whereNotNull('phone')->orWhereNotNull('email');
            })
            ->orderBy('id')
            ->chunk(200, function ($leads) use ($lead, $country, &$match) {
                foreach ($leads as $candidate) {
                    if ($lead['phone'] !== null && $this->contacts->samePhone($lead['phone'], $candidate->phone, $country)) {
                        $match = $candidate;

                        return false;
                    }

                    if ($lead['email'] !== null
                        && $this->contacts->sameEmail($lead['email'], $candidate->email)
                        && ($match === null || $candidate->id > $match->id)) {
                        $match = $candidate;
                    }
                }
            });

        return $match;
    }

    protected function phoneExpression(): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '.', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '/', ''), '#', '')";
    }

    protected function parseDate(mixed $value, ?string $timezone = null): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    protected function limited(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    protected function counts(RecycledPortalImportRun $run, array $delta): array
    {
        $counts = [];

        foreach (array_keys($delta) as $field) {
            $counts[$field] = $run->{$field} + $delta[$field];
        }

        return $counts;
    }

    protected function completionStatus(string $mode, bool $hasErrors): string
    {
        if ($mode === 'preview') {
            return $hasErrors ? 'ready_with_errors' : 'ready';
        }

        return $hasErrors ? 'completed_with_errors' : 'completed';
    }

    protected function fail(RecycledPortalImportRun $run, string $message): array
    {
        $errors = array_values(array_filter((array) $run->errors));
        $errors[] = Str::limit($message, 500);

        $run->update([
            'status' => 'failed',
            'errors' => array_slice($errors, 0, 50),
            'error_count' => $run->error_count + 1,
            'completed_at' => now(),
        ]);

        $this->audit($run, 'recycled.portal_import.failed');

        return ['dispatch_next' => false];
    }

    protected function audit(RecycledPortalImportRun $run, string $action): void
    {
        AuditLog::create([
            'tenant_id' => $run->tenant_id,
            'user_id' => $run->user_id,
            'action' => $action,
            'model_type' => $run::class,
            'model_id' => $run->id,
            'new_values' => [
                'portal' => $run->portal,
                'mode' => $run->mode,
                'status' => $run->status,
                'observed' => $run->observed_count,
                'contactable' => $run->contactable_count,
                'imported' => $run->imported_count,
                'already_active' => $run->already_active_count,
                'duplicates' => $run->duplicate_count,
                'errors' => $run->error_count,
            ],
        ]);
    }
}
