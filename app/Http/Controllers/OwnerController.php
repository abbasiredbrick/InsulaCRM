<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\Property;
use App\Services\OwnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Owners: the owners/landlords master. Units link to an owner so
 * the same person is entered once (name, phone, email, office address and a
 * pinned office location for directions/Waze), instead of being retyped on
 * every unit. Admin-only.
 */
class OwnerController extends Controller
{
    private function owned(Owner $owner): Owner
    {
        abort_unless($owner->tenant_id === auth()->user()->tenant_id, 403);

        return $owner;
    }

    public function index(Request $request): View
    {
        $tenantId = auth()->user()->tenant_id;
        $search = trim((string) $request->query('q'));

        $query = Owner::where('tenant_id', $tenantId)->withCount('properties');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('office_address', 'like', $like);
            });
        }

        $owners = $query->orderBy('name')->get();

        return view('settings.owners', [
            'owners' => $owners,
            'search' => $search,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:190',
            'office_address' => 'nullable|string|max:255',
            'office_location' => 'nullable|string|max:1000',
        ]);

        $owners = app(OwnerService::class);
        $result = $owners->findOrCreate($tenantId, $data);
        $owner = $result['owner'];

        $owners->setOfficeLocation($owner, $request->input('office_location'));

        if (! $result['reused']) {
            $owners->linkUnitsByName($owner);
        }

        return back()->with(
            $result['reused'] ? 'warning' : 'success',
            $result['reused']
                ? __('":name" already exists — it was reused, not duplicated.', ['name' => $owner->name])
                : __('Owner ":name" added.', ['name' => $owner->name])
        );
    }

    public function update(Request $request, Owner $owner): RedirectResponse
    {
        $owner = $this->owned($owner);
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:190',
            'office_address' => 'nullable|string|max:255',
            'office_location' => 'nullable|string|max:1000',
        ]);

        $owners = app(OwnerService::class);

        $conflict = $owners->findByName($tenantId, $data['name']);
        if ($conflict && $conflict->id !== $owner->id) {
            return back()->with('error', __('":name" already belongs to another owner — edit that one instead.', ['name' => $data['name']]));
        }

        $owner->update([
            'name' => trim($data['name']),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'office_address' => trim((string) ($data['office_address'] ?? '')) ?: null,
        ]);

        $owners->setOfficeLocation($owner, $request->input('office_location'));
        $updated = $owners->cascadeToUnits($owner);

        return back()->with('success', __('Owner updated — :count unit(s) refreshed.', ['count' => $updated]));
    }

    public function destroy(Owner $owner): RedirectResponse
    {
        $owner = $this->owned($owner);
        $name = $owner->name;

        $units = Property::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->where('owner_id', $owner->id)
            ->count();

        if ($units > 0) {
            return back()->with('error', __('Cannot remove ":name" — :count unit(s) still point at this owner.', ['name' => $name, 'count' => $units]));
        }

        $owner->delete();

        return back()->with('success', __('Removed owner ":name".', ['name' => $name]));
    }

    /**
     * JSON search for the unit form owner picker (x-searchable-select).
     * Contract: { "results": [{ "value": "<owner id>", "label": "…" }] }.
     */
    public function search(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $term = trim((string) $request->query('q'));

        $query = Owner::where('tenant_id', $tenantId)->withCount('properties');

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('office_address', 'like', $like);
            });
        }

        $results = $query->orderBy('name')->limit(30)->get()->map(function (Owner $owner) {
            $label = $owner->name;
            if ($owner->phone) {
                $label .= ' · '.$owner->phone;
            }
            $units = (int) $owner->properties_count;
            if ($units > 0) {
                $label .= ' — '.$units.' '.($units === 1 ? __('unit') : __('units'));
            }

            return ['value' => (string) $owner->id, 'label' => $label];
        })->values();

        return response()->json(['results' => $results]);
    }
}
