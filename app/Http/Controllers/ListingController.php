<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Property;
use App\Models\PropertyMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ListingController extends Controller
{
    /**
     * Units the current user may see in the inventory.
     */
    protected function baseQuery(Request $request)
    {
        $query = Property::with(['assignedAgent', 'media', 'leads']);

        if (!auth()->user()->isAdmin()) {
            $query->where(fn ($q) => $q->where('assigned_agent_id', auth()->id())->orWhereNull('assigned_agent_id'));
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('marketing_title', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('community', 'like', "%{$search}%")
                  ->orWhere('sub_community', 'like', "%{$search}%")
                  ->orWhere('unit_no', 'like', "%{$search}%");
            });
        }

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

        if (auth()->user()->isAdmin() && $request->filled('agent')) {
            $query->where('assigned_agent_id', $request->agent);
        }

        $units = (clone $query)->latest('updated_at')->paginate(20);

        $kpis = [
            'total'       => (clone $query)->count(),
            'active'      => (clone $query)->whereIn('availability', ['ready_to_list', 'listed'])->count(),
            'for_rent'    => (clone $query)->whereIn('intent', ['rent', 'both'])->whereIn('availability', ['ready_to_list', 'listed'])->count(),
            'for_sale'    => (clone $query)->whereIn('intent', ['sale', 'both'])->whereIn('availability', ['ready_to_list', 'listed'])->count(),
            'live_portals'=> (clone $query)->where('availability', 'listed')
                                ->where(function ($q) {
                                    $q->where('bayut_status', 'live')
                                      ->orWhere('dubizzle_status', 'live')
                                      ->orWhere('propertyfinder_status', 'live');
                                })->count(),
        ];

        $agents = auth()->user()->isAdmin()
            ? \App\Models\User::where('tenant_id', auth()->user()->tenant_id)
                ->whereHas('role', fn ($q) => $q->whereIn('name', \App\Services\BusinessModeService::getRoles()))
                ->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('inventory.index', [
            'units' => $units,
            'kpis'  => $kpis,
            'agents' => $agents,
        ]);
    }

    public function create(Request $request)
    {
        return view('inventory.create', [
            'property' => new Property(['availability' => 'draft']),
            'agents'   => $this->agents(),
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
            'agents'   => $this->agents(),
        ]);
    }

    public function edit(Request $request, Property $property)
    {
        $property->load('leads');

        return view('inventory.edit', [
            'property' => $property,
            'agents'   => $this->agents(),
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
            $q = $request->q;
            $query->where(function ($builder) use ($q) {
                $builder->where('marketing_title', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%")
                    ->orWhere('community', 'like', "%{$q}%")
                    ->orWhere('sub_community', 'like', "%{$q}%")
                    ->orWhere('developer_name', 'like', "%{$q}%")
                    ->orWhere('unit_no', 'like', "%{$q}%");
            });
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
                'id'           => $unit->id,
                'label'        => $unit->display_name,
                'meta'         => $unit->price_line,
                'detail'       => trim(
                    implode(' • ', array_filter([
                        $unit->sub_community ?: $unit->community,
                        $unit->bedrooms ? $unit->bedrooms . ' ' . __('BR') : null,
                        $unit->square_footage ? \App\Helpers\TenantFormatHelper::area($unit->square_footage) : null,
                        $unit->unit_no ? __('Unit') . ' ' . $unit->unit_no : null,
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

        if (auth()->user()->isAdmin() && $request->filled('agent')) {
            $query->where('assigned_agent_id', $request->agent);
        }

        $all = $query->latest('updated_at')->get();
        $ready = $all->filter->isPortalReady();

        // Per-portal counters for the current view
        $portals = [
            'bayut'          => ['units' => $ready->filter(fn ($p) => $p->bayut_status !== 'live')->count(), 'live' => $all->where('bayut_status', 'live')->count()],
            'dubizzle'       => ['units' => $ready->filter(fn ($p) => $p->dubizzle_status !== 'live')->count(), 'live' => $all->where('dubizzle_status', 'live')->count()],
            'propertyfinder' => ['units' => $ready->filter(fn ($p) => $p->propertyfinder_status !== 'live')->count(), 'live' => $all->where('propertyfinder_status', 'live')->count()],
        ];

        return view('inventory.portal', [
            'units' => $all,
            'ready' => $ready,
            'portals' => $portals,
            'enabled_portals' => \App\Models\PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
                ->where('is_active', true)
                ->pluck('portal')
                ->all(),
            'agents' => auth()->user()->isAdmin()
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
            'bayut'          => ['status' => 'bayut_status', 'reference' => 'bayut_listing_id', 'url' => 'bayut_url', 'listed_at' => 'bayut_listed_at'],
            'dubizzle'       => ['status' => 'dubizzle_status', 'reference' => 'dubizzle_listing_reference', 'url' => 'dubizzle_url', 'listed_at' => 'dubizzle_listed_at'],
            'propertyfinder' => ['status' => 'propertyfinder_status', 'reference' => 'propertyfinder_listing_reference', 'url' => 'propertyfinder_url', 'listed_at' => 'propertyfinder_listed_at'],
        ][$portal];

        $property->update([
            $map['status']    => $data['status'],
            $map['reference'] => $data['listing_reference'] ?? null,
            $map['url']       => $data['url'] ?? null,
            $map['listed_at'] => $data['status'] === 'live' && empty($data['listed_at']) ? now()->toDateString() : ($data['listed_at'] ?? null),
        ]);

        AuditLog::log('inventory.portal_status_' . $portal, $property, ['portal' => $portal, 'status' => $data['status']]);

        return redirect()->route('inventory.portal')->with('success', __('Portal status recorded.'));
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

        $service = $portal === 'bayut'
            ? new \App\Services\Portals\BayutPortalService($integration)
            : new \App\Services\Portals\PropertyFinderPortalService($integration);

        $result = $service->publish($property);

        $integration->update([
            'last_synced_at' => now(),
            'last_error'     => $result['ok'] ? null : ($result['message'] ?? null),
        ]);

        if (! $result['ok']) {
            AuditLog::log('inventory.portal_push_failed_' . $portal, $property, ['error' => $result['message'] ?? null]);

            return back()->with('error', $result['message'] ?? __('The portal rejected the push.'));
        }

        if ($portal === 'bayut') {
            $property->update(array_filter([
                'bayut_status'   => 'live',
                'bayut_listed_at' => now()->toDateString(),
                'bayut_listing_id' => $result['reference'] ?? null,
                'bayut_url'      => $result['url'] ?? null,
            ]));
        } else {
            $property->update(array_filter([
                'propertyfinder_status'   => 'live',
                'propertyfinder_listed_at' => now()->toDateString(),
                'propertyfinder_listing_reference' => $result['reference'] ?? null,
                'propertyfinder_url'      => $result['url'] ?? null,
            ]));
        }

        AuditLog::log('inventory.portal_pushed_' . $portal, $property, ['reference' => $result['reference'] ?? null]);

        return back()->with('success', __('Submitted to :portal.', ['portal' => $integration->portal_label]));
    }

    // ── Helpers ─────────────────────────────────────────────

    protected function rules(): array
    {
        return [
            'address'     => 'nullable|string|max:255',
            'city'        => 'nullable|string|max:100',
            'state'       => 'nullable|string|max:60',
            'zip_code'    => 'nullable|string|max:20',
            'intent'      => 'required|in:rent,sale,both',
            'market_class' => 'required|in:ready,off_plan',
            'property_category' => 'required|string|max:40',
            'community'   => 'nullable|string|max:120',
            'sub_community' => 'nullable|string|max:120',
            'developer_name' => 'nullable|string|max:150',
            'handover_date' => 'nullable|date',
            'title_deed_no' => 'nullable|string|max:60',
            'rera_permit_no' => 'nullable|string|max:60',
            'plot_no'     => 'nullable|string|max:60',
            'building_no' => 'nullable|string|max:60',
            'unit_no'     => 'nullable|string|max:60',
            'floor_no'    => 'nullable|string|max:60',
            'bedrooms'    => 'nullable|integer|min:0',
            'bathrooms'   => 'nullable|integer|min:0',
            'square_footage' => 'nullable|numeric|min:0',
            'parking'     => 'nullable|integer|min:0',
            'property_type' => 'nullable|string|max:40',
            'furnishing'  => 'nullable|in:unfurnished,semi_furnished,furnished',
            'service_charge' => 'nullable|numeric|min:0',
            'rent_price'  => 'nullable|numeric|min:0',
            'rent_period' => 'nullable|in:yearly,monthly',
            'list_price'  => 'nullable|numeric|min:0',
            'availability' => 'required|in:draft,ready_to_list,listed,reserved,leased,sold,unlisted',
            'assigned_agent_id' => 'nullable|exists:users,id',
            'owner_name'  => 'nullable|string|max:150',
            'owner_phone' => 'nullable|string|max:30',
            'owner_email' => 'nullable|email|max:190',
            'marketing_title' => 'nullable|string|max:200',
            'marketing_description' => 'nullable|string',
            'virtual_tour_url' => 'nullable|url|max:500',
            'notes'       => 'nullable|string|max:2000',
            'photos.*'    => 'nullable|image|max:10240',
            'floor_plan'  => 'nullable|image|max:10240',
            'external_photo_urls' => 'nullable|string',
        ];
    }

    protected function syncMedia(Request $request, Property $property): void
    {
        $uploads = [];

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $file) {
                if ($file->isValid()) {
                    $uploads[] = [
                        'type' => 'photo',
                        'path' => $file->store('properties/' . $property->id . '/' . Str::random(6), 'public'),
                    ];
                }
            }
        }

        if ($request->hasFile('floor_plan') && $request->file('floor_plan')->isValid()) {
            $uploads[] = [
                'type' => 'floor_plan',
                'path' => $request->file('floor_plan')->store('properties/' . $property->id . '/' . Str::random(6), 'public'),
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
            'photos'   => 'required|array|max:10',
            'photos.*' => 'image|mimes:jpg,jpeg,png,gif,webp|max:10240',
            'captions' => 'nullable|array',
            'captions.*' => 'nullable|string|max:255',
        ]);

        $uploaded = 0;
        foreach ($request->file('photos') as $i => $file) {
            if (! $file->isValid()) {
                continue;
            }

            $path = $file->store('properties/' . $property->id . '/' . Str::random(6), 'public');

            $property->media()->create([
                'type'          => 'photo',
                'path'          => $path,
                'caption'       => $request->input("captions.{$i}"),
                'original_name' => $file->getClientOriginalName(),
                'uploaded_by'   => auth()->id(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
                'sort_order'    => $property->media()->max('sort_order') + 1,
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