<?php

namespace App\Http\Controllers;

use App\Models\Community;
use App\Models\MapLocation;
use App\Models\Property;
use App\Services\MapLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Settings → Locations: the communities → sub-communities (buildings)
 * master. Buildings are the map_locations rows that inventory imports already
 * maintained; this screen adds the community grouping, normalized de-dup,
 * renames (cascading to every unit) and merges.
 */
class MapLocationController extends Controller
{
    private function ownedLocation(MapLocation $mapLocation): MapLocation
    {
        abort_unless($mapLocation->tenant_id === auth()->user()->tenant_id, 403);

        return $mapLocation;
    }

    private function ownedCommunity(Community $community): Community
    {
        abort_unless($community->tenant_id === auth()->user()->tenant_id, 403);

        return $community;
    }

    public function index(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;
        $maps = app(MapLocationService::class);

        $search = trim((string) $request->query('q'));
        $selectedCommunity = (int) $request->query('community', 0);

        $communities = Community::where('tenant_id', $tenantId)
            ->withCount('mapLocations')
            ->orderBy('name')
            ->get();

        $buildingsQuery = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('community')
            ->withCount('properties');

        if ($search !== '') {
            $term = '%'.$search.'%';
            $buildingsQuery->where(function ($q) use ($term) {
                $q->where('sub_community', 'like', $term)
                    ->orWhere('community', 'like', $term)
                    ->orWhere('city', 'like', $term);
            });
        }

        if ($selectedCommunity !== 0) {
            $buildingsQuery->where('community_id', $selectedCommunity);
        }

        $buildings = $buildingsQuery->orderBy('sub_community')->get();

        $missing = 0;
        foreach ($buildings as $building) {
            $building->current_query = $building->map_query;
            $building->suggested = $maps->searchUrl($maps->queryForLocation($building));
            if ($building->map_url === null) {
                $missing++;
            }
        }

        // Merge targets for the per-row picker: buildings in the same community
        // (all buildings when the row has none), by community_id.
        $byCommunity = $buildings->groupBy(fn ($b) => (string) ($b->community_id ?? 0))
            ->map(fn ($group) => $group->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->sub_community])->values())
            ->toArray();

        return view('settings.map-locations', [
            'communities' => $communities,
            'buildings' => $buildings,
            'selectedCommunity' => $selectedCommunity,
            'search' => $search,
            'missing' => $missing,
            'maps' => $maps,
            'byCommunity' => $byCommunity,
            'cities' => $maps->citiesForTenant($tenantId, auth()->user()->tenant?->country),
        ]);
    }

    public function storeBuilding(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'sub_community' => 'required|string|max:255',
            'community_id' => 'nullable|integer',
            'city' => 'nullable|string|max:255',
        ]);

        $community = null;
        if (filled($data['community_id'] ?? null)) {
            $community = Community::where('tenant_id', $tenantId)->find((int) $data['community_id']);
            abort_unless($community, 422);
        }

        $maps = app(MapLocationService::class);
        $location = $maps->ensureMapLocation(
            $tenantId,
            $data['sub_community'],
            $community?->name,
            $data['city'] ?? $community?->city,
            null
        );

        if ($community && $location->community_id === null) {
            $location->update(['community_id' => $community->id]);
        }

        $maps->linkBuildingUnits($tenantId, $data['sub_community'], $location);

        return back()->with('success', __('Building ":building" is ready.', ['building' => $location->sub_community]));
    }

    public function setBuildingLocation(Request $request, MapLocation $mapLocation): RedirectResponse
    {
        $location = $this->ownedLocation($mapLocation);
        $maps = app(MapLocationService::class);

        $data = $request->validate([
            'mode' => 'required|in:save,clear,auto',
            'location' => 'nullable|string|max:2048',
        ]);

        if ($data['mode'] === 'clear') {
            $location->update(['map_url' => null, 'map_query' => null]);

            return back()->with('success', __('Location cleared for :building.', ['building' => $location->sub_community]));
        }

        $queryParts = [$location->sub_community, $location->community, $location->city];

        if ($data['mode'] === 'auto') {
            if ($location->map_url !== null) {
                return back()->with('info', __(':building already has a location.', ['building' => $location->sub_community]));
            }

            $query = $maps->queryFor($queryParts);
            $mapUrl = $maps->searchUrl($query);
            $mapQuery = $query !== '' ? $query : null;
        } else {
            $input = trim((string) ($data['location'] ?? ''));
            $clean = $maps->cleanUrl($input);
            if ($clean !== null) {
                $mapUrl = $clean;
                $query = $maps->queryFor($queryParts);
                $mapQuery = $query !== '' ? $query : null;
            } else {
                $mapUrl = $maps->searchUrl($input);
                $mapQuery = $input !== '' ? $input : null;
            }
        }

        if ($mapUrl === null && $mapQuery === null) {
            return back()->with('error', __('No map link understood from that input for :building.', ['building' => $location->sub_community]));
        }

        $location->update(['map_url' => $mapUrl, 'map_query' => $mapQuery]);

        $pin = $maps->resolveCoordinates($location) !== null;

        return back()->with(
            $pin ? 'success' : 'info',
            $pin
                ? __('Saved location for :building — map pin ready.', ['building' => $location->sub_community])
                : __('Saved location for :building — no map pin yet (its coordinates could not be resolved).', ['building' => $location->sub_community])
        );
    }

    public function generateAll(): RedirectResponse
    {
        $updated = app(MapLocationService::class)->backfillForTenant(auth()->user()->tenant_id);

        return back()->with(
            $updated > 0 ? 'success' : 'info',
            __('Generated map links for :count building(s).', ['count' => $updated])
        );
    }

    public function renameBuilding(Request $request, MapLocation $mapLocation): RedirectResponse
    {
        $location = $this->ownedLocation($mapLocation);

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $result = app(MapLocationService::class)->rename($location, $data['name']);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        if (isset($result['conflict']) && $result['target'] instanceof MapLocation) {
            return back()->with('error', __('":name" already exists — merge the two buildings instead of renaming.', ['name' => $result['target']->sub_community]));
        }

        return back()->with('success', __('Building renamed — :count unit(s) updated.', ['count' => $result['renamed'] ?? 0]));
    }

    public function mergeBuildings(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'keep_id' => 'required|integer',
            'discard_id' => 'required|integer|different:keep_id',
        ]);

        $result = app(MapLocationService::class)->merge($tenantId, (int) $data['keep_id'], (int) $data['discard_id']);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', __('Merged into :building — :count unit(s) moved.', [
            'building' => $result['kept']->sub_community,
            'count' => $result['moved'] ?? 0,
        ]));
    }

    public function destroyBuilding(MapLocation $mapLocation): RedirectResponse
    {
        $location = $this->ownedLocation($mapLocation);
        $name = $location->sub_community;

        $units = $location->properties()->count()
            + Property::withoutGlobalScopes()
                ->where('tenant_id', auth()->user()->tenant_id)
                ->where('sub_community', $name)
                ->whereNull('map_location_id')
                ->count();
        if ($units > 0) {
            return back()->with('error', __('Cannot remove ":building" — it still has :count unit(s). Merge them into another building first.', ['building' => $name, 'count' => $units]));
        }

        $location->delete();

        return back()->with('success', __('Removed building ":building".', ['building' => $name]));
    }

    public function storeCommunity(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ]);

        $name = trim($data['name']);
        $maps = app(MapLocationService::class);

        $duplicate = Community::where('tenant_id', $tenantId)
            ->get(['id', 'name'])
            ->contains(fn ($c) => $maps->normalizeName($c->name) === $maps->normalizeName($name));

        if ($duplicate) {
            return back()->with('error', __('Community ":name" already exists.', ['name' => $name]));
        }

        Community::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'city' => $data['city'] ?: null,
        ]);

        return back()->with('success', __('Community ":name" added.', ['name' => $name]));
    }

    public function updateCommunity(Request $request, Community $community): RedirectResponse
    {
        $community = $this->ownedCommunity($community);
        $tenantId = auth()->user()->tenant_id;
        $maps = app(MapLocationService::class);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
        ]);

        $name = trim($data['name']);
        $oldName = $community->name;

        $duplicate = Community::where('tenant_id', $tenantId)
            ->where('id', '!=', $community->id)
            ->get(['id', 'name'])
            ->contains(fn ($c) => $maps->normalizeName($c->name) === $maps->normalizeName($name));

        if ($duplicate) {
            return back()->with('error', __('Community ":name" already exists.', ['name' => $name]));
        }

        $community->update(['name' => $name, 'city' => $data['city'] ?: null]);

        // Cascade the new name to the building snapshots and the units of
        // those buildings, so filters/search/portal pushes stay in sync.
        DB::table('map_locations')
            ->where('tenant_id', $tenantId)
            ->where('community_id', $community->id)
            ->update(['community' => $name]);

        $buildingIds = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('community_id', $community->id)
            ->pluck('id');

        DB::table('properties')
            ->where('tenant_id', $tenantId)
            ->whereIn('map_location_id', $buildingIds)
            ->update(['community' => $name]);

        // Legacy fallback: units never linked to a building row.
        DB::table('properties')
            ->where('tenant_id', $tenantId)
            ->whereNull('map_location_id')
            ->whereRaw('LOWER(community) = ?', [mb_strtolower(trim($oldName))])
            ->update(['community' => $name]);

        return back()->with('success', __('Community renamed — its buildings and units were updated.', ['name' => $name]));
    }

    public function mergeCommunities(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'keep_id' => 'required|integer',
            'discard_id' => 'required|integer|different:keep_id',
        ]);

        $keep = Community::where('tenant_id', $tenantId)->find((int) $data['keep_id']);
        $discard = Community::where('tenant_id', $tenantId)->find((int) $data['discard_id']);
        abort_unless($keep && $discard, 403);

        $result = app(MapLocationService::class)->mergeCommunities($tenantId, (int) $data['keep_id'], (int) $data['discard_id']);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', __('Merged ":discard" into ":keep" — :count building(s) rehomed.', [
            'discard' => $result['discarded_name'],
            'keep' => $result['kept']->name,
            'count' => $result['buildings'] ?? 0,
        ]));
    }

    public function destroyCommunity(Community $community): RedirectResponse
    {
        $community = $this->ownedCommunity($community);

        $buildings = $community->mapLocations()->count();
        if ($buildings > 0) {
            return back()->with('error', __('Community ":name" still has :count building(s). Remove or rehome them first.', ['name' => $community->name, 'count' => $buildings]));
        }

        $name = $community->name;
        $community->delete();

        return back()->with('success', __('Removed community ":name".', ['name' => $name]));
    }

    /**
     * JSON search for the New Unit building picker (x-searchable-select).
     * Contract: { "results": [{ "value": "<map_location id>", "label": "…" }] }.
     */
    public function searchBuildings(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $term = trim((string) $request->query('q'));

        $query = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->withCount('properties');

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('sub_community', 'like', $like)
                    ->orWhere('community', 'like', $like)
                    ->orWhere('city', 'like', $like);
            });
        }

        $results = $query->orderBy('sub_community')->limit(30)->get()->map(function (MapLocation $location) {
            $label = $location->sub_community;
            $place = collect([$location->community, $location->city])
                ->filter()
                ->implode(' · ');
            if ($place !== '') {
                $label .= ' · '.$place;
            }
            $units = (int) $location->properties_count;
            if ($units > 0) {
                $label .= ' — '.$units.' '.($units === 1 ? __('unit') : __('units'));
            }

            return ['value' => (string) $location->id, 'label' => $label];
        })->values();

        return response()->json(['results' => $results]);
    }
}
