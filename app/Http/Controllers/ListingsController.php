<?php

namespace App\Http\Controllers;

use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Services\BusinessModeService;
use App\Services\PermitExpiryService;
use Illuminate\Http\Request;

class ListingsController extends Controller
{
    private const TABS = ['units', 'renewals', 'calendar'];

    /**
     * The rental "listed units" board: units currently published live
     * (availability = listed), plus the permit-renewal and permit-calendar
     * views for the same set. A unit cannot stay listed without a valid RERA /
     * Madhmoun permit, so permit tracking belongs with the listings.
     */
    public function index(Request $request)
    {
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'units';

        $query = $this->baseQuery($request);

        $units = (clone $query)->latest('updated_at')->paginate(25);

        $readyQuery = Property::where('availability', 'ready_to_list');
        if (! auth()->user()->isAdmin()) {
            $readyQuery->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        $kpis = [
            'listed' => (clone $query)->count(),
            'live' => (clone $query)->where(function ($q) {
                $q->where('bayut_status', 'live')
                    ->orWhere('dubizzle_status', 'live')
                    ->orWhere('propertyfinder_status', 'live');
            })->count(),
            'ready' => $readyQuery->count(),
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

        $renewals = null;
        if ($tab === 'renewals') {
            $renewals = $this->baseQuery($request)
                ->orderByRaw('CASE WHEN permit_expires_at IS NULL THEN 1 ELSE 0 END, permit_expires_at ASC')
                ->paginate(50);
        }

        return view('listings.index', compact('units', 'kpis', 'sources', 'buildings', 'agents', 'tab', 'renewals') + [
            'permitWindow' => $this->permitWindow(),
        ]);
    }

    /**
     * JSON events for the listings permit calendar: one all-day event per unit
     * permit expiry, scoped like the listings board itself.
     */
    public function permitsEvents(Request $request)
    {
        $query = $this->baseQuery($request)->whereNotNull('permit_expires_at');

        if ($request->filled('start')) {
            $query->whereDate('permit_expires_at', '>=', $request->start);
        }
        if ($request->filled('end')) {
            $query->whereDate('permit_expires_at', '<=', $request->end);
        }

        $window = $this->permitWindow();

        $events = $query->get()->map(function (Property $unit) use ($window) {
            $days = $unit->permitDaysLeft();

            $color = $days < 0 ? 'red' : ($days <= $window ? 'orange' : 'cyan');

            return [
                'id' => 'permit-'.$unit->id,
                'title' => $unit->permit_label.': '.$unit->display_name,
                'date' => $unit->permit_expires_at->format('Y-m-d'),
                'time' => null,
                'color' => $color,
                'url' => route('inventory.show', $unit),
                'completed' => false,
            ];
        })->values();

        return response()->json($events);
    }

    /**
     * CSV export of every listed unit with its permit number and expiry date,
     * so the renewal list can be worked (and backfilled) offline.
     */
    public function exportPermits(Request $request)
    {
        $query = $this->baseQuery($request);

        $units = $query
            ->orderByRaw('CASE WHEN permit_expires_at IS NULL THEN 1 ELSE 0 END, permit_expires_at ASC')
            ->get();

        $tenant = auth()->user()->tenant;

        $rows = [[
            'Reference', 'Unit', 'City', 'Community', 'Sub-Community', 'Intent',
            'Permit Type', 'Permit Number', 'Permit Expiry', 'Days Left', 'Status',
            'Agent', 'Owner',
        ]];

        foreach ($units as $unit) {
            $days = $unit->permitDaysLeft();

            $rows[] = [
                $unit->id,
                $unit->display_name,
                (string) $unit->city,
                (string) $unit->community,
                (string) $unit->sub_community,
                Property::INTENTS[$unit->intent] ?? (string) $unit->intent,
                $unit->permit_label,
                (string) $unit->rera_permit_no,
                $unit->permit_expires_at?->format('Y-m-d') ?? '',
                $days === null ? '' : (string) $days,
                $days === null ? 'missing' : PermitExpiryService::status($unit, $tenant),
                $unit->assignedAgent->name ?? '',
                $unit->owner->name ?? (string) $unit->owner_name,
            ];
        }

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, 'permit-renewals.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The listed-units query with every board filter applied, including the
     * agent visibility scope. Shared by the units board, renewals and calendar
     * so they never drift.
     */
    protected function baseQuery(Request $request)
    {
        $query = Property::with(['availabilitySource', 'assignedAgent', 'leads', 'owner'])
            ->where('availability', 'listed');

        if (! auth()->user()->isAdmin()) {
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

        $this->applyPermitStatusFilter($query, $request->input('permit_status'));

        return $query;
    }

    /**
     * The tenant's "due soon" window in days (max configured reminder offset).
     */
    protected function permitWindow(): int
    {
        return PermitExpiryService::windowFor(auth()->user()->tenant);
    }

    /**
     * Filter by permit state: expired, due (within the window), ok, or missing
     * (no permit number and/or no expiry recorded — the backfill backlog).
     */
    protected function applyPermitStatusFilter($query, ?string $status): void
    {
        $today = now()->startOfDay();

        match ($status) {
            'expired' => $query->whereNotNull('permit_expires_at')
                ->whereDate('permit_expires_at', '<', $today->toDateString()),
            'due' => $query->whereNotNull('permit_expires_at')
                ->whereBetween('permit_expires_at', [$today->toDateString(), $today->copy()->addDays($this->permitWindow())->toDateString()]),
            'ok' => $query->whereNotNull('permit_expires_at')
                ->whereDate('permit_expires_at', '>', $today->copy()->addDays($this->permitWindow())->toDateString()),
            'missing' => $query->where(fn ($q) => $q->whereNull('permit_expires_at')->orWhereNull('rera_permit_no')),
            default => null,
        };
    }
}
