<?php

namespace App\Services;

use App\Models\MapLocation;
use App\Models\Property;

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

    const EMBED_KEYED_BASE = 'https://www.google.com/maps/embed/v1/place?q=';

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

        $key = $key !== null ? trim($key) : '';
        if ($key !== '') {
            return self::EMBED_KEYED_BASE.rawurlencode($query).'&key='.rawurlencode($key);
        }

        return self::EMBED_BASE.rawurlencode($query).self::EMBED_SUFFIX;
    }

    public function isHttpUrl(?string $value): bool
    {
        return $this->cleanUrl($value) !== null;
    }

    /**
     * Create the location entry for a building the first time it is seen.
     * Never overwrites: a link a source provided (or a human corrected in
     * Settings) is authoritative, so re-imports leave it alone.
     */
    public function ensureMapLocation(int $tenantId, string $subCommunity, ?string $community, ?string $city, ?string $sourceUrl): ?MapLocation
    {
        $existing = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sub_community', $subCommunity)
            ->first();

        if ($existing) {
            return $existing;
        }

        $query = $this->queryFor([$subCommunity, $community, $city]);
        $url = $this->cleanUrl($sourceUrl) ?: $this->searchUrl($query);

        return MapLocation::create([
            'tenant_id' => $tenantId,
            'sub_community' => $subCommunity,
            'community' => $community !== null && $community !== '' ? $community : null,
            'city' => $city !== null && $city !== '' ? $city : null,
            'map_url' => $url,
            'map_query' => $query !== '' ? $query : null,
        ]);
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
            $location = MapLocation::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('sub_community', $building->sub_community)
                ->first();

            if ($location && $location->map_url !== null) {
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
                MapLocation::create([
                    'tenant_id' => $tenantId,
                    'sub_community' => $building->sub_community,
                    'community' => $building->community,
                    'city' => $building->city,
                    'map_url' => $url,
                    'map_query' => $query,
                ]);
            }
            $count++;
        }

        return $count;
    }
}
