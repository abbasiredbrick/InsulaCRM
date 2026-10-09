<?php

namespace App\Services;

use App\Models\Community;
use App\Models\MapLocation;
use App\Models\Property;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Location/map links for inventory, keyed per building (sub_community).
 *
 * Each building has one Google Maps "search" URL (map_url) plus the
 * human-readable text it was resolved from (map_query). Sources that publish a
 * real link (Reelam column, AY/RDK pre-table URL, a manual entry) keep it
 * verbatim; sources whose cells only hold unreadable Excel hyperlinks (AMS)
 * fall back to a generated Google Maps search link built from building/area/
 * city, so every unit still has somewhere to point the visitor. The same query
 * drives the keyless embedded map and the "View on map" link on the public
 * share page. Entries live in map_locations (one row per building) and are
 * corrected from Settings → Map Locations.
 */
class MapLocationService
{
    const SEARCH_BASE = 'https://www.google.com/maps/search/?api=1&query=';

    const DIRECTIONS_BASE = 'https://www.google.com/maps/dir/?api=1&destination=';

    const EMBED_BASE = 'https://maps.google.com/maps?z=16&q=';

    const EMBED_SUFFIX = '&output=embed';

    // Waze opens the visitor's own driving app from wherever they are.
    const WAZE_BASE = 'https://www.waze.com/ul';

    // OpenStreetMap Nominatim geocoder (keyless). Respect its usage policy: a
    // proper User-Agent and at most ~1 request/sec when backfilling.
    const GEOCODE_BASE = 'https://nominatim.openstreetmap.org/search';

