<?php

namespace App\Services;

use App\Models\Owner;
use App\Models\Property;

/**
 * Owners (landlords) master, keyed per tenant.
 *
 * The unit form once stored the owner as free text (owner_name/phone/email),
 * so the same person was retyped on every unit and drifted. Owners are now
 * stored once and linked by FK; the strings on units stay snapshots.
 *
 * De-dup is normalized-exact by name (case/whitespace-insensitive), with the
 * canonical phone as a secondary key, mirroring how buildings are de-duplicated
 * in MapLocationService. Richer data wins: a re-create never overwrites a field
 * a human already filled, and a corrected spelling folds onto the existing row.
 *
 * There is intentionally no TenantScope on Owner — tenancy is always an
 * explicit `where('tenant_id', …)`.
 */
class OwnerService
{
    public function __construct(
        private MapLocationService $maps,
        private ContactNormalizer $contacts,
    ) {}

    public function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strtolower($name);
    }

    /**
     * Digits-only canonical form used to match phone numbers regardless of
     * spacing/dashes/trunk-zero differences.
     */
    public function canonicalPhone(?string $phone): ?string
    {
        return $this->contacts->phone((string) $phone);
    }

    public function findByName(int $tenantId, string $name): ?Owner
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $normalized = $this->normalizeName($name);

        foreach (Owner::where('tenant_id', $tenantId)->get(['id', 'name']) as $owner) {
            if ($this->normalizeName((string) $owner->name) === $normalized) {
                return Owner::where('tenant_id', $tenantId)->find($owner->id);
            }
        }

        return null;
    }

    public function findByPhone(int $tenantId, ?string $phone): ?Owner
    {
        $needle = $this->canonicalPhone($phone);
        if ($needle === null) {
            return null;
        }

        foreach (Owner::where('tenant_id', $tenantId)->get(['id', 'phone']) as $owner) {
            if ($this->canonicalPhone($owner->phone) === $needle) {
                return Owner::where('tenant_id', $tenantId)->find($owner->id);
            }
        }

        return null;
    }

    /**
     * Find (or create) the owner a form is describing.
     *
     * De-dup is normalized-exact on the name; when the name is blank the phone
     * identifies the owner. An existing row is topped up with any field it is
     * still missing — richer data wins, weaker never overwrites.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, office_address?: ?string}  $data
     * @return array{owner: Owner, reused: bool}
     */
    public function findOrCreate(int $tenantId, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = $this->clean($data['phone'] ?? null);
        $email = $this->clean($data['email'] ?? null);
        $officeAddress = $this->clean($data['office_address'] ?? null);

        $existing = $name !== '' ? $this->findByName($tenantId, $name) : null;
        if (! $existing && $phone !== null) {
            $existing = $this->findByPhone($tenantId, $phone);
        }

        if ($existing) {
            $updates = [];
            if (($existing->name === null || $existing->name === '') && $name !== '') {
                $updates['name'] = $name;
            }
            if (! $existing->phone && $phone !== null) {
                $updates['phone'] = $phone;
            }
            if (! $existing->email && $email !== null) {
                $updates['email'] = $email;
            }
            if (! $existing->office_address && $officeAddress !== null) {
                $updates['office_address'] = $officeAddress;
            }
            if ($updates) {
                $existing->update($updates);
            }

            return ['owner' => $existing, 'reused' => true];
        }

        $owner = Owner::create([
            'tenant_id' => $tenantId,
            'name' => $name !== '' ? $name : ($officeAddress ?: 'Owner'),
            'phone' => $phone,
            'email' => $email,
            'office_address' => $officeAddress,
        ]);

        return ['owner' => $owner, 'reused' => false];
    }

    /**
     * Store the office location from a pasted Google Maps link or a typed
     * address, then resolve a map pin. Mirrors MapLocationService's handling so
     * "view on map", "get directions" and Waze all work from the owner record.
     * A blank input is ignored (never clobbers an existing location).
     */
    public function setOfficeLocation(Owner $owner, ?string $input): void
    {
        $input = trim((string) $input);
        if ($input === '') {
            return;
        }

        $clean = $this->maps->cleanUrl($input);
        $query = $owner->office_address ?: ($owner->name ?: null);

        if ($clean !== null) {
            $owner->map_url = $clean;
            $owner->map_query = $clean === $input ? $query : $input;
        } else {
            $owner->map_url = $this->maps->searchUrl($input);
            $owner->map_query = $input;
        }
        $owner->save();

        $coords = $this->maps->coordsFromUrl($owner->map_url)
            ?? $this->maps->geocode((string) ($owner->map_query ?: $owner->office_address));
        if ($coords !== null) {
            $owner->updateQuietly(['latitude' => $coords[0], 'longitude' => $coords[1]]);
        }
    }

    /**
     * Copy the owner's details onto every linked unit's snapshot columns, so
     * the inventory search and offer letters (which read owner_name) stay in
     * step after an edit. Returns the number of units updated.
     */
    public function cascadeToUnits(Owner $owner): int
    {
        return Property::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->where('owner_id', $owner->id)
            ->update([
                'owner_name' => $owner->name,
                'owner_phone' => $owner->phone,
                'owner_email' => $owner->email,
            ]);
    }

    /**
     * Adopt units that still carry this owner's name as free text (never linked
     * to an owner row) so an existing inventory folds onto the master without a
     * re-import. Returns the number of units linked.
     */
    public function linkUnitsByName(Owner $owner): int
    {
        $name = trim((string) $owner->name);
        if ($name === '') {
            return 0;
        }

        return Property::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereNull('owner_id')
            ->whereRaw('LOWER(owner_name) = ?', [mb_strtolower($name)])
            ->update(['owner_id' => $owner->id]);
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
