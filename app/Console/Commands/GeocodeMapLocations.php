<?php

namespace App\Console\Commands;

use App\Services\MapLocationService;
use Illuminate\Console\Command;

class GeocodeMapLocations extends Command
{
    protected $signature = 'maps:geocode-locations {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Resolve coordinates for map_locations so the public share map can drop pins on every building';

    public function handle(MapLocationService $maps): int
    {
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $updated = $maps->backfillCoordinates($tenantId, function ($location, array $coords) {
            $this->info("  {$location->sub_community} → {$coords[0]},{$coords[1]}");
        });

        $this->info($updated > 0
            ? "Resolved coordinates for {$updated} building location(s)."
            : 'No building locations needed new coordinates.');

        return self::SUCCESS;
    }
}
