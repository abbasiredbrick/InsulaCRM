<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Google Maps location for one building (sub_community).
 *
 * Stored once per building rather than per unit: every unit under a
 * sub_community points at the same place. Entries are created on availability
 * import (first-write-wins, normalized exact de-dup) and corrected from
 * Settings → Locations.
 *
 * There is intentionally no TenantScope — like PortalIntegration, tenancy is
 * always an explicit `where('tenant_id', …)`.
 */
class MapLocation extends Model
{
    protected $fillable = [
        'tenant_id',
        'community_id',
        'sub_community',
        'community',
        'city',
        'map_url',
        'map_query',
        'latitude',
        'longitude',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    /**
     * Units (properties) attached to this building through the FK. Strings on
     * those units stay snapshots; renames/merges cascade through this link.
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'map_location_id');
    }
}
