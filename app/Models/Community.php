<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A master community/destination (e.g. "Al Ryada", "Marjan Island").
 *
 * Buildings (map_locations) belong to a community via community_id; the
 * community/sub_community strings on properties stay as snapshots so existing
 * filters and portal pushes are untouched. The FK is the source of truth for
 * management (renames cascade through it).
 *
 * There is intentionally no TenantScope — like MapLocation and PortalIntegration,
 * tenancy is always an explicit `where('tenant_id', …)`.
 */
class Community extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'city',
    ];

    public function mapLocations(): HasMany
    {
        return $this->hasMany(MapLocation::class, 'community_id');
    }
}
