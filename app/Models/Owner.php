<?php

namespace App\Models;

use App\Services\MapLocationService;
use Illuminate\Database\Eloquent\Model;

/**
 * A property owner / landlord (the "Owner" on a unit).
 *
 * Like MapLocation and Community, an owner is mastered once per tenant and
 * units point at it through properties.owner_id; the owner_name/phone/email
 * columns on the unit stay snapshots so existing filters and the offer letter
 * keep working. Owners carry the office details (address + a pinned map
 * location) used to find the office and drive to a meeting (Google or Waze).
 *
 * There is intentionally no TenantScope — tenancy is an explicit
 * `where('tenant_id', …)`. There is also no unique constraint on the name:
 * two people can share a name, so de-dup is normalized-exact in OwnerService.
 */
class Owner extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'office_address',
        'map_url',
        'map_query',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Units (properties) attached to this owner through the FK. The owner_name/
     * phone/email strings on those units stay snapshots; edits cascade through
     * this link.
     */
    public function properties(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Property::class, 'owner_id');
    }

    /**
     * The text used for map search/directions when the stored link carries no
     * usable query: the resolved query, else the typed office address, else the
     * owner name.
     */
    public function navigationQuery(): ?string
    {
        $query = trim((string) ($this->map_query ?: $this->office_address ?: $this->name));

        return $query === '' ? null : $query;
    }

    /** Clickable Google Maps link for the office (stored link wins). */
    public function mapsSearchUrl(): ?string
    {
        return $this->map_url ?: app(MapLocationService::class)->searchUrl($this->navigationQuery());
    }

    /** "Get directions" link (Google Maps) to the office. */
    public function mapsDirectionsUrl(): ?string
    {
        return app(MapLocationService::class)->directionsUrl($this->navigationQuery());
    }

    /** Waze driving link to the office (pinned coordinates preferred). */
    public function wazeUrl(): ?string
    {
        return app(MapLocationService::class)->wazeUrlFor($this->latitude, $this->longitude, $this->navigationQuery());
    }
}
