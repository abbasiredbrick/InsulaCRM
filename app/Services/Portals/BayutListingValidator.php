<?php

namespace App\Services\Portals;

use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Checks inventory records against the mandatory Bayut listing fields. The
 * rule set mirrors what {@see BayutPortalService::payload()} sends, so a unit
 * that passes here is publishable without hacks.
 */
class BayutListingValidator
{
    /**
     * Which availability states the readiness report should cover.
     */
    public const SCOPED_AVAILABILITIES = ['ready_to_list', 'listed'];

    /**
     * Default tenant when none is supplied (CLI / admin view).
     */
    private ?Tenant $fallbackTenant = null;

    public function __construct(?Tenant $tenant = null)
    {
        $this->fallbackTenant = $tenant ?? (auth()->check() ? auth()->user()->tenant : null);
    }

    /**
     * Ordered list of mandatory fields mapped from the Bayut push payload.
     *
     * @return array<int, array{key: string, label: string, test: \Closure}>
     */
    public function specs(): array
    {
        return [
            ['key' => 'intent', 'label' => 'Purpose (Rent / Sale)', 'test' => fn (Property $p): bool => in_array($p->intent, ['rent', 'sale', 'both'], true)],
            ['key' => 'property_category', 'label' => 'Property Type', 'test' => fn (Property $p): bool => ! blank($p->property_category)],
            ['key' => 'rera_permit_no', 'label' => 'RERA Permit No.', 'test' => fn (Property $p): bool => ! blank($p->rera_permit_no)],
            ['key' => 'marketing_title', 'label' => 'Marketing Title', 'test' => fn (Property $p): bool => ! blank($p->marketing_title)],
            ['key' => 'marketing_description', 'label' => 'Marketing Description', 'test' => fn (Property $p): bool => ! blank($p->marketing_description)],
            ['key' => 'price', 'label' => 'Listed Price', 'test' => fn (Property $p): bool => $p->sale_price !== null || $p->rent_advertised_price !== null],
            ['key' => 'location', 'label' => 'Location (City / Community)', 'test' => fn (Property $p): bool => ! blank($p->city) || ! blank($p->community)],
            ['key' => 'area', 'label' => 'Area (Sub-Community / Community)', 'test' => fn (Property $p): bool => ! blank($p->sub_community) || ! blank($p->community)],
            ['key' => 'address', 'label' => 'Address', 'test' => fn (Property $p): bool => ! blank($p->address)],
            ['key' => 'bedrooms', 'label' => 'Bedrooms', 'test' => fn (Property $p): bool => $p->bedrooms !== null],
            ['key' => 'bathrooms', 'label' => 'Bathrooms', 'test' => fn (Property $p): bool => $p->bathrooms !== null],
            ['key' => 'square_footage', 'label' => 'Size (sqft)', 'test' => fn (Property $p): bool => ! blank($p->square_footage)],
            ['key' => 'furnishing', 'label' => 'Furnishing', 'test' => fn (Property $p): bool => ! blank($p->furnishing)],
            ['key' => 'parking', 'label' => 'Parking', 'test' => fn (Property $p): bool => $p->parking !== null],
            ['key' => 'photos', 'label' => 'Photos', 'test' => fn (Property $p): bool => (count($p->photo_urls)) > 0],
            ['key' => 'agent', 'label' => 'Listing Agent (with agent code)', 'test' => fn (Property $p): bool => $p->assigned_agent_id !== null && $p->assignedAgent?->agent_code !== null],
        ];
    }

    /**
     * List of missing mandatory fields for a single unit.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function missing(Property $property): array
    {
        $missing = [];

        foreach ($this->specs() as $spec) {
            if (! $spec['test']($property)) {
                $missing[] = ['key' => $spec['key'], 'label' => $spec['label']];
            }
        }

        return $missing;
    }

    public function isReady(Property $property): bool
    {
        return count($this->missing($property)) === 0;
    }

    public function report(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->fallbackTenant;
        if ($tenant === null) {
            return ['total' => 0, 'ready' => 0, 'blocked' => 0, 'rows' => collect(), 'miss_by_key' => collect()];
        }

        $units = Property::withoutGlobalScopes()
            ->with(['assignedAgent', 'media'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('availability', self::SCOPED_AVAILABILITIES)
            ->orderBy('id')
            ->get();

        $rows = $units->map(fn (Property $p) => [
            'property' => $p,
            'missing'  => $this->missing($p),
        ]);

        $ready = 0;
        $missByKey = [];

        foreach ($rows as $row) {
            if (count($row['missing']) === 0) {
                $ready++;
                continue;
            }

            foreach ($row['missing'] as $miss) {
                $missByKey[$miss['key']] = ($missByKey[$miss['key']] ?? 0) + 1;
            }
        }

        arsort($missByKey);

        return [
            'total'       => $units->count(),
            'ready'       => $ready,
            'blocked'     => $units->count() - $ready,
            'rows'        => $rows,
            'miss_by_key' => collect($missByKey),
            'specs'       => collect($this->specs()),
        ];
    }
}