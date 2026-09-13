<?php

namespace App\Services\Portals;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\Property;
use App\Models\User;
use App\Services\LeadDistributionService;
use Illuminate\Support\Facades\Log;

class PortalLeadService
{
    public function createFromPayload(PortalIntegration $integration, string $source, array $data): ?Lead
    {
        $tenant = $integration->tenant;

        [$first, $last] = $this->splitName($data['name'] ?? '');
        $phone = $this->cleanPhone($data['phone'] ?? null);
        $email = trim((string) ($data['email'] ?? ''));

        if ($first === '' && $phone === null && $email === '') {
            return null;
        }

        // Listing references embed the owning agent's code ({AGENTCODE}-{PROPERTYID}),
        // so the lead can be routed straight to that agent.
        $reference = $data['reference'] ?? null;
        [$agentCode, $propertyId] = $this->parseListingReference($reference);

        $routedAgent = $agentCode !== null
            ? User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('agent_code', $agentCode)
                ->where('is_active', true)
                ->first()
            : null;

        $existing = null;
        if ($phone !== null) {
            $existing = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('phone', $phone)
                ->first();
        }
        if ($existing === null && $email !== '') {
            $existing = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('email', $email)
                ->first();
        }

        $property = $this->matchProperty($integration, $reference)
            ?? $this->matchPropertyById($tenant->id, $propertyId);

        if ($existing !== null) {
            $this->linkProperty($existing, $property);

            return $existing;
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
                'portal'             => $integration->portal,
                'portal_reference'   => $data['id'] ?? null,
                'listing_reference'  => $data['reference'] ?? null,
                'listing_url'        => $data['url'] ?? null,
                'listing_property_id' => $property?->id,
                'contact_link'       => $data['contact_link'] ?? null,
                'received_at'        => $data['received_at'] ?? null,
            ]),
        ]);

        // A lead routed by its listing reference is already owned; only the
        // leftover, unowned leads go through the tenant's distribution pool.
        if ($lead->agent_id === null) {
            try {
                app(LeadDistributionService::class)->distribute($lead, $tenant);
            } catch (\Throwable $e) {
                Log::warning('Portal lead distribution failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        $this->linkProperty($lead, $property);

        AuditLog::log('lead.received_from_portal_' . $source, $lead);

        return $lead;
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