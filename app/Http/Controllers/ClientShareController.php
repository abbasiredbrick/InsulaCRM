<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\MapLocation;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ShareInterest;
use App\Services\LeadDistributionService;
use App\Services\TenantMailConfigurator;
use App\Support\InventorySearchParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Client-facing "shared inventory" links.
 *
 * An agent picks filters in the inventory (e.g. 1BR) and shares a link like
 * /s/{tenant-slug}?bedrooms=1. The visitor verifies their identity (or becomes
 * a new lead captured from the link's filters), then browses the available
 * units and can flag units they are interested in, which link the unit to
 * their lead record.
 */
class ClientShareController extends Controller
{
    protected function tenant(string $slug): Tenant
    {
        return Tenant::where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();
    }

    protected function cookieName(Tenant $tenant): string
    {
        return 'keystone_share_'.$tenant->id;
    }

    /**
     * The lead currently viewing this share link, or null when unverified.
     */
    protected function identify(Tenant $tenant, Request $request): ?Lead
    {
        $payload = $this->decodePayload((string) $request->cookie($this->cookieName($tenant)));

        if (! is_array($payload) || empty($payload['lead'])) {
            return null;
        }

        return Lead::withoutGlobalScopes()
            ->where('id', $payload['lead'])
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    protected function remember(Lead $lead): void
    {
        Cookie::queue(
            $this->cookieName($lead->tenant),
            json_encode(['lead' => $lead->id, 'tenant' => $lead->tenant_id]),
            60 * 24 * 365,
            '/',
            null,
            false,
            true
        );
    }

    /**
     * Read our identity cookie. It is transparent (already decrypted by the
     * framework) in normal requests, but some contexts hand it over still
     * encrypted, so both forms are accepted.
     */
    protected function decodePayload(string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $payload = json_decode($value, true);
        if (is_array($payload)) {
            return $payload;
        }

        try {
            $value = Crypt::decryptString($value);
        } catch (\Throwable) {
            try {
                $value = Crypt::decrypt($value);
            } catch (\Throwable) {
                return null;
            }
        }

        // Laravel signs cookies with a "{hash}|" prefix when encrypting them.
        if (($pos = strpos($value, '|')) !== false && ctype_xdigit(substr($value, 0, $pos))) {
            $value = substr($value, $pos + 1);
        }

        $payload = json_decode($value, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Units the client may browse: on the market for rent, currently available.
     */
    protected function baseQuery(Tenant $tenant, Request $request)
    {
        $query = Property::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('intent', ['rent', 'both'])
            ->whereIn('availability', ['ready_to_list', 'listed'])
            ->with('media');

        if ($request->filled('search')) {
            InventorySearchParser::apply($query, $request->search);
        }

        if ($request->filled('bedrooms') && is_numeric($request->bedrooms)) {
            $query->where('bedrooms', (int) $request->bedrooms);
        }

        if ($request->filled('community')) {
            $query->where('community', $request->community);
        }

        if ($request->filled('building')) {
            $query->where('sub_community', $request->building);
        }

        if ($request->filled('max_rent') && is_numeric($request->max_rent)) {
            $query->where('rent_price', '<=', (float) $request->max_rent);
        }

        if ($request->filled('furnishing')) {
            $query->where('furnishing', $request->furnishing);
        }

        if ($request->filled('maids_room')) {
            $query->where('maids_room', true);
        }

        if ($request->filled('category')) {
            $query->where('property_category', $request->category);
        }

        return $query;
    }

    /**
     * Per-tenant web app manifest so the shared portal installs like an app.
     *
     * The global manifest.json targets the staff CRM (start_url /dashboard).
     * Installing the portal from a share link must open the portal, not the
     * staff login, so the manifest is resolved for the tenant, scoped to its
     * /s/{slug} tree.
     */
    public function manifest(string $slug)
    {
        $tenant = $this->tenant($slug);

        $shortName = mb_strlen($tenant->name) <= 16
            ? $tenant->name
            : __('Keystone Portal');

        return response()->json([
            'name' => $tenant->name.' — '.__('Available Units'),
            'short_name' => $shortName,
            'description' => __('Browse the available units of :name.', ['name' => $tenant->name]),
            'start_url' => url('/s/'.$tenant->slug),
            'id' => '/s/'.$tenant->slug.'/',
            'scope' => '/s/'.$tenant->slug.'/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#17212f',
            'orientation' => 'any',
            'categories' => ['business', 'realestate'],
            'icons' => [
                ['src' => asset('img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => asset('img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * Show the shared inventory (after identity verification) or the gate.
     */
    public function index(Request $request, string $slug)
    {
        $tenant = $this->tenant($slug);

        // The share pages are public — there is no authenticated user for the
        // helper to resolve a tenant from, so without this every price would
        // fall back to the USD default. Pin the tenant being viewed.
        \App\Helpers\TenantFormatHelper::setTenant($tenant);

        $lead = $this->identify($tenant, $request);

        if (! $lead) {
            return view('share.verify', [
                'tenant' => $tenant,
                'filters' => $request->query(),
            ]);
        }

        $units = $this->baseQuery($tenant, $request)
            ->latest('updated_at')
            ->take(120)
            ->get();

        $unitsQuery = $this->baseQuery($tenant, $request);
        $communities = (clone $unitsQuery)->distinct()->pluck('community')->filter()->sort()->values();
        $buildings = (clone $unitsQuery)->distinct()->pluck('sub_community')->filter()->sort()->values();

        $interested = $lead->properties()->pluck('properties.id')->all();

        // Location per building (sub_community), not per unit: every unit under
        // a sub_community shares the same place. One pin per building; hovering
        // a pin shows how many of the currently visible units are available
        // there, and clicking offers Google Maps / Waze directions.
        $maps = app(\App\Services\MapLocationService::class);
        $subCommunities = $units->pluck('sub_community')->filter()->unique()->values();
        $mapLocations = MapLocation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('sub_community', $subCommunities)
            ->get()
            ->keyBy('sub_community');

        $unitCounts = $units->groupBy('sub_community')->map->count();

        $mapPins = [];
        foreach ($unitCounts as $building => $count) {
            $location = $mapLocations[$building] ?? null;
            if ($location === null || $location->latitude === null || $location->longitude === null) {
                continue;
            }

            $query = $location->map_query ?: $maps->queryForLocation($location);

            $mapPins[] = [
                'lat' => (float) $location->latitude,
                'lng' => (float) $location->longitude,
                'name' => $building,
                'count' => $count,
                'query' => $query,
                'google' => $maps->directionsUrl($query),
                'waze' => $maps->wazeUrl($location),
            ];
        }

        // Fallback for tenants with no geocoded building yet: still show a
        // keyless embed of the first located building rather than nothing.
        $mapEmbed = null;
        if ($mapPins === []) {
            foreach ($units as $unit) {
                $location = $unit->sub_community ? ($mapLocations[$unit->sub_community] ?? null) : null;
                if ($location && $location->map_query !== null && $location->map_query !== '') {
                    $mapEmbed = [
                        'query' => $location->map_query,
                        'embed' => $maps->embedUrl($location->map_query),
                    ];
                    break;
                }
            }
        }

        return view('share.inventory', [
            'tenant' => $tenant,
            'lead' => $lead,
            'units' => $units,
            'communities' => $communities,
            'buildings' => $buildings,
            'interested' => $interested,
            'filters' => $request->query(),
            'mapPins' => $mapPins,
            'mapEmbed' => $mapEmbed,
            'mapLocations' => $mapLocations,
            'maps' => $maps,
        ]);
    }

    /**
     * Verify the visitor: match an existing lead, else create a new lead
     * carrying the filters captured from the shared link.
     */
    public function verify(Request $request, string $slug)
    {
        $tenant = $this->tenant($slug);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'required|string|max:24',
            'email' => 'nullable|email|max:255',
        ]);

        $phone = $this->normalizePhone($validated['phone'] ?? null);

        // The phone is how the team reaches the client (call/WhatsApp), so it
        // must be a real international number: a leading "+" with the country
        // code and the subscriber digits, E.164 style. Anything else is
        // rejected here and the visitor never proceeds.
        if ($phone !== null && ! preg_match('/^\+[1-9][0-9]{4,14}$/', $phone)) {
            throw ValidationException::withMessages([
                'phone' => __('Enter the phone with the country code, e.g. +971501234567.'),
            ]);
        }

        // The agent who generated the share link is recorded as ?agent=<code>.
        // Their credit only counts while they are an active, lead-receiving
        // member (see attributedAgent()); otherwise the lead falls through to
        // the normal distribution.
        $sharer = $this->attributedAgent($tenant, trim((string) ($request->query('agent') ?? '')));

        // Attribution is transport, not a filter: keep it out of the lead's
        // note and captured filters. The URL still carries it afterwards, so
        // the visitor stays on the exact link they were shared and any lead
        // that comes back through it keeps what attribution it is entitled to.
        $agentParam = $request->query('agent');
        $filters = $request->query();
        unset($filters['agent']);

        $lead = null;
        if (! empty($phone)) {
            // Portal-synced leads used to carry the number exactly as the
            // portal sent it, spaces and all ("+971 50 123 4567"). Match those
            // legacy rows on their digits too, or a client who types the
            // compact number on a share link spawns a duplicate of himself.
            $digits = preg_replace('/\D+/', '', $phone);
            $lead = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) use ($phone, $digits) {
                    $q->where('phone', $phone)
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, "+", ""), " ", ""), "-", ""), "(", ""), ")", "") = ?', [$digits]);
                })
                ->first();
        }
        if (! $lead && ! empty($validated['email'])) {
            $lead = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('email', trim($validated['email']))
                ->first();
        }

        if ($lead) {
            AuditLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'user_id' => null,
                'action' => 'lead.verified_share_link',
                'model_type' => Lead::class,
                'model_id' => $lead->id,
                'new_values' => ['filters' => $filters, 'agent_code' => $sharer?->agent_code],
            ]);
        } else {
            $customFields = ['share_link_filters' => $this->cleanFilters($filters)];
            if ($sharer) {
                $customFields['share_link_agent_code'] = $sharer->agent_code;
            }

            $lead = Lead::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? '',
                'phone' => $phone,
                'email' => $validated['email'] ?? null,
                'lead_source' => 'Share Link',
                'status' => 'new',
                'temperature' => 'warm',
                'deal_type' => 'rent',
                'stage' => 'new_lead',
                'notes' => $this->filterNote($filters)
                    ? 'Came in from the shared availability link. Looking for: '.$this->filterNote($filters).'.'
                    : 'Came in from the shared availability link.',
                'custom_fields' => $customFields,
            ]);

            AuditLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'user_id' => null,
                'action' => 'lead.created_via_share_link',
                'model_type' => Lead::class,
                'model_id' => $lead->id,
                'new_values' => [
                    'filters' => $this->cleanFilters($filters),
                    'agent_code' => $sharer?->agent_code,
                ],
            ]);

            if ($sharer) {
                // The sharer's own link is a directed referral: the lead goes
                // straight onto their book, bypassing the rotation exactly as
                // portal listing enquiries route by agent code.
                $lead->agent_id = $sharer->id;
                $lead->save();
            } else {
                try {
                    app(LeadDistributionService::class)->distribute($lead, $tenant);
                } catch (\Throwable $e) {
                    Log::warning('Share link lead distribution failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->remember($lead);

        return redirect()->route('share.inventory', array_merge(
            ['slug' => $tenant->slug],
            $filters,
            $agentParam !== null ? ['agent' => (string) $agentParam] : []
        ));
    }

    /**
     * The agent a share link attributes its leads to, resolved from the
     * ?agent=<code> parameter embedded by the inventory "Copy share link"
     * button.
     *
     * Mirrors the portal listing rule (PortalLeadService::findAgentByCode):
     * only an active member who has not opted out of incoming leads is
     * credited. A member who switched receives_leads off must not be handed
     * share-link leads behind the distribution formula's back, so they fall
     * through to the rotation instead.
     */
    protected function attributedAgent(Tenant $tenant, string $agentCode): ?User
    {
        if ($agentCode === '') {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('agent_code', $agentCode)
            ->where('is_active', true)
            ->where('receives_leads', true)
            ->first();
    }

    /**
     * Record that the verified client is interested in a unit.
     */
    public function interest(Request $request, string $slug, string $propertyId)
    {
        $tenant = $this->tenant($slug);
        $lead = $this->identify($tenant, $request);

        if (! $lead) {
            return redirect()->route('share.inventory', array_merge(['slug' => $slug], $request->query()));
        }

        $property = Property::withoutGlobalScopes()
            ->where('id', (int) $propertyId)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $property->leads()->syncWithoutDetaching([
            $lead->id => ['relation_type' => 'interest'],
        ]);

        app(\App\Services\UnitLeadAssignmentService::class)->assignToUnitOwnerIfRequired($lead->fresh() ?? $lead, $property);

        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'action' => 'lead.interested_in_unit',
            'model_type' => Lead::class,
            'model_id' => $lead->id,
            'new_values' => ['property_id' => $property->id],
        ]);

        $this->notifyAssignedAgent($tenant, $lead, $property);

        return redirect()->route('share.inventory', array_merge(['slug' => $slug], $request->query()))
            ->with('interest', $property->id);
    }

    /**
     * Email the unit's assigned agent about a new interest. Failed sends are
     * logged, never surfaced as errors — the client's redirect must not break.
     */
    protected function notifyAssignedAgent(Tenant $tenant, Lead $lead, Property $property): void
    {
        $agent = $property->assignedAgent;

        if (! $agent || ! $agent->email) {
            return;
        }

        try {
            app(TenantMailConfigurator::class)->apply($tenant);
            Notification::route('mail', $agent->email)->notify(new ShareInterest($lead, $property));
        } catch (\Throwable $e) {
            Log::warning('Share interest email failed', [
                'tenant_id' => $tenant->id,
                'lead_id' => $lead->id,
                'property_id' => $property->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forget the visitor's identity cookie (switch viewer / reset).
     */
    public function logout(Request $request, string $slug)
    {
        $tenant = $this->tenant($slug);
        Cookie::queue(Cookie::forget($this->cookieName($tenant)));

        return redirect()->route('share.inventory', array_merge(['slug' => $slug], $request->query()));
    }

    /**
     * Clean a phone typed into the share form: drop the cosmetic separators
     * (spaces, dashes, dots, parentheses) so "+971 50 123 4567" stores as
     * "+971501234567". A value that does not start with "+" is returned as-is
     * so the E.164 check rejects it instead of silently repairing it.
     */
    protected function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        if (! str_starts_with($phone, '+')) {
            return $phone;
        }

        return str_replace([' ', '-', '.', '(', ')'], '', $phone);
    }

    /**
     * Human-readable summary of the filters captured from the shared link.
     */
    protected function filterNote(array $filters): string
    {
        $bits = [];
        if (isset($filters['bedrooms']) && $filters['bedrooms'] !== '') {
            $bits[] = ((int) $filters['bedrooms'] === 0 ? 'Studio' : ((int) $filters['bedrooms'].'BR'));
        }
        if (isset($filters['community']) && $filters['community'] !== '') {
            $bits[] = (string) $filters['community'];
        }
        if (isset($filters['building']) && $filters['building'] !== '') {
            $bits[] = (string) $filters['building'];
        }
        if (isset($filters['max_rent']) && $filters['max_rent'] !== '') {
            $bits[] = 'max AED '.number_format((float) $filters['max_rent']);
        }
        if (isset($filters['furnishing']) && $filters['furnishing'] !== '') {
            $bits[] = ucfirst(str_replace('_', ' ', (string) $filters['furnishing']));
        }
        if (isset($filters['maids_room']) && $filters['maids_room'] !== '') {
            $bits[] = __("Maid's room");
        }

        return implode(' · ', $bits);
    }

    protected function cleanFilters(array $filters): array
    {
        return array_filter($filters, fn ($v) => $v !== null && $v !== '');
    }
}
