<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Property;
use App\Models\PropertyMedia;
use App\Support\InventorySearchParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingController extends Controller
{
    /**
     * Units the current user may see in the inventory. Regular agents only see
     * their own (or unassigned) units; admins and management roles (e.g. a
     * property manager) can browse the whole portfolio.
     */
    protected function baseQuery(Request $request)
    {
        $query = Property::with(['assignedAgent', 'media', 'leads']);

        if (! auth()->user()->isAdmin() && auth()->user()->isAgent()) {
            $query->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        return $query;
    }

    /**
     * Whether the current user may filter results by assigned agent.
     */
    protected function canFilterByAgent(): bool
    {
        return auth()->user()->isAdmin() || ! auth()->user()->isAgent();
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        // ── Basic search (free text: "2br", "2 br", "2bhk", "2 bed", "marina tower 901"...)
        if ($request->filled('search')) {
            InventorySearchParser::apply($query, $request->search);
        }

        if ($this->canFilterByAgent() && $request->filled('agent')) {
            $query->where('assigned_agent_id', $request->agent);
        }

        // ── Advanced search (each field additive) ──
        if ($request->filled('intent')) {
            $query->where('intent', $request->intent);
        }

        if ($request->filled('availability')) {
            $query->where('availability', $request->availability);
        }

        if ($request->filled('market_class')) {
            $query->where('market_class', $request->market_class);
        }

        if ($request->filled('category')) {
            $query->where('property_category', $request->category);
        }

        if ($request->filled('furnishing')) {
            $query->where('furnishing', $request->furnishing);
        }

        if ($request->filled('rent_period')) {
            $query->where('rent_period', $request->rent_period);
        }

        if ($request->filled('community')) {
            $query->where('community', $request->community);
        }

        if ($request->filled('sub_community')) {
            $query->where('sub_community', $request->sub_community);
        }

        if ($request->filled('building_no')) {
            $query->where('building_no', $request->building_no);
        }

        if ($request->filled('floor_no')) {
            $query->where('floor_no', $request->floor_no);
        }

        if ($request->filled('bedrooms_min')) {
            $query->where('bedrooms', '>=', (int) $request->bedrooms_min);
        }
        if ($request->filled('bedrooms_max')) {
            $query->where('bedrooms', '<=', (int) $request->bedrooms_max);
        }

        if ($request->filled('bathrooms')) {
            $query->where('bathrooms', (int) $request->bathrooms);
        }

        $this->applyPriceRange($query, $request, 'rent_price', 'rent_min', 'rent_max');
        $this->applyPriceRange($query, $request, 'list_price', 'sale_min', 'sale_max');

        $this->applyAreaRange($query, $request);

        if ($request->filled('developer_name')) {
            $query->where('developer_name', 'like', "%{$request->developer_name}%");
        }

        if ($request->filled('rera_permit_no')) {
            $query->where('rera_permit_no', 'like', "%{$request->rera_permit_no}%");
        }

        if ($request->filled('title_deed_no')) {
            $query->where('title_deed_no', 'like', "%{$request->title_deed_no}%");
        }

        if ($request->filled('plot_no')) {
            $query->where('plot_no', 'like', "%{$request->plot_no}%");
        }

        if ($request->filled('owner_name')) {
            $query->where('owner_name', 'like', "%{$request->owner_name}%");
        }

        if ($request->filled('has_photos')) {
            $query->whereHas('media', function ($q) {
                $q->where('type', 'photo');
            });
        }

        if ($request->filled('has_portal_live')) {
            $query->where(function ($q) {
                $q->where('bayut_status', 'live')
                    ->orWhere('dubizzle_status', 'live')
                    ->orWhere('propertyfinder_status', 'live');
            });
        }

        if ($request->filled('parking')) {
            $query->where('parking', '>=', (int) $request->parking);
        }

        if ($request->filled('source')) {
            $query->where('availability_source_id', $request->source);
        }

        $sort = $request->input('sort');
        $direction = $request->input('direction', $sort ? 'asc' : 'desc');
        if (! in_array(strtolower((string) $direction), ['asc', 'desc'], true)) {
            $direction = $sort ? 'asc' : 'desc';
        }

        if ($sort === 'leads') {
            $query->withCount('leads');
        }

        $this->applySort($query, $sort, $direction);

        $units = (clone $query)->paginate(20);

        $kpis = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->whereIn('availability', ['ready_to_list', 'listed'])->count(),
            'for_rent' => (clone $query)->whereIn('intent', ['rent', 'both'])->whereIn('availability', ['ready_to_list', 'listed'])->count(),
            'for_sale' => (clone $query)->whereIn('intent', ['sale', 'both'])->whereIn('availability', ['ready_to_list', 'listed'])->count(),
            'live_portals' => (clone $query)->where('availability', 'listed')
                ->where(function ($q) {
                    $q->where('bayut_status', 'live')
                        ->orWhere('dubizzle_status', 'live')
                        ->orWhere('propertyfinder_status', 'live');
                })->count(),
        ];

        $agents = $this->filterableAgents($request);
        $sources = $this->filterableSources($request);
        $communities = $this->optionValues($request, 'community');
        $subCommunities = $this->optionValues($request, 'sub_community');

        return view('inventory.index', [
            'units' => $units,
            'kpis' => $kpis,
            'agents' => $agents,
            'sources' => $sources,
            'communities' => $communities,
            'subCommunities' => $subCommunities,
            'canFilterByAgent' => $this->canFilterByAgent(),
            'sort' => $request->input('sort'),
            'direction' => $direction,
        ]);
    }

    /**
     * JSON source of truth for the advanced-search dropdowns. Returns the
     * available source / agent / community / sub-community options honoring
     * every selected advanced filter. Used to live-update the option lists
     * as the user changes filters (cascading values).
     */
    public function filterOptions(Request $request)
    {
        return response()->json([
            'sources' => $this->filterableSources($request),
            'agents' => $this->filterableAgents($request),
            'communities' => $this->optionValues($request, 'community'),
            'sub_communities' => $this->optionValues($request, 'sub_community'),
        ]);
    }

    /**
     * Available agents considering the currently selected advanced filters
     * (an agent column is only offered when the role scope permits it).
     */
    protected function filterableAgents(Request $request): \Illuminate\Support\Collection
    {
        if (! $this->canFilterByAgent()) {
            return collect();
        }

        $agentIds = (clone $this->optionQuery($request, ['agent']))
            ->whereNotNull('assigned_agent_id')
            ->where('assigned_agent_id', '!=', '')
            ->distinct()
            ->pluck('assigned_agent_id');

        return \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
            ->whereIn('id', $agentIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Available availability sources considering the currently selected
     * advanced filters.
     */
    protected function filterableSources(Request $request): \Illuminate\Support\Collection
    {
        $sourceIds = (clone $this->optionQuery($request, ['source']))
            ->whereNotNull('availability_source_id')
            ->where('availability_source_id', '!=', '')
            ->distinct()
            ->pluck('availability_source_id');

        return \App\Models\AvailabilitySource::whereIn('id', $sourceIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Distinct values for a data-driven dropdown column. The column's own
     * filter is excluded so the currently selected value stays navigable;
     * every other selected advanced filter narrows the candidate list.
     */
    protected function optionValues(Request $request, string $column): array
    {
        return (array) (clone $this->optionQuery($request, [$column]))
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->take(500)->pluck($column)->values()->all();
    }

    /**
     * Tenant-scoped property query mirroring index()'s visibility rules and
     * every advanced filter except the excluded ones. Used to build the
     * cascading dropdown option lists.
     */
    protected function optionQuery(Request $request, array $exclude = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = Property::withoutGlobalScopes()->where('tenant_id', auth()->user()->tenant_id);

        if (! auth()->user()->isAdmin() && auth()->user()->isAgent()) {
            $query->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        $filters = [
            'intent' => fn ($v) => $query->where('intent', $v),
            'availability' => fn ($v) => $query->where('availability', $v),
            'market_class' => fn ($v) => $query->where('market_class', $v),
            'category' => fn ($v) => $query->where('property_category', $v),
            'furnishing' => fn ($v) => $query->where('furnishing', $v),
            'rent_period' => fn ($v) => $query->where('rent_period', $v),
            'community' => fn ($v) => $query->where('community', $v),
            'sub_community' => fn ($v) => $query->where('sub_community', $v),
            'building_no' => fn ($v) => $query->where('building_no', $v),
            'floor_no' => fn ($v) => $query->where('floor_no', $v),
            'bedrooms_min' => fn ($v) => $query->where('bedrooms', '>=', (int) $v),
            'bedrooms_max' => fn ($v) => $query->where('bedrooms', '<=', (int) $v),
            'bathrooms' => fn ($v) => $query->where('bathrooms', (int) $v),
            'rent_min' => fn ($v) => $query->where('rent_price', '>=', (float) $v),
            'rent_max' => fn ($v) => $query->where('rent_price', '<=', (float) $v),
            'sale_min' => fn ($v) => $query->where('list_price', '>=', (float) $v),
            'sale_max' => fn ($v) => $query->where('list_price', '<=', (float) $v),
            'area_min' => fn ($v) => $query->where('square_footage', '>=', (int) $v),
            'area_max' => fn ($v) => $query->where('square_footage', '<=', (int) $v),
            'developer_name' => fn ($v) => $query->where('developer_name', 'like', "%{$v}%"),
            'rera_permit_no' => fn ($v) => $query->where('rera_permit_no', 'like', "%{$v}%"),
            'title_deed_no' => fn ($v) => $query->where('title_deed_no', 'like', "%{$v}%"),
            'plot_no' => fn ($v) => $query->where('plot_no', 'like', "%{$v}%"),
            'owner_name' => fn ($v) => $query->where('owner_name', 'like', "%{$v}%"),
            'parking' => fn ($v) => $query->where('parking', '>=', (int) $v),
            'source' => fn ($v) => $query->where('availability_source_id', $v),
            'agent' => function ($v) use ($query) {
                if ($this->canFilterByAgent()) {
                    $query->where('assigned_agent_id', $v);
                }
            },
            'has_photos' => function () use ($query) {
                $query->whereHas('media', function ($q) { $q->where('type', 'photo'); });
            },
            'has_portal_live' => function () use ($query) {
                $query->where(function ($q) {
                    $q->where('bayut_status', 'live')
                        ->orWhere('dubizzle_status', 'live')
                        ->orWhere('propertyfinder_status', 'live');
                });
            },
        ];

        foreach ($filters as $key => $apply) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            if (in_array($key, ['has_photos', 'has_portal_live'], true)) {
                if ($request->filled($key)) {
                    $apply();
                }
            } elseif ($request->filled($key)) {
                $apply($request->input($key));
            }
        }

        return $query;
    }

    /**
     * Apply a whitelisted column sort. Only user-facing sortable columns are
     * accepted; anything else falls back to the default newest-first order.
     */
    protected function applySort($query, ?string $sort, string $direction): void
    {
        $sortable = [
            'unit' => "CASE WHEN marketing_title IS NOT NULL AND marketing_title != '' THEN marketing_title ELSE COALESCE(sub_community, community, address, '') END",
            'intent' => 'intent',
            'price' => "CASE WHEN intent IN ('rent','both') THEN rent_price ELSE COALESCE(list_price, asking_price) END",
            'availability' => 'availability',
            'leads' => 'leads_count',
            'agent' => "(SELECT name FROM users WHERE users.id = properties.assigned_agent_id)",
        ];

        $column = $sortable[$sort] ?? 'updated_at';
        $dir = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw($column.' '.$dir);
    }

    /**
     * Apply an inclusive lower/upper bound on a decimal price column.
     */
    protected function applyPriceRange($query, Request $request, string $column, string $minKey, string $maxKey): void
    {
        if ($request->filled($minKey)) {
            $query->where($column, '>=', (float) $request->{$minKey});
        }
        if ($request->filled($maxKey)) {
            $query->where($column, '<=', (float) $request->{$maxKey});
        }
    }

    /**
     * Apply square-footage lower/upper bounds.
     */
    protected function applyAreaRange($query, Request $request): void
    {
        if ($request->filled('area_min')) {
            $query->where('square_footage', '>=', (int) $request->area_min);
        }
        if ($request->filled('area_max')) {
            $query->where('square_footage', '<=', (int) $request->area_max);
        }
    }

    public function create(Request $request)
    {
        return view('inventory.create', [
            'property' => new Property(['availability' => 'draft']),
            'agents' => $this->agents(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        if (empty(trim((string) ($data['address'] ?? '')))) {
            $data['address'] = trim(implode(' ', array_filter([
                $data['sub_community'] ?? null,
                $data['community'] ?? null,
                $data['building_no'] ?? null,
                $data['unit_no'] ?? null,
            ])));
        }

        if (empty(trim((string) ($data['address'])))) {
            return back()->withErrors(['address' => __('Address or a community reference is required.')])->withInput();
        }

        $data['city'] = $data['city'] ?? ($data['community'] ?? '');
        $data['state'] = $data['state'] ?? '';
        $data['zip_code'] = $data['zip_code'] ?? '';

        $property = Property::create([
            'tenant_id' => auth()->user()->tenant_id,
            ...$data,
            'availability' => $data['availability'] ?? 'draft',
        ]);

        $this->syncMedia($request, $property);

        AuditLog::log('inventory.unit_created', $property);

        return redirect()->route('inventory.show', $property)
            ->with('success', __('Unit added to inventory.'));
    }

    public function show(Request $request, Property $property)
    {
        $property->load(['assignedAgent', 'media', 'leads', 'leads.deals']);

        return view('inventory.show', [
            'property' => $property,
            'agents' => $this->agents(),
        ]);
    }

    public function edit(Request $request, Property $property)
    {
        $property->load('leads');

        return view('inventory.edit', [
            'property' => $property,
            'agents' => $this->agents(),
        ]);
    }

    public function update(Request $request, Property $property)
    {
        $data = $request->validate($this->rules());

        if (empty(trim((string) ($data['address'] ?? '')))) {
            $data['address'] = trim(implode(' ', array_filter([
                $data['sub_community'] ?? null,
                $data['community'] ?? null,
                $data['building_no'] ?? null,
                $data['unit_no'] ?? null,
            ])));
        }

        if (empty(trim((string) ($data['address'])))) {
            return back()->withErrors(['address' => __('Address or a community reference is required.')])->withInput();
        }

        $data['city'] = $data['city'] ?? ($data['community'] ?? '');
        $data['state'] = $data['state'] ?? '';
        $data['zip_code'] = $data['zip_code'] ?? '';

        $property->update($data);

        $this->syncMedia($request, $property);

        AuditLog::log('inventory.unit_updated', $property);

        return redirect()->route('inventory.show', $property)
            ->with('success', __('Unit updated.'));
    }

    public function destroy(Request $request, Property $property)
    {
        foreach ($property->media as $media) {
            if ($media->path) {
                Storage::disk('public')->delete($media->path);
            }
        }
        $property->media()->delete();

        $propertyId = $property->id;
        $property->delete();

        AuditLog::log('inventory.unit_deleted', $property);

        return redirect()->route('inventory.index')->with('success', __('Unit removed.'));
    }

    /**
     * JSON search used by the lead create/edit form to link inventory units.
     */
    public function searchForLead(Request $request)
    {
        $query = $this->baseQuery($request)
            ->whereIn('availability', ['draft', 'ready_to_list', 'listed', 'reserved']);

        if ($request->filled('q')) {
            InventorySearchParser::apply($query, $request->q);
        }

        if ($request->filled('intent')) {
            $query->where('intent', $request->intent);
        }

        if ($request->filled('availability')) {
            $query->where('availability', $request->availability);
        }

        if ($request->filled('category')) {
            $query->where('property_category', $request->category);
        }

        $units = $query->with('leads')->latest('updated_at')->take(50)->get();

        $units = $units->map(function (Property $unit) {
            return [
                'id' => $unit->id,
                'label' => $unit->display_name,
                'meta' => $unit->price_line,
                'detail' => trim(
                    implode(' • ', array_filter([
                        $unit->sub_community ?: $unit->community,
                        $unit->bedrooms ? $unit->bedrooms.' '.__('BR') : null,
                        $unit->square_footage ? \App\Helpers\TenantFormatHelper::area($unit->square_footage) : null,
                        $unit->unit_no ? __('Unit').' '.$unit->unit_no : null,
                    ]))
                ),
                'availability' => __(\App\Models\Property::AVAILABILITIES[$unit->availability] ?? $unit->availability),
            ];
        })->values();

        return response()->json($units);
    }

    /**
     * Publish tracker + per-portal feed exports.
     */
    public function portal(Request $request)
    {
        $query = $this->baseQuery($request);

        if ($this->canFilterByAgent() && $request->filled('agent')) {
            $query->where('assigned_agent_id', $request->agent);
        }

        $all = $query->latest('updated_at')->get();
        $ready = $all->filter->isPortalReady();

        $bayutIntegration = \App\Models\PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', 'bayut')
            ->where('is_active', true)
            ->first();

        // Per-portal counters for the current view
        $portals = [
            'bayut' => ['units' => $ready->filter(fn ($p) => $p->bayut_status !== 'live')->count(), 'live' => $all->where('bayut_status', 'live')->count()],
            'dubizzle' => ['units' => $ready->filter(fn ($p) => $p->dubizzle_status !== 'live')->count(), 'live' => $all->where('dubizzle_status', 'live')->count()],
            'propertyfinder' => ['units' => $ready->filter(fn ($p) => $p->propertyfinder_status !== 'live')->count(), 'live' => $all->where('propertyfinder_status', 'live')->count()],
        ];

        return view('inventory.portal', [
            'units' => $all,
            'ready' => $ready,
            'portals' => $portals,
            'wallet' => app(\App\Services\Portals\BayutCreditsService::class)->balance(auth()->user()->tenant),
            'bayut_sync' => $bayutIntegration !== null ? [
                'last_synced_at' => $bayutIntegration->last_synced_at,
                'last_error' => $bayutIntegration->last_error,
            ] : null,
            'enabled_portals' => \App\Models\PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
                ->where('is_active', true)
                ->pluck('portal')
                ->all(),
            'agents' => $this->canFilterByAgent()
                ? \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
                    ->whereHas('role', fn ($q) => $q->whereIn('name', \App\Services\BusinessModeService::getRoles()))
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }

    public function exportBayut(Request $request)
    {
        return $this->exportCsv($request, 'propertyplus');
    }

    public function exportDubizzle(Request $request)
    {
        return $this->exportCsv($request, 'propertyplus');
    }

    public function exportPropertyFinder(Request $request)
    {
        $units = $this->exportUnits($request, 'propertyfinder_status');

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><listings></listings>');

        foreach ($units as $unit) {
            $listing = $xml->addChild('listing');
            $listing->addChild('ReferenceNo', (string) $unit->id);
            $listing->addChild('PermitNumber', (string) $unit->rera_permit_no);
            $listing->addChild('Transaction', $unit->intent === 'sale' ? 'SELL' : 'RENT');
            $listing->addChild('RentFrequency', $unit->rent_period === 'monthly' ? 'Monthly' : 'Annual');
            $listing->addChild('PropertyType', $this->portalCategory($unit));
            $listing->addChild('Featured', 'No');
            $listing->addChild('Title_en', Str::limit($unit->display_name, 120));
            $listing->addChild('Description_en', Str::limit((string) $unit->marketing_description, 4000));
            $listing->addChild('Price', $this->portalPrice($unit) !== null ? (string) (int) $this->portalPrice($unit) : '');
            $listing->addChild('Beds', (string) ($unit->bedrooms ?? 0));
            $listing->addChild('Baths', (string) ($unit->bathrooms ?? 0));
            $listing->addChild('Area', (string) ($unit->square_footage ?? 0));
            $listing->addChild('City', htmlspecialchars(ucfirst((string) $unit->city)));
            $listing->addChild('Region', htmlspecialchars((string) ($unit->sub_community ?: $unit->community)));
            $listing->addChild('Address', htmlspecialchars($unit->address));
            $listing->addChild('Latitude', '');
            $listing->addChild('Longitude', '');
            $listing->addChild('AgentName', htmlspecialchars((string) $this->agentName()));
            $listing->addChild('AgentEmail', htmlspecialchars((string) $this->agentEmail()));
            $listing->addChild('AgentPhone', htmlspecialchars((string) $this->agentPhone()));
            $listing->addChild('ContactName', htmlspecialchars((string) $this->agentName()));

            $photos = $listing->addChild('Photos');
            foreach (array_values($unit->photo_urls) as $i => $url) {
                $photo = $photos->addChild('Photo');
                $photo->addChild('Url', htmlspecialchars($url));
                $photo->addChild('Order', (string) ($i + 1));
            }

            $amenities = $listing->addChild('Amenities');
            foreach ($this->amenities($unit) as $amenity) {
                $item = $amenities->addChild('Amenity');
                $item->addChild('Type', htmlspecialchars($amenity));
            }
        }

        return response($xml->asXML(), 200, [
            'Content-Type' => 'text/xml',
            'Content-Disposition' => 'attachment; filename="propertyfinder-feed.xml"',
        ]);
    }

    /**
     * Record portal publication for a unit (per portal tracker).
     */
    public function updatePortalStatus(Request $request, Property $property)
    {
        $portal = $request->validate(['portal' => 'required|in:bayut,dubizzle,propertyfinder'])['portal'];

        $rules = [
            'status' => 'required|in:not_listed,live,removed',
            'listing_reference' => 'nullable|string|max:60',
            'url' => 'nullable|url|max:500',
            'listed_at' => 'nullable|date',
        ];

        $data = $request->validate($rules);

        $map = [
            'bayut' => ['status' => 'bayut_status', 'reference' => 'bayut_listing_id', 'url' => 'bayut_url', 'listed_at' => 'bayut_listed_at'],
            'dubizzle' => ['status' => 'dubizzle_status', 'reference' => 'dubizzle_listing_reference', 'url' => 'dubizzle_url', 'listed_at' => 'dubizzle_listed_at'],
            'propertyfinder' => ['status' => 'propertyfinder_status', 'reference' => 'propertyfinder_listing_reference', 'url' => 'propertyfinder_url', 'listed_at' => 'propertyfinder_listed_at'],
        ][$portal];

        $property->update([
            $map['status'] => $data['status'],
            $map['reference'] => $data['listing_reference'] ?? null,
            $map['url'] => $data['url'] ?? null,
            $map['listed_at'] => $data['status'] === 'live' && empty($data['listed_at']) ? now()->toDateString() : ($data['listed_at'] ?? null),
        ]);

        AuditLog::log('inventory.portal_status_'.$portal, $property, ['portal' => $portal, 'status' => $data['status']]);

        return redirect()->route('inventory.portal')->with('success', __('Portal status recorded.'));
    }

    /**
     * Quick list/unlist toggle for a portal, usable on any unit regardless of
     * portal readiness - units that are already live on a portal (uploaded
     * outside the CRM) can be flagged as such immediately.
     */
    public function togglePortalStatus(Request $request, Property $property)
    {
        $portal = $request->validate(['portal' => 'required|in:bayut,dubizzle,propertyfinder'])['portal'];

        $statusField = "{$portal}_status";
        $listedAtField = "{$portal}_listed_at";

        $wasLive = $property->{$statusField} === 'live';
        $property->update([
            $statusField => $wasLive ? 'not_listed' : 'live',
            $listedAtField => $wasLive ? null : now()->toDateString(),
        ]);

        AuditLog::log($wasLive ? 'inventory.portal_unlisted_'.$portal : 'inventory.portal_listed_'.$portal, $property, ['portal' => $portal]);

        return back()->with('success', __(':portal marked as :status.', [
            'portal' => ucfirst($portal),
            'status' => $wasLive ? __('Not listed') : __('Live'),
        ]));
    }

    /**
     * Push a ready unit to Bayut/Dubizzle or Property Finder via the portal API.
     */
    public function pushToPortal(Request $request, Property $property, string $portal)
    {
        abort_unless(in_array($portal, ['bayut', 'propertyfinder'], true), 404);

        $integration = \App\Models\PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', $portal)
            ->where('is_active', true)
            ->first();

        if ($integration === null) {
            return back()->with('error', __('No active :portal integration. Set it up under Settings → Portal Integrations.', ['portal' => ucfirst($portal)]));
        }

        if (! $property->isPortalReady()) {
            return back()->with('error', __('Add a RERA permit, category and intent before publishing to portals.'));
        }

        if ($portal === 'bayut') {
            $credits = app(\App\Services\Portals\BayutCreditsService::class);
            $tenant = $integration->tenant;
            $cost = $credits->costFor($tenant, $property);

            if ($cost > 0) {
                if (! $request->boolean('confirmed')) {
                    return back()->with('error', __('Confirm that this listing may consume :cost Bayut credits before pushing.', ['cost' => $cost]));
                }

                if (! $credits->canAfford($tenant, $property)) {
                    return back()->with('error', __('Not enough Bayut credits. This listing needs :cost credits; add more under Settings → Portal Credits.', ['cost' => $cost]));
                }
            }
        }

        $service = $portal === 'bayut'
            ? new \App\Services\Portals\BayutPortalService($integration)
            : new \App\Services\Portals\PropertyFinderPortalService($integration);

        $result = $service->publish($property);

        $integration->update([
            'last_synced_at' => now(),
            'last_error' => $result['ok'] ? null : ($result['message'] ?? null),
        ]);

        if (! $result['ok']) {
            AuditLog::log('inventory.portal_push_failed_'.$portal, $property, ['error' => $result['message'] ?? null]);

            return back()->with('error', $result['message'] ?? __('The portal rejected the push.'));
        }

        if ($portal === 'bayut') {
            // Bayut owns Dubizzle: it auto-duplicates each listing, so a
            // successful Bayut push also publishes the unit on Dubizzle.
            $property->update(array_filter([
                'bayut_status' => 'live',
                'bayut_listed_at' => now()->toDateString(),
                'bayut_listing_id' => $result['reference'] ?? null,
                'bayut_url' => $result['url'] ?? null,
                'dubizzle_status' => 'live',
                'dubizzle_listing_reference' => $result['reference'] ?? null,
                'dubizzle_listed_at' => now()->toDateString(),
                'dubizzle_url' => $result['url'] ?? null,
            ]));

            app(\App\Services\Portals\BayutCreditsService::class)->consume($tenant, $property);
        } else {
            $property->update(array_filter([
                'propertyfinder_status' => 'live',
                'propertyfinder_listed_at' => now()->toDateString(),
                'propertyfinder_listing_reference' => $result['reference'] ?? null,
                'propertyfinder_url' => $result['url'] ?? null,
            ]));
        }

        AuditLog::log('inventory.portal_pushed_'.$portal, $property, ['reference' => $result['reference'] ?? null]);

        return back()->with('success', __('Submitted to :portal.', ['portal' => $integration->portal_label]));
    }

    /**
     * Refresh the live/removed portal status of every listed unit against Bayut.
     */
    public function syncPortalStatus(Request $request)
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $integration = \App\Models\PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', 'bayut')
            ->where('is_active', true)
            ->first();

        if ($integration === null) {
            return back()->with('error', __('No active Bayut integration to refresh. Set it up under Settings → Portal Integrations.'));
        }

        $result = (new \App\Services\Portals\BayutStatusSyncService($integration))->sync();

        $integration->update([
            'last_synced_at' => now(),
            'last_error' => $result['error'],
        ]);

        AuditLog::log('inventory.portal_status_sync', null, $result);

        if ($result['error'] !== null) {
            return back()->with('error', __('Bayut status refresh failed: :error', ['error' => $result['error']]));
        }

        return back()->with('success', __('Bayut status refresh complete: :checked checked, :live live, :updated status changes, :removed removed.', [
            'checked' => $result['checked'],
            'live' => $result['live'],
            'updated' => $result['updated'],
            'removed' => $result['removed'],
        ]));
    }

    // ── Helpers ─────────────────────────────────────────────

    protected function rules(): array
    {
        return [
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:60',
            'zip_code' => 'nullable|string|max:20',
            'intent' => 'required|in:rent,sale,both',
            'market_class' => 'required|in:ready,off_plan',
            'property_category' => 'required|string|max:40',
            'community' => 'nullable|string|max:120',
            'sub_community' => 'nullable|string|max:120',
            'developer_name' => 'nullable|string|max:150',
            'handover_date' => 'nullable|date',
            'title_deed_no' => 'nullable|string|max:60',
            'rera_permit_no' => 'nullable|string|max:60',
            'plot_no' => 'nullable|string|max:60',
            'building_no' => 'nullable|string|max:60',
            'unit_no' => 'nullable|string|max:60',
            'floor_no' => 'nullable|string|max:60',
            'bedrooms' => 'nullable|integer|min:0',
            'bathrooms' => 'nullable|integer|min:0',
            'square_footage' => 'nullable|numeric|min:0',
            'parking' => 'nullable|integer|min:0',
            'property_type' => 'nullable|string|max:40',
            'furnishing' => 'nullable|in:unfurnished,semi_furnished,furnished',
            'service_charge' => 'nullable|numeric|min:0',
            'rent_price' => 'nullable|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'admin_fee' => 'nullable|numeric|min:0',
            'tawtheeq_fee' => 'nullable|numeric|min:0',
            'rent_period' => 'nullable|in:yearly,monthly',
            'list_price' => 'nullable|numeric|min:0',
            'availability' => 'required|in:draft,ready_to_list,listed,reserved,leased,sold,unlisted',
            'assigned_agent_id' => 'nullable|exists:users,id',
            'owner_name' => 'nullable|string|max:150',
            'owner_phone' => 'nullable|string|max:30',
            'owner_email' => 'nullable|email|max:190',
            'marketing_title' => 'nullable|string|max:200',
            'marketing_description' => 'nullable|string',
            'virtual_tour_url' => 'nullable|url|max:500',
            'notes' => 'nullable|string|max:2000',
            'photos.*' => 'nullable|image|max:10240',
            'floor_plan' => 'nullable|image|max:10240',
            'external_photo_urls' => 'nullable|string',
        ];
    }

    protected function syncMedia(Request $request, Property $property): void
    {
        $uploads = [];
        $cloud = app(\App\Services\Cloud\CloudPhotoService::class);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file->isValid()) {
                    $stored = $cloud->store($file, 'properties/'.$property->id.'/'.Str::random(6), auth()->user());
                    $uploads[] = [
                        'type' => 'photo',
                        'path' => $stored['path'],
                        'external_url' => $stored['external_url'],
                    ];
                }
            }
        }

        if ($request->hasFile('floor_plan') && $request->file('floor_plan')->isValid()) {
            $stored = $cloud->store($request->file('floor_plan'), 'properties/'.$property->id.'/'.Str::random(6), auth()->user());
            $uploads[] = [
                'type' => 'floor_plan',
                'path' => $stored['path'],
                'external_url' => $stored['external_url'],
            ];
        }

        if ($request->filled('external_photo_urls')) {
            foreach (preg_split('/\R/', $request->external_photo_urls) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $uploads[] = ['type' => 'photo', 'external_url' => $line];
                }
            }
        }

        if ($uploads) {
            $property->media()->createMany($uploads);
        }
    }

    protected function agents()
    {
        return \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
            ->whereHas('role', fn ($q) => $q->whereIn('name', \App\Services\BusinessModeService::getRoles()))
            ->orderBy('name')->get(['id', 'name'])
            ->pluck('name', 'id');
    }

    /**
     * Upload photos for a unit from its detail page.
     */
    public function uploadPhotos(Request $request, Property $property)
    {
        $request->validate([
            'photos' => 'required|array|max:10',
            'photos.*' => 'image|mimes:jpg,jpeg,png,gif,webp|max:10240',
            'captions' => 'nullable|array',
            'captions.*' => 'nullable|string|max:255',
        ]);

        $uploaded = 0;
        $cloud = app(\App\Services\Cloud\CloudPhotoService::class);
        foreach ($request->file('photos') as $i => $file) {
            if (! $file->isValid()) {
                continue;
            }

            $stored = $cloud->store($file, 'properties/'.$property->id.'/'.Str::random(6), auth()->user());

            $property->media()->create([
                'type' => 'photo',
                'path' => $stored['path'],
                'external_url' => $stored['external_url'],
                'caption' => $request->input("captions.{$i}"),
                'original_name' => $file->getClientOriginalName(),
                'uploaded_by' => auth()->id(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'sort_order' => $property->media()->max('sort_order') + 1,
            ]);
            $uploaded++;
        }

        return redirect()->route('inventory.show', $property)
            ->with('success', $uploaded ? __('Photos uploaded.') : __('No valid photo files found.'));
    }

    /**
     * Delete a unit photo.
     */
    public function deletePhoto(Property $property, PropertyMedia $photo)
    {
        if ($photo->property_id !== $property->id || $photo->tenant_id !== auth()->user()->tenant_id) {
            abort(404);
        }

        if ($photo->path) {
            Storage::disk('public')->delete($photo->path);
        }
        $photo->delete();

        return redirect()->route('inventory.show', $property)
            ->with('success', __('Photo deleted.'));
    }

    protected function exportUnits(Request $request, string $statusColumn)
    {
        $units = $this->baseQuery($request)
            ->whereIn('availability', ['ready_to_list', 'listed'])
            ->whereNotNull('intent')
            ->whereNotNull('rera_permit_no')
            ->whereNotNull('property_category')
            ->where($statusColumn, '!=', 'live')
            ->latest('updated_at')
            ->with('media');

        if (auth()->user()->isAdmin() && $request->filled('agent')) {
            $units->where('assigned_agent_id', $request->agent);
        }

        return $units->get();
    }

    protected function exportCsv(Request $request, string $schema)
    {
        $units = $this->exportUnits($request, 'bayut_status');

        $rows = [];
        $rows[] = [
            'Reference', 'PermitNumber', 'Transaction', 'Frequency', 'PropertyType', 'Title',
            'Description', 'Price', 'Beds', 'Baths', 'Area (sqft)', 'Plot No', 'Unit No', 'Building No',
            'City', 'Community', 'Sub-Community', 'Address', 'Furnishing', 'Parking',
            'Developer Name', 'Handover / Possession', 'Title Deed No',
            'Agent Name', 'Agent Email', 'Agent Phone', 'Photos (comma separated)',
        ];

        foreach ($units as $unit) {
            $rows[] = [
                $unit->id,
                $unit->rera_permit_no,
                $unit->intent === 'sale' ? 'SELL' : 'RENT',
                $unit->rent_period === 'monthly' ? 'Monthly' : 'Annual',
                $this->portalCategory($unit),
                $unit->display_name,
                (string) $unit->marketing_description,
                $this->portalPrice($unit) !== null ? (string) (int) $this->portalPrice($unit) : '',
                (string) ($unit->bedrooms ?? 0),
                (string) ($unit->bathrooms ?? 0),
                (string) ($unit->square_footage ?? 0),
                (string) $unit->plot_no,
                (string) $unit->unit_no,
                (string) $unit->building_no,
                ucfirst((string) $unit->city),
                (string) $unit->community,
                (string) ($unit->sub_community ?: $unit->community),
                (string) $unit->address,
                \App\Models\Property::FURNISHING[$unit->furnishing] ?? '',
                (string) ($unit->parking ?? ''),
                (string) $unit->developer_name,
                $unit->handover_date?->format('Y-m-d') ?? '',
                (string) $unit->title_deed_no,
                $this->agentName(),
                $this->agentEmail(),
                $this->agentPhone(),
                implode(',', $unit->photo_urls),
            ];
        }

        $filename = $schema === 'propertyplus' ? 'bayut-dubizzle-feed.csv' : 'portals-feed.csv';

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, $filename, ['Content-Type' => 'text/csv']);
    }

    protected function portalPrice(Property $unit): ?float
    {
        if ($unit->intent === 'sale' || $unit->intent === 'both') {
            return $unit->sale_price;
        }

        return $unit->rent_price;
    }

    protected function portalCategory(Property $unit): string
    {
        $cats = [
            'apartment' => 'Apartment', 'penthouse' => 'Penthouse', 'villa' => 'Villa',
            'villa_compound' => 'Villa Compound', 'townhouse' => 'Townhouse',
            'residential_building' => 'Residential Building', 'hotel_apartment' => 'Hotel Apartment',
            'office' => 'Office', 'shop' => 'Shop', 'showroom' => 'Showroom',
            'warehouse' => 'Warehouse', 'factory' => 'Factory', 'commercial_building' => 'Commercial Building',
            'land' => 'Land', 'other' => 'Commercial / Other',
        ];

        return $cats[$unit->property_category] ?? ucwords(str_replace('_', ' ', (string) $unit->property_category));
    }

    protected function amenities(Property $unit): array
    {
        $items = [];
        if ($unit->parking) {
            $items[] = 'Parking';
        }
        if ($unit->furnishing === 'furnished') {
            $items[] = 'Fully Furnished';
        } elseif ($unit->furnishing === 'semi_furnished') {
            $items[] = 'Partly Furnished';
        }
        if ($unit->virtual_tour_url) {
            $items[] = 'Virtual Tour';
        }

        return $items;
    }

    protected function agentName(): ?string
    {
        return auth()->user()->name ?? null;
    }

    protected function agentEmail(): ?string
    {
        return auth()->user()->email ?? null;
    }

    protected function agentPhone(): ?string
    {
        return auth()->user()->phone ?? null;
    }
}
