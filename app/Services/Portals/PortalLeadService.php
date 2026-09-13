<?php

namespace App\Services\Portals;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PortalLeadUnclaimed;
use App\Notifications\ReturningClientInterest;
use App\Services\ContactNormalizer;
use App\Services\LeadDistributionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PortalLeadService
{
    /**
     * Two identical inquiries for the same listing inside this window are a
     * re-fetch / double send, not a fresh opportunity.
     */
    public const DUPLICATE_WINDOW_MINUTES = 120;

    public function createFromPayload(PortalIntegration $integration, string $source, array $data): ?Lead
    {
        $tenant = $integration->tenant;

        [$first, $last] = $this->splitName($data['name'] ?? '');
        $phone = $this->cleanPhone($data['phone'] ?? null);
        $email = trim((string) ($data['email'] ?? ''));

        if ($first === '' && $phone === null && $email === '') {
            return null;
        }

        // The same portal lead can arrive multiple times (type/target buckets,
        // re-pulls). When its portal reference is already ingested, skip.
        if (($data['id'] ?? null) !== null) {
            $ingested = $this->findByPortalReference($integration, (string) $data['id']);
            if ($ingested !== null) {
                return $ingested;
            }
        }

        // Listing references embed the owning agent's code ({AGENTCODE}-{PROPERTYID}),
        // so the lead can be routed straight to that agent.
        $reference = $data['reference'] ?? null;
        [$agentCode, $propertyId] = $this->parseListingReference($reference);

        $routedAgent = $agentCode !== null
            ? $this->findAgentByCode($tenant->id, $agentCode)
            : null;

        $property = $this->matchProperty($integration, $reference)
            ?? $this->matchPropertyById($tenant->id, $propertyId);

        // A client who already exists (hand-typed or from an earlier portal lead)
        // is never duplicated: the new listing is added to their existing lead.
        $existing = $this->findExistingContact($tenant, $phone, $email);

        if ($existing !== null) {
            return $this->handleExistingClient($existing, $integration, $source, $property, $data, $phone, $email);
        }

        $notes = implode("\n", array_filter([
            $data['message'] ?? null,
            $data['url'] ?? null,
        ]));

        $lead = Lead::withoutGlobalScopes()->create([
            'tenant_id'   => $tenant->id,
            'agent_id'    => $routedAgent?->id,
            'first_name'  => $first,
            'last_name'   => $last,
            'phone'       => $phone,
            'email'       => $email !== '' ? $email : null,
            'lead_source' => $source,
            'status'      => 'new',
            'temperature' => 'warm',
            'notes'       => $notes !== '' ? $notes : null,
            'custom_fields' => array_filter([
                'portal'              => $integration->portal,
                'portal_reference'    => $data['id'] ?? null,
                'listing_reference'   => $data['reference'] ?? null,
                'listing_url'         => $data['url'] ?? null,
                'listing_property_id' => $property?->id,
                'contact_link'        => $data['contact_link'] ?? null,
                'received_at'         => $data['received_at'] ?? null,
            ]),
        ]);

        // No routeable agent: honour the tenant's portal-lead handling setting.
        if ($lead->agent_id === null) {
            $this->handleUnmatched($lead, $tenant);
        }

        $this->linkProperty($lead, $property);

        AuditLog::log('lead.received_from_portal_' . $source, $lead);

        return $lead;
    }

    /**
     * An inbound lead matched an existing contact. Decide whether it is a true
     * duplicate (same listing, recent) or a fresh/known opportunity.
     */
    protected function handleExistingClient(Lead $lead, PortalIntegration $integration, string $source, ?Property $property, array $data, ?string $phone, ?string $email): Lead
    {
        $sameListing = $property !== null && $lead->properties()->whereKey($property->id)->exists();
        $recent = $this->receivedRecently($data['received_at'] ?? null);

        // True duplicate: same listing, inside the duplicate window. Stay quiet.
        if ($sameListing && $recent) {
            return $lead;
        }

        // Returning client (or renewed interest in a listing they already saw):
        $this->attachOpportunity($lead, $integration, $source, $property, $data);

        return $lead;
    }

    /**
     * Refresh the existing lead as a new opportunity: attach the listing, mark
     * it as a returning inquiry, and alert the owning agent (or the admins when
     * the lead has no agent).
     */
    protected function attachOpportunity(Lead $lead, PortalIntegration $integration, string $source, ?Property $property, array $data): void
    {
        if ($property !== null) {
            $lead->properties()->syncWithoutDetaching([$property->id]);
        }

        $custom = $lead->custom_fields ?? [];

        $references = is_array($custom['opportunity_references'] ?? null)
            ? $custom['opportunity_references']
            : [];
        if (($data['reference'] ?? null) !== null) {
            $references[] = $data['reference'];
            $custom['opportunity_references'] = array_slice(array_values(array_unique($references)), -10);
        }

        $custom['returning_opportunity'] = true;
        $custom['latest_portal'] = $integration->portal;
        $custom['latest_received_at'] = $data['received_at'] ?? now()->toDateTimeString();

        $lead->status = 'new';
        $lead->temperature = 'warm';
        $lead->custom_fields = $custom;
        $lead->save();

        AuditLog::log('lead.returning_client_interest', $lead, null, [
            'portal'    => $integration->portal,
            'reference' => $data['reference'] ?? null,
            'property_id' => $property?->id,
        ]);

        $agent = $lead->agent;
        if ($agent !== null) {
            try {
                $agent->notify(new ReturningClientInterest($lead));
            } catch (\Throwable $e) {
                Log::warning('Returning-client notification failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        } elseif (($lead->tenant?->portalLeadSettings()['notify_admins'] ?? true)) {
            $this->notifyAdmins($lead, new ReturningClientInterest($lead));
        }
    }

    /**
     * Leaves a brand-new unassigned portal lead to the tenant's configured
     * handling: either push it into the routing pool or keep it unassigned and
     * alert the admins.
     */
    protected function handleUnmatched(Lead $lead, Tenant $tenant): void
    {
        $settings = $tenant->portalLeadSettings();

        if (($settings['unmatched'] ?? 'unassigned') === 'distribute') {
            try {
                app(LeadDistributionService::class)->distribute($lead, $tenant);
            } catch (\Throwable $e) {
                Log::warning('Portal lead distribution failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }

            return;
        }

        if (($settings['notify_admins'] ?? true)) {
            $this->notifyAdmins($lead, new PortalLeadUnclaimed($lead));
        }
    }

    protected function notifyAdmins(Lead $lead, object $notification): void
    {
        $tenant = $lead->tenant;
        if ($tenant === null) {
            return;
        }

        $tenant->users()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('name', 'admin'))
            ->get()
            ->each(function (User $admin) use ($notification, $lead) {
                try {
                    $admin->notify($notification);
                } catch (\Throwable $e) {
                    Log::warning('Portal lead admin notification failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
                }
            });
    }

    /**
     * A lead already exists with the same portal lead id: pure re-fetch.
     */
    protected function findByPortalReference(PortalIntegration $integration, string $portalReference): ?Lead
    {
        return Lead::withoutGlobalScopes()
            ->where('tenant_id', $integration->tenant_id)
            ->where('custom_fields->portal', $integration->portal)
            ->where('custom_fields->portal_reference', $portalReference)
            ->first();
    }

    /**
     * Database-level probe for an active agent carrying an agent code.
     */
    protected function findAgentByCode(int $tenantId, string $agentCode): ?User
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('agent_code', $agentCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find the existing client by normalized phone or email. Lazily ignores
     * formatting differences (spaces, dashes, country-code prefixes, casing).
     */
    protected function findExistingContact(Tenant $tenant, ?string $phone, ?string $email): ?Lead
    {
        $country = $tenant->country;
        $normalizer = app(ContactNormalizer::class);

        $match = null;

        Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('email'))
            ->orderBy('id')
            ->chunk(200, function ($chunk) use ($normalizer, $phone, $email, $country, &$match) {
                foreach ($chunk as $lead) {
                    if ($phone !== null && $normalizer->samePhone($phone, $lead->phone, $country)) {
                        $match = $lead;

                        continue;
                    }

                    if ($email !== null && $normalizer->sameEmail($email, $lead->email) && ($match === null || $lead->id > $match->id)) {
                        $match = $lead;
                    }
                }
            });

        return $match;
    }

    /**
     * Whether an inquiry happened inside the duplicate window.
     */
    protected function receivedRecently(?string $receivedAt): bool
    {
        try {
            $at = $receivedAt !== null ? Carbon::parse($receivedAt) : now();
        } catch (\Throwable) {
            $at = now();
        }

        return $at->gte(now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES));
    }

    /**
     * Attach the matched inventory unit to the lead (many-to-many).
     */
    protected function linkProperty(Lead $lead, ?Property $property): void
    {
        if ($property === null) {
            return;
        }

        $lead->properties()->syncWithoutDetaching([$property->id]);
    }

    protected function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return ['', ''];
        }

        $parts = explode(' ', $name);

        return [$parts[0], count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : ''];
    }

    protected function cleanPhone($phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '' || $phone === 'null') {
            return null;
        }

        if (str_contains($phone, 'y/')) {
            return null;
        }

        return $phone;
    }

    protected function matchProperty(PortalIntegration $integration, ?string $reference): ?Property
    {
        if (blank($reference)) {
            return null;
        }

        $prefix = $integration->portal;
        if ($prefix === 'propertyfinder') {
            $column = 'propertyfinder_listing_reference';
        } elseif ($prefix === 'bayut') {
            $column = 'bayut_listing_id';
        } else {
            $column = null;
        }

        if ($column !== null) {
            $property = Property::withoutGlobalScopes()
                ->where('tenant_id', $integration->tenant_id)
                ->where($column, $reference)
                ->first();
            if ($property) {
                return $property;
            }
        }

        if ($prefix === 'bayut') {
            return Property::withoutGlobalScopes()
                ->where('tenant_id', $integration->tenant_id)
                ->where('dubizzle_listing_reference', $reference)
                ->first();
        }

        return null;
    }

    /**
     * Extract the agent code and property id embedded in a listing reference.
     */
    protected function parseListingReference(?string $reference): array
    {
        if (is_string($reference) && preg_match('/^([A-Z]{2}\d{2})-(\d+)$/', $reference, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [null, null];
    }

    /**
     * Resolution fallback that connects a lead to its inventory unit from the
     * numeric property id carried inside a listing reference.
     */
    protected function matchPropertyById(?int $tenantId, ?int $propertyId): ?Property
    {
        if ($propertyId === null || $tenantId === null) {
            return null;
        }

        return Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $propertyId)
            ->first();
    }
}