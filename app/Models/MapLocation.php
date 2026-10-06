<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Google Maps location for one building (sub_community).
 *
 * Stored once per building rather than per unit: every unit under a
 * sub_community points at the same place. Entries are created on availability
 * import (first-write-wins) and corrected from Settings → Map Locations.
 *
 * There is intentionally no TenantScope — like PortalIntegration, tenancy is
 * always an explicit `where('tenant_id', …)`.
 */
class MapLocation extends Model
{
    protected $fillable = [
        'tenant_id',
        'sub_community',
        'community',
        'city',
        'map_url',
        'map_query',
    ];
}