    /**
     * Strip surrounding whitespace and return the value only when it is a
     * real http(s) link. A bare host ("maps.google.com/...") is promoted to
     * https; anything with a non-http scheme is rejected.
     */
    public function cleanUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '://') === false
            && preg_match('~^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}(?:[/?#]|$)~i', $value)) {
            $value = 'https://'.$value;
        }
        if (! preg_match('#^https?://#i', $value)) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $value;
    }

    /**
     * Join building/area/city segments into a Google-Maps-ready query.
     * "Other" buildings (the ingest fallback placeholder) carry no location
     * and are dropped, as are empty and duplicated segments.
     *
     * @param  array<int, mixed>  $parts
     */
    public function queryFor(array $parts): string
    {
        $seen = [];
        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value === '' || strtolower($value) === 'other') {
                continue;
            }
            if (isset($seen[mb_strtolower($value)])) {
                continue;
            }
            $seen[mb_strtolower($value)] = true;
            $clean[] = $value;
        }

        return implode(', ', $clean);
    }

    public function queryForLocation(MapLocation $location): string
    {
        return $this->queryFor([
            $location->sub_community,
            $location->community,
            $location->city,
        ]);
    }

    /**
     * The clickable link for a location: an explicit link when one was stored,
     * otherwise a Google Maps search built from the query text.
     */
    public function urlFor(MapLocation $location): ?string
    {
        return $location->map_url ?: $this->searchUrl($location->map_query);
    }

    public function searchUrl(?string $query): ?string
    {
        $query = trim((string) $query);

        return $query === '' ? null : self::SEARCH_BASE.rawurlencode($query);
    }

    public function directionsUrl(?string $query): ?string
    {
        $query = trim((string) $query);

        return $query === '' ? null : self::DIRECTIONS_BASE.rawurlencode($query);
    }

    public function embedUrl(?string $query, ?string $key = null): ?string
    {
        $query = trim((string) $query);
        if ($query === '') {
            return null;
        }

        // Deliberately keyless: the official /embed/v1/place endpoint returns a
        // 403 (a blank iframe) for any key that is not fully enabled and
        // referrer-whitelisted in Google Cloud, whereas the legacy
        // output=embed endpoint needs no key, no API enablement and no referrer
        // config. A visible map beats a gray box, so any shared key is ignored.
        return self::EMBED_BASE.rawurlencode($query).self::EMBED_SUFFIX;
    }

    /**
     * Driving link for a building. Waze starts navigating from wherever the
     * visitor is; pinned coordinates are preferred, otherwise the query text.
     */
    public function wazeUrl(MapLocation $location): ?string
    {
        if ($location->latitude !== null && $location->longitude !== null) {
            return self::WAZE_BASE.'?ll='.rtrim(rtrim(sprintf('%.7f', $location->latitude), '0'), '.').','.rtrim(rtrim(sprintf('%.7f', $location->longitude), '0'), '.').'&navigate=yes';
        }

        $query = trim((string) ($location->map_query ?: $this->queryForLocation($location)));

        return $query === '' ? null : self::WAZE_BASE.'?q='.rawurlencode($query).'&navigate=yes';
    }

    /**
     * Pull decimal coordinates out of a Google Maps place/share URL when the
     * link itself carries them (a pinned search like @25.20,55.27,17z or a
     * !3d..!4d payload). Returns [lat, lng] or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public function coordsFromUrl(?string $url): ?array
    {
        $url = (string) $url;
        if ($url === '') {
            return null;
        }

        $match = [];

        // @25.2048,55.2708,17z (path or query; also @lat,lng as a bare pair)
        if (preg_match('~@([+-]?\d{1,3}\.\d+),([+-]?\d{1,3}\.\d+)~', $url, $match)) {
            return [(float) $match[1], (float) $match[2]];
        }

        // !3d25.2048!4d55.2708 (the binary "data=" payload used by share links)
        if (preg_match('~!3d([+-]?\d{1,3}\.\d+)!4d([+-]?\d{1,3}\.\d+)~', $url, $match)) {
            return [(float) $match[1], (float) $match[2]];
        }

        // ll=25.2048,55.2708 / center=... / q=lat,lng
        if (preg_match('~(?:ll|center|destination)=([+-]?\d{1,3}\.\d+),([+-]?\d{1,3}\.\d+)~', $url, $match)) {
            return [(float) $match[1], (float) $match[2]];
        }

        return null;
    }

    /**
     * Resolve coordinates for a building: stored coordinates win, then any the
     * link embeds, then a fresh Nominatim geocode of the query text. Stored
     * coordinates never get overwritten.
     *
     * @return array{0: float, 1: float}|null
     */
    public function resolveCoordinates(MapLocation $location): ?array
    {
        if ($location->latitude !== null && $location->longitude !== null) {
            return [(float) $location->latitude, (float) $location->longitude];
        }

        $coords = $this->coordsFromUrl($location->map_url) ?? $this->geocode($location->map_query ?: $this->queryForLocation($location));

        if ($coords !== null) {
            $location->updateQuietly(['latitude' => $coords[0], 'longitude' => $coords[1]]);
        }

        return $coords;
    }

    /**
     * Keyless location lookup against OpenStreetMap Nominatim. Results are
     * cached for a month so repeated builds/imports do not hammer the service.
     *
     * @return array{0: float, 1: float}|null
     */
    public function geocode(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        $cacheKey = 'map-geocode-'.sha1($query);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($query) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['User-Agent' => 'KeystoneCRM/1.0 ('.config('app.url').'; map pins)'])
                    ->timeout(8)
                    ->get(self::GEOCODE_BASE, [
                        'q' => $query,
                        'format' => 'json',
                        'limit' => 1,
                    ]);

                $data = $response->json();

                if (! $response->ok() || empty($data) || ! isset($data[0]['lat'], $data[0]['lon'])) {
                    return null;
                }

                return [(float) $data[0]['lat'], (float) $data[0]['lon']];
            } catch (\Throwable $e) {
                Log::warning('Map geocode failed', ['query' => $query, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Backfill missing coordinates. Returns the number of buildings that
     * gained them. Sleeps 1s before each uncached geocode call to respect the
     * Nominatim usage policy (never more than ~1 request per second).
     */
    public function backfillCoordinates(?int $tenantId = null, ?callable $onUpdated = null): int
    {
        $query = MapLocation::withoutGlobalScopes()->where(function ($q) {
            $q->whereNull('latitude')->orWhereNull('longitude');
        });

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $updated = 0;
        $calls = 0;

        foreach ($query->get() as $location) {
            if ($location->latitude !== null && $location->longitude !== null) {
                continue;
            }

            // A link-embedded pair needs no HTTP call; skip the rate-limit pause.
            if ($this->coordsFromUrl($location->map_url) === null) {
                if ($calls > 0 && ! app()->runningUnitTests()) {
                    sleep(1);
                }
                $calls++;
            }

            $resolved = $this->resolveCoordinates($location);
            if ($resolved !== null) {
                $updated++;
                if ($onUpdated !== null) {
                    $onUpdated($location, $resolved);
                }
            }
        }

        return $updated;
    }

    public function isHttpUrl(?string $value): bool
    {
        return $this->cleanUrl($value) !== null;
    }

    /**
     * Normalized building/community name used for de-dup: lower-cased, trimmed,
     * internal whitespace collapsed. "AL ARYAM " and "al  aryam" are the same
     * building; "Al Aryam" and "Al Aryam Tower" are deliberately different (the
     * latter is fixed by a rename, never silently, and merging is a review action).
     */
    public function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strtolower($name);
    }

    /**
     * Find the tenant's building location for a name, exact first then a
     * normalized (case/whitespace-insensitive) match. Two spellings that
     * normalize differently stay two buildings until a human merges them.
     */
    public function findByName(int $tenantId, string $name): ?MapLocation
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $normalized = $this->normalizeName($name);

        foreach (MapLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->get(['id', 'sub_community']) as $location) {
            if ($this->normalizeName((string) $location->sub_community) === $normalized) {
                return $location;
            }
        }

        return null;
    }

    /**
     * Create the location entry for a building the first time it is seen.
     * Re-imports never duplicate: the normalized name is matched first, so a
     * corrected spelling in a newer sheet is folded back onto the existing
     * row (and its human-corrected link) instead of creating a second one.
     * Returns the single location for the building.
     */
    public function ensureMapLocation(int $tenantId, string $subCommunity, ?string $community, ?string $city, ?string $sourceUrl): ?MapLocation
    {
        $existing = $this->findByName($tenantId, $subCommunity);
        if ($existing) {
            return $existing;
        }

        $query = $this->queryFor([$subCommunity, $community, $city]);
        $url = $this->cleanUrl($sourceUrl) ?: $this->searchUrl($query);

        return MapLocation::create([
            'tenant_id' => $tenantId,
            'sub_community' => trim($subCommunity),
            'community' => $community !== null && $community !== '' ? $community : null,
            'city' => $city !== null && $city !== '' ? $city : null,
            'map_url' => $url,
            'map_query' => $query !== '' ? $query : null,
        ]);
    }

    /**
     * Point every unit of a building at its (single) map location row. Called
     * after an import or manual save so properties.map_location_id — the FK
     * that drives renames and merges — always follows the building name.
     */
    public function linkBuildingUnits(int $tenantId, string $building, MapLocation $location): int
    {
        return Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sub_community', $building)
            ->update(['map_location_id' => $location->id]);
    }

    /**
     * Rename a building, cascading to every unit carrying the old name. When
     * the new name already belongs to another building the call returns
     * ['conflict' => true, 'target' => ...] and changes nothing — collapsing the
     * two is a merge decision, not a silent rename.
     *
     * @return array{location?: MapLocation, renamed?: int, legacy?: int, conflict?: bool, target?: ?MapLocation, error?: string}
     */
    public function rename(MapLocation $location, string $newName): array
    {
        $newName = trim((string) $newName);
        if ($newName === '') {
            return ['error' => __('The building name cannot be empty.')];
        }

        $target = $this->findByName($location->tenant_id, $newName);
        if ($target && $target->id !== $location->id) {
            return ['conflict' => true, 'target' => $target, 'location' => $location];
        }

        $oldName = $location->sub_community;
        $location->update(['sub_community' => $newName]);

        $linked = Property::withoutGlobalScopes()
            ->where('tenant_id', $location->tenant_id)
            ->where('map_location_id', $location->id)
            ->update(['sub_community' => $newName]);

        $legacy = Property::withoutGlobalScopes()
            ->where('tenant_id', $location->tenant_id)
            ->whereNull('map_location_id')
            ->whereRaw('LOWER(sub_community) = ?', [mb_strtolower(trim((string) $oldName))])
            ->update(['sub_community' => $newName, 'map_location_id' => $location->id]);

        return ['location' => $location, 'renamed' => $linked + $legacy, 'legacy' => $legacy];
    }

    /**
     * Fold one building into another: units are re-pointed at the kept
     * location (the kept row wins on links/coordinates), legacy unlinked
     * units are re-pointed by name, then the discarded row is deleted.
     *
     * @return array{kept?: MapLocation, moved?: int, legacy?: int, error?: string}
     */
    public function merge(int $tenantId, int $keepId, int $discardId): array
    {
        $keep = MapLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($keepId);
        $discard = MapLocation::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($discardId);

        if (! $keep || ! $discard || $keep->id === $discard->id) {
            return ['error' => __('Pick two different buildings to merge.')];
        }

        if ($keep->map_url === null && $discard->map_url !== null) {
            $keep->updateQuietly(['map_url' => $discard->map_url, 'map_query' => $discard->map_query]);
        }
        if ($keep->latitude === null && $discard->latitude !== null) {
            $keep->updateQuietly(['latitude' => $discard->latitude, 'longitude' => $discard->longitude]);
        }
        if ($keep->community_id === null && $discard->community_id !== null) {
            $keep->updateQuietly(['community_id' => $discard->community_id, 'community' => $discard->community, 'city' => $discard->city]);
        }

        $moved = Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('map_location_id', $discard->id)
            ->update(['map_location_id' => $keep->id, 'sub_community' => $keep->sub_community]);

        $legacy = Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('map_location_id')
            ->whereRaw('LOWER(sub_community) = ?', [mb_strtolower(trim((string) $discard->sub_community))])
            ->update(['map_location_id' => $keep->id, 'sub_community' => $keep->sub_community]);

        $discard->delete();

        return ['kept' => $keep, 'moved' => $moved + $legacy, 'legacy' => $legacy];
    }

    /**
     * Fold one community into another: its buildings are re-pointed at the
     * kept community, the kept name is stamped onto every building and unit
     * snapshot of the discarded community, then the discarded community row is
     * deleted. "Al Reem Island" and "Reem Island" become one.
     *
     * @return array{kept?: Community, discarded_name?: string, buildings?: int, units?: int, error?: string}
     */
    public function mergeCommunities(int $tenantId, int $keepId, int $discardId): array
    {
        $keep = Community::where('tenant_id', $tenantId)->find($keepId);
        $discard = Community::where('tenant_id', $tenantId)->find($discardId);

        if (! $keep || ! $discard || $keep->id === $discard->id) {
            return ['error' => __('Pick two different communities to merge.')];
        }

        if (($keep->city === null || $keep->city === '') && $discard->city !== null && $discard->city !== '') {
            $keep->updateQuietly(['city' => $discard->city]);
        }

        $oldName = (string) $discard->name;

        // Buildings explicitly grouped under the discarded community.
        $buildings = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('community_id', $discard->id)
            ->update(['community_id' => $keep->id, 'community' => $keep->name]);

        // Buildings whose community text still names the discarded community
        // but were never linked to it (post-backfill this is a no-op).
        $buildings += MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('community_id')
            ->whereRaw('LOWER(community) = ?', [mb_strtolower(trim($oldName))])
            ->update(['community_id' => $keep->id, 'community' => $keep->name]);

        $buildingIds = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('community_id', $keep->id)
            ->pluck('id');

        // Unit snapshots of those buildings that still carry the old name.
        $units = Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('map_location_id', $buildingIds)
            ->whereRaw('LOWER(community) = ?', [mb_strtolower(trim($oldName))])
            ->update(['community' => $keep->name]);

        // Legacy fallback: units never linked to a building row.
        $legacy = Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('map_location_id')
            ->whereRaw('LOWER(community) = ?', [mb_strtolower(trim($oldName))])
            ->update(['community' => $keep->name]);

        $discard->delete();

        return [
            'kept' => $keep,
            'discarded_name' => $oldName,
            'buildings' => $buildings,
            'units' => $units + $legacy,
            'legacy' => $legacy,
        ];
    }

    /**
     * Give a Google Maps search link to every building of a tenant that still
     * has none. Creates the missing entries and fills entries whose link is
     * blank — never overwrites an existing link. Returns the number updated.
     */
    public function backfillForTenant(int $tenantId): int
    {
        $count = 0;

        $buildings = Property::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('sub_community')
            ->where('sub_community', '<>', '')
            ->select('sub_community')
            ->selectRaw('MAX(community) as community, MAX(city) as city')
            ->groupBy('sub_community')
            ->get();

        foreach ($buildings as $building) {
            $location = $this->findByName($tenantId, $building->sub_community);

            if ($location && $location->map_url !== null) {
                $this->linkBuildingUnits($tenantId, $building->sub_community, $location);

                continue;
            }

            $query = $this->queryFor([$building->sub_community, $building->community, $building->city]);
            $url = $this->searchUrl($query);
            if ($url === null) {
                continue;
            }

            if ($location) {
                $location->updateQuietly(['map_url' => $url, 'map_query' => $query]);
            } else {
                $location = MapLocation::create([
                    'tenant_id' => $tenantId,
                    'sub_community' => $building->sub_community,
                    'community' => $building->community,
                    'city' => $building->city,
                    'map_url' => $url,
                    'map_query' => $query,
                ]);
            }

            $this->linkBuildingUnits($tenantId, $building->sub_community, $location);
            $count++;
        }

        return $count;
    }
}
