<?php

namespace App\Http\Controllers;

use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Services\BusinessModeService;
use Illuminate\Http\Request;

class ListingsController extends Controller
{
    /**
     * The rental "listed units" board: units currently published live (availability = listed).
     */
    public function index(Request $request)
    {
        $query = Property::with(['availabilitySource', 'assignedAgent', 'leads'])
            ->where('availability', 'listed');

        if (!auth()->user()->isAdmin()) {
            $query->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('marketing_title', 'like', "%{$search}%")
                  ->orWhere('unit_no', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('community', 'like', "%{$search}%")
                  ->orWhere('sub_community', 'like', "%{$search}%");
            });
        }

        if ($request->filled('source')) {
            $query->where('availability_source_id', $request->source);
        }

        if ($request->filled('building')) {
            $query->where('sub_community', $request->building);
        }

        if ($request->filled('intent')) {
            $query->where('intent', $request->intent);
        }

        if (auth()->user()->isAdmin() && $request->filled('agent')) {
            $query->where('assigned_agent_id', $request->agent);
        }

        $units = (clone $query)->latest('updated_at')->paginate(25);

        $readyQuery = Property::where('availability', 'ready_to_list');
        if (!auth()->user()->isAdmin()) {
            $readyQuery->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        $kpis = [
            'listed'   => (clone $query)->count(),
            'live'     => (clone $query)->where(function ($q) {
                $q->where('bayut_status', 'live')
                  ->orWhere('dubizzle_status', 'live')
                  ->orWhere('propertyfinder_status', 'live');
            })->count(),
            'ready'    => $readyQuery->count(),
            'avg_rent' => (clone $query)->whereNotNull('rent_price')->avg('rent_price'),
        ];

        $sources = AvailabilitySource::orderBy('name')->get(['id', 'name']);
        $buildings = (clone $query)->whereNotNull('sub_community')
            ->distinct()->orderBy('sub_community')->pluck('sub_community');

        $agents = collect();
        if (auth()->user()->isAdmin()) {
            $agents = \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', BusinessModeService::getRoles()))
                ->orderBy('name')->get(['id', 'name']);
        }

        return view('listings.index', compact('units', 'kpis', 'sources', 'buildings', 'agents'));
    }
}