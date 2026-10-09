<?php

namespace App\Services\Portals;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeadAssigned;
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

    private ?PortalLinkResolver $links = null;

    /**
     * Classifies the two portal URLs a payload may carry (property page vs
     * message thread) so neither is ever filed under the other's heading.
     */
    public function links(): PortalLinkResolver
    {
        return $this->links ??= app(PortalLinkResolver::class);
    }

    public function createFromPayload(PortalIntegration $integration, string $source, array $data): ?Lead
    {
        $tenant = $integration->tenant;

        [$first, $last] = $this->splitName($data['name'] ?? '');
        $phone = $this->canonicalPhone($data['phone'] ?? null, $tenant);
        $email = trim((string) ($data['email'] ?? ''));

        if ($phone === null && $email === '') {
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

        // The recorded listing_reference on the inventory unit is checked first.
        // A listing created manually on Property Finder carries no agent code,
        // so the unit's own reference is the only thing that can route it — and
        // its owner must win before any other routing gets a chance to run.
        $reference = $data['reference'] ?? null;

        $property = $this->matchProperty($integration, $reference);

        [$agentCode, $propertyId] = $this->parseListingReference($reference);

        $property ??= $this->matchPropertyById($tenant->id, $propertyId);

        $unitService = app(\App\Services\UnitLeadAssignmentService::class);
        $unitOwner = $unitService->unitOwner($property, $tenant->id);

        // Listing references also embed the owning agent's code
        // ({AGENTCODE}-{PROPERTYID}), which is only consulted as a fallback.
        $routedAgent = $agentCode !== null
            ? $this->findAgentByCode($tenant->id, $agentCode)
            : null;

        // A client who already exists (hand-typed or from an earlier portal lead)
        // is never duplicated: the new listing is added to their existing lead.
        $existing = $this->findExistingContact($tenant, $phone, $email);

        if ($existing !== null) {
            return $this->handleExistingClient($existing, $integration, $source, $property, $data, $phone, $email);
        }

        // The listing/enquiry URL is stored in custom_fields (listing_url and
        // contact_link) and rendered as the clickable "Portal Listing" block on
        // the lead page. Appending it to notes as well used to show the same
        // link three times on one screen, so the notes carry the client's
        // message only.
        $notes = implode("\n", array_filter([
            $data['message'] ?? null,
        ]));

        // A portal payload can carry both a property page and a message thread,
        // and Property Finder sends the message URL for both. Classify them so
        // "Listing" opens the listing and "WhatsApp" opens the conversation.
        $links = $this->links()->resolve(
            [$data['url'] ?? null, $data['contact_link'] ?? null],
            $integration->portal
        );

        // The unit owner takes the lead outright: it beats the agent code the
        // portal carried, and being already assigned it also keeps the lead out
        // of pool distribution below.
        $lead = Lead::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'agent_id' => $unitOwner?->id ?? $routedAgent?->id,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'lead_source' => $source,
            'status' => 'new',
            'temperature' => 'warm',
            'notes' => $notes !== '' ? $notes : null,
            'custom_fields' => array_filter([
                'portal' => $integration->portal,
                'portal_reference' => $data['id'] ?? null,
                'listing_reference' => $data['reference'] ?? null,
                'listing_url' => $links['listing_url'],
                'listing_property_id' => $property?->id,
                'contact_link' => $links['contact_link'],
                'received_at' => $data['received_at'] ?? null,
            ]),
        ]);

        // No routeable agent: honour the tenant's portal-lead handling setting.
        if ($lead->agent_id === null) {
            $this->handleUnmatched($lead, $tenant);
        }

        // Link the unit before anyone is emailed, so the notification the
        // owner receives points at a lead that already shows its listing.
        // Ownership was settled above (agent_id is already the unit owner when
        // one applies), so there is nothing left to reassign here.
        $this->linkProperty($lead, $property);

        // New-lead notification: email the agent the lead was assigned to
        // (unit owner, routed, or distributed), otherwise email the Owner so an
        // unassigned inbound lead never lingers silently.
        $this->notifyNewLeadRouting($lead, $tenant);

        // Record the assignment itself: AssignmentHistoryService reads audit
        // rows carrying agent_id in new_values, and a portal lead never passed
        // through the form that writes those.
        if ($lead->agent_id !== null) {
            AuditLog::log('lead.updated', $lead, null, [
                'agent_id' => $lead->agent_id,
                'previous_agent_id' => $routedAgent?->id,
                'property_id' => $property?->id,
                'reason' => $unitOwner !== null ? 'unit_owner' : 'portal_reference',
            ]);
        }

        AuditLog::log('lead.received_from_portal_'.$source, $lead);

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
            app(\App\Services\UnitLeadAssignmentService::class)->assignToUnitOwnerIfRequired($lead, $property);
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
            'portal' => $integration->portal,
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
     * handling: either push it into the routing pool or keep it unassigned.
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
        }
    }

    /**
     * Notify on a brand-new inbound portal lead: the assigned agent gets the
     * lead-assigned email, or when the lead stayed unassigned the Owner(s) are
     * emailed instead (tenant-level notify_admins applies).
     */
    protected function notifyNewLeadRouting(Lead $lead, Tenant $tenant): void
    {
        $lead->refresh();

        $agent = $lead->agent;

        if ($agent !== null) {
            if ($tenant->wantsNotification('lead_assigned')) {
                try {
                    $agent->notify(new LeadAssigned($lead, $tenant));
                } catch (\Throwable $e) {
                    Log::warning('Portal lead assignment notification failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
                }
            }

            return;
        }

        if (($tenant->portalLeadSettings()['notify_admins'] ?? true)) {
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
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin']))
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
     *
     * A member who has switched off receiving leads is deliberately not matched
     * here either: their listings still route enquiries by agent code, so
     * without the opt-out the listing-based route would hand them portal leads
     * behind the distribution formula's back. The lead then falls through to
     * handleUnmatched() and is distributed to somebody who is in the rotation.
     */
    protected function findAgentByCode(int $tenantId, string $agentCode): ?User
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('agent_code', $agentCode)
            ->where('is_active', true)
            ->where('receives_leads', true)
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

    /**
     * Canonical stored phone for a portal lead. However the portal formatted
     * it ("+971 529 603 039", "971529603039", "052960603039", "00 971 …"), it
     * lands as the same E.164-style "+971529603039" that the share-link form
     * stores, so the same client cannot exist twice just because one row kept
     * the separators and the other did not. The digits-only form is what
     * findExistingContact() compares on either way; this is just the one
     * storage shape for every number.
     */
    protected function canonicalPhone($phone, Tenant $tenant): ?string
    {
        $phone = $this->cleanPhone($phone);

        if ($phone === null) {
            return null;
        }

        $digits = app(ContactNormalizer::class)->phone($phone, $tenant->country);

        return $digits !== null ? '+'.$digits : null;
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
     *
     * Accepts both the current two-letter codes (AJ-42) and the legacy
     * four-character codes (AJ07-42) used by listings published before the
     * two-letter changeover.
     */
    protected function parseListingReference(?string $reference): array
    {
        if (is_string($reference) && preg_match('/^([A-Z]{2}\d{2})-(\d+)$/', $reference, $m)) {
            return [$m[1], (int) $m[2]];
        }

        if (is_string($reference) && preg_match('/^([A-Z]{2})-(\d+)$/', $reference, $m)) {
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
