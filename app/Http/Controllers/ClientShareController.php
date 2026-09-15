<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Services\LeadDistributionService;
use App\Support\InventorySearchParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

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

        if ($request->filled('category')) {
            $query->where('property_category', $request->category);
        }

        return $query;
    }

    /**
     * Show the shared inventory (after identity verification) or the gate.
     */
    public function index(Request $request, string $slug)
    {
        $tenant = $this->tenant($slug);
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

        return view('share.inventory', [
            'tenant' => $tenant,
            'lead' => $lead,
            'units' => $units,
            'communities' => $communities,
            'buildings' => $buildings,
            'interested' => $interested,
            'filters' => $request->query(),
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
            'last_name' => 'required|string|max:255',
            'phone' => 'required_without:email|nullable|string|max:20',
            'email' => 'required_without:phone|nullable|email|max:255',
        ]);

        $filters = $request->query();

        $lead = null;
        if (! empty($validated['phone'])) {
            $lead = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('phone', trim($validated['phone']))
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
                'new_values' => ['filters' => $filters],
            ]);
        } else {
            $lead = Lead::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'lead_source' => 'Share Link',
                'status' => 'new',
                'temperature' => 'warm',
                'deal_type' => 'rent',
                'stage' => 'new_lead',
                'notes' => $this->filterNote($filters)
                    ? 'Came in from the shared availability link. Looking for: '.$this->filterNote($filters).'.'
                    : 'Came in from the shared availability link.',
                'custom_fields' => [
                    'share_link_filters' => $this->cleanFilters($filters),
                ],
            ]);

            AuditLog::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'user_id' => null,
                'action' => 'lead.created_via_share_link',
                'model_type' => Lead::class,
                'model_id' => $lead->id,
                'new_values' => ['filters' => $this->cleanFilters($filters)],
            ]);

            try {
                app(LeadDistributionService::class)->distribute($lead, $tenant);
            } catch (\Throwable $e) {
                Log::warning('Share link lead distribution failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        $this->remember($lead);

        return redirect()->route('share.inventory', array_merge(['slug' => $tenant->slug], $filters));
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

        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'user_id' => null,
            'action' => 'lead.interested_in_unit',
            'model_type' => Lead::class,
            'model_id' => $lead->id,
            'new_values' => ['property_id' => $property->id],
        ]);

        return redirect()->route('share.inventory', array_merge(['slug' => $slug], $request->query()))
            ->with('interest', $property->id);
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

        return implode(' · ', $bits);
    }

    protected function cleanFilters(array $filters): array
    {
        return array_filter($filters, fn ($v) => $v !== null && $v !== '');
    }
}
