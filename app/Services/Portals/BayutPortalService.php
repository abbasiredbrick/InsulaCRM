<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BayutPortalService
{
    protected static ?int $agentId = null;

    /**
     * Bayut api-v7 category ids confirmed via GET /categories (leaf nodes).
     */
    protected const CATEGORY_IDS = [
        'apartment' => 4,
        'villa' => 3,
        'townhouse' => 16,
        'residential_building' => 17,
        'office' => 5,
        'shop' => 6,
        'warehouse' => 7,
        'factory' => 8,
    ];

    public function __construct(protected PortalIntegration $integration)
    {
    }

    public function publish(Property $property): array
    {
        if (blank($this->integration->base_url)) {
            return $this->error('Bayut Push API base URL is missing. Add it under Settings → Portal Integrations.');
        }

        if (blank($property->bayut_location_id) && blank($this->integration->default_location_id)) {
            return $this->error('Select a Bayut location for this unit first (location picker on the unit page), or set a default location under Settings → Portal Integrations.');
        }

        if (($property->market_class ?? 'ready') === 'off_plan' && in_array($property->intent, ['rent', 'both'], true)) {
            return $this->error('This unit is in an off-plan development; Bayut only allows off-plan projects to be listed for sale, not rent. Set the intent to Sale, or switch the unit to Ready/Secondary once completed.');
        }

        $agentId = $this->resolveAgentId();
        if ($agentId === null) {
            return $this->error('Could not resolve a Bayut agent id for this account. Check the API token under Settings → Portal Integrations.');
        }

        $payload = $this->payload($property);

        try {
            $response = Http::withToken($this->integration->api_token)
                ->asJson()
                ->acceptJson()
                ->timeout(60)
                ->post(rtrim($this->integration->base_url, '/') . '/listings', $payload);
        } catch (\Throwable $e) {
            return $this->error('Could not reach Bayut: ' . $e->getMessage());
        }

        return $this->interpret($response, $property);
    }

    public function unpublish(Property $property): array
    {
        $reference = $property->bayut_listing_id;

        if (blank($reference)) {
            return $this->error('This unit has no Bayut listing reference to remove.');
        }

        if (blank($this->integration->base_url)) {
            return $this->error('Bayut Push API base URL is missing.');
        }

        $response = Http::withToken($this->integration->api_token)
            ->asJson()
            ->acceptJson()
            ->delete(rtrim($this->integration->base_url, '/') . '/listings/' . $reference);

        return $this->interpret($response, $property);
    }

    public function test(): array
    {
        if (blank($this->integration->base_url)) {
            return $this->error('Set the Bayut Push API base URL (from your Bayut Pro account) before testing.');
        }

        try {
            $response = Http::withToken($this->integration->api_token)
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim($this->integration->base_url, '/') . '/agents');

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'message' => 'Connected. ' . $this->summarize($response),
                ];
            }

            $body = Str::limit((string) $response->body(), 300);

            return $this->error('Bayut responded HTTP ' . $response->status() . '. ' . $body);
        } catch (\Throwable $e) {
            return $this->error('Could not reach Bayut: ' . $e->getMessage());
        }
    }

    /**
     * Pull the agency's publishable location catalog from Bayut and persist it
     * on the integration so listings can be placed from a trusted list in the UI.
     */
    /**
     * Sync the Bayut location catalog by paging through every page. Bayut
     * hard-caps each page at 15 results across ~1,428 pages (~21.4k locations),
     * so the sync is resumable: each call advances `location_sync_page` by up to
     * Budget net until page exhaustion, and repeats can finish the job.
     */
    public function syncLocations(): array
    {
        if (blank($this->integration->base_url)) {
            return $this->error('Bayut Push API base URL is missing.');
        }

        $existing = $this->integration->location_catalog ?? [];
        $byId = [];
        $catalog = [];

        foreach ($existing as $option) {
            $id = (int) ($option['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[(string) $id] = true;
            $catalog[] = ['id' => $id, 'label' => (string) ($option['label'] ?? ('Location ' . $id))];
        }

        $page = max(1, (int) ($this->integration->location_sync_page ?? 0) + 1);
        $start = microtime(true);
        $lastPage = 1;

        try {
            do {
                $response = Http::withToken($this->integration->api_token)
                    ->acceptJson()
                    ->timeout(20)
                    ->get(rtrim($this->integration->base_url, '/') . '/locations', ['page' => $page]);

                if (! $response->successful()) {
                    return $this->error('Bayut responded HTTP ' . $response->status() . ' on page ' . $page . ': ' . Str::limit((string) $response->body(), 300));
                }

                $payload = $response->json() ?? [];
                $batch = $payload['data'] ?? $payload['results'] ?? (is_array($payload) && array_is_list($payload) ? $payload : null);

                if (! is_array($batch)) {
                    break;
                }

                foreach ($batch as $location) {
                    $id = $location['id'] ?? $location['locationId'] ?? null;
                    if ($id === null || isset($byId[(string) $id])) {
                        continue;
                    }

                    $breadcrumb = $location['breadcrumb']['en'] ?? $location['breadcrumb'] ?? [];
                    $label = trim(implode(' | ', array_values(array_filter((array) $breadcrumb))));

                    if (blank($label)) {
                        $label = $location['title']['en'] ?? $location['name'] ?? ('Location ' . $id);
                    }

                    $byId[(string) $id] = true;
                    $catalog[] = ['id' => (int) $id, 'label' => $label];
                }

                $paginator = $payload['meta'] ?? $payload['pagination'] ?? null;
                if ($paginator) {
                    $lastPage = max($lastPage, (int) ($paginator['last_page'] ?? 1));
                }

                $page++;

                if (microtime(true) - $start > 16) {
                    break;
                }
            } while ($page <= $lastPage);
        } catch (\Throwable $e) {
            return $this->error('Could not reach Bayut: ' . $e->getMessage());
        }

        $done = $page - 1;
        $finished = $done >= $lastPage;

        if ($done === 0) {
            $done = 1;
        }

        usort($catalog, fn ($a, $b) => strcmp($a['label'], $b['label']));

        $this->integration->update([
            'location_catalog' => array_values($catalog),
            'location_sync_page' => $finished ? null : $done,
            'locations_synced_at' => now(),
            'last_error' => null,
        ]);

        if ($finished) {
            return [
                'ok' => true,
                'message' => 'Synced all ' . count($catalog) . ' Bayut locations.',
                'locations' => array_values($catalog),
            ];
        }

        return [
            'ok' => true,
            'message' => 'Synced ' . count($catalog) . ' Bayut locations so far (page ' . $done . ' of ~' . $lastPage . ') — press Sync again to continue.',
            'locations' => array_values($catalog),
        ];
    }

    /**
     * The previously-synced location catalog, ready for a <select>.
     */
    public function locationOptions(): array
    {
        return array_values($this->integration->location_catalog ?? []);
    }

    /**
     * Live-search Bayut's location catalog by title/building, proxied for the
     * searchable picker. Bayut caps the response at 15/page and supports the
     * `filter[title]=` query against the full 21k+ catalog.
     */
    public function searchLocations(string $term): array
    {
        $term = trim($term);

        if (blank($term)) {
            return $this->locationOptions();
        }

        if (mb_strlen($term) < 2) {
            return [];
        }

        $matches = [];
        $byId = [];

        foreach ($this->locationOptions() as $option) {
            $byId[(string) $option['id']] = true;
            if (stripos($option['label'], $term) !== false) {
                $matches[] = ['value' => (int) $option['id'], 'label' => $option['label']];
            }
        }

        try {
            $response = Http::withToken($this->integration->api_token)
                ->acceptJson()
                ->timeout(16)
                ->get(rtrim($this->integration->base_url, '/') . '/locations', [
                    'filter[title]' => $term,
                    'page' => 1,
                ]);
        } catch (\Throwable $e) {
            return $matches;
        }

        if (! $response->successful()) {
            return $matches;
        }

        $payload = $response->json() ?? [];
        $page = 1;

        do {
            $batch = $payload['data'] ?? $payload['results'] ?? (is_array($payload) && array_is_list($payload) ? $payload : null);

            if (! is_array($batch)) {
                break;
            }

            foreach ($batch as $location) {
                $id = $location['id'] ?? $location['locationId'] ?? null;
                if ($id === null || isset($byId[(string) $id])) {
                    continue;
                }

                $breadcrumb = $location['breadcrumb']['en'] ?? $location['breadcrumb'] ?? [];
                $label = trim(implode(' | ', array_values(array_filter((array) $breadcrumb))));

                if (blank($label)) {
                    $label = $location['title']['en'] ?? $location['name'] ?? ('Location ' . $id);
                }

                $byId[(string) $id] = true;
                $matches[] = ['value' => (int) $id, 'label' => $label];
            }

            $paginator = $payload['meta'] ?? null;
            $lastPage = $paginator['last_page'] ?? 1;
            $page++;

            if (! $paginator || $page > (int) $lastPage || $page > 4 || count($matches) >= 100) {
                break;
            }

            try {
                $payload = Http::withToken($this->integration->api_token)
                    ->acceptJson()
                    ->timeout(16)
                    ->get(rtrim($this->integration->base_url, '/') . '/locations', [
                        'filter[title]' => $term,
                        'page' => $page,
                    ])
                    ->json() ?? [];
            } catch (\Throwable $e) {
                break;
            }
        } while (true);

        return array_slice($matches, 0, 100);
    }

    protected function payload(Property $property): array
    {
        $payload = [
            'agentId'         => $this->resolveAgentId(),
            'categoryId'      => $this->categoryId($property),
            'purposeId'       => $property->intent === 'sale' ? 1 : 2,
            'locationId'      => (int) ($property->bayut_location_id ?: $this->integration->default_location_id),
            'title'           => ['en' => Str::limit($property->display_name, 120)],
            'description'     => ['en' => Str::limit((string) $property->marketing_description, 4000)],
            'area'            => $this->areaInSqm($property),
            'referenceNumber' => $this->listingReference($property),
            'permitNumber'    => (string) $property->rera_permit_no ?: null,
            'permitType'      => $property->permit_regime === 'abudhabi' ? 'madhmoun' : 'rera',
            'currency'        => 'AED',
            'period'          => $property->rent_period === 'monthly' ? 'monthly' : 'yearly',
            'beds'            => (int) ($property->bedrooms ?? 0),
            'baths'           => (int) ($property->bathrooms ?? 0),
            'furnished'       => $property->furnishing === 'furnished',
        ];

        if (in_array($property->intent, ['sale', 'both'], true)) {
            $payload['price'] = $this->price($property);
        }

        if (in_array($property->intent, ['rent', 'both'], true)) {
            $payload['rentPrice'] = $this->price($property);
        }

        $images = array_values($property->photo_urls);
        if ($images) {
            // Bayut's api-v7 accepts media as [{path, collection}]; the older
            // `images` array of URLs is silently ignored. `collection` must be
            // "images" (validated server-side).
            $payload['media'] = array_map(
                fn (string $url) => ['path' => $url, 'collection' => 'images'],
                $images
            );
        }

        return $payload;
    }

    protected function interpret($response, Property $property): array
    {
        if ($response->successful()) {
            $body = $response->json() ?? [];
            $status = strtolower((string) ($body['status'] ?? $body['state'] ?? ''));
            $isLive = in_array($status, ['', 'live', 'published', 'active', 'approved', 'listed'], true);

            return [
                'ok'        => true,
                'status'    => $status !== '' ? $status : 'live',
                'reference' => $body['id'] ?? $body['referenceNumber'] ?? $body['reference'] ?? $body['listing']['id'] ?? null,
                'url'       => $body['url'] ?? $body['link'] ?? null,
                'message'   => $isLive ? 'Accepted by Bayut.' : $this->draftMessage($body, $status),
                'raw'       => $body,
            ];
        }

        $body = $response->json() ?? [];

        $messages = [];
        if (isset($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $field => $fieldMessages) {
                foreach ((array) $fieldMessages as $message) {
                    $messages[] = is_string($message) ? $message : json_encode($message);
                }
            }
        }
        if ($body['message'] ?? null) {
            $messages[] = $body['message'];
        }

        $text = $messages
            ? implode(' ', array_values(array_unique($messages)))
            : Str::limit((string) $response->body(), 400);

        if (preg_match('/\b(BRN|BLN|license number|broker)/i', $text)) {
            $text .= ' Tip: Bayut requires an approved agent profile — a valid BRN (Dubai) or BLN (Abu Dhabi/Al Ain) must be set on the Bayut agent before it can publish.';
        }

        return $this->error('Bayut rejected the listing (HTTP ' . $response->status() . '): ' . $text);
    }

    /**
     * Bayut often answers with HTTP 2xx but keeps the ad in `draft` (e.g. the
     * agent profile or permit is awaiting review). Explain why so the agent is
     * not left thinking a draft is live on the portal.
     */
    protected function draftMessage(array $body, string $status): string
    {
        $reasons = [];

        if (($body['permitNumberStatus'] ?? null) === 'pending') {
            $reasons[] = 'the permit number is pending Bayut compliance review';
        }

        if (array_key_exists('activeImagesCount', $body) && (int) $body['activeImagesCount'] === 0) {
            $reasons[] = 'no listing photos were attached';
        }

        $text = 'Bayut accepted the listing but kept it as a DRAFT (status: ' . $status . '), so it is not visible on the portal yet';

        if ($reasons) {
            $text .= ' — ' . implode('; ', $reasons);
        }

        if ($issue = $this->agentProfileIssue()) {
            $text .= '. ' . $issue;
        }

        $canActivate = ! empty($body['canBeActivated']);

        $text .= '. Complete the Bayut agent profile and permit, then activate the listing from Bayut Profolio'
            . ($canActivate ? ' (this listing can be activated).' : '.');

        return $text;
    }

    /**
     * The Bayut API happily creates ads under an agent whose profile is not yet
     * approved; those ads stay drafts. Report the profile state when it is not on.
     */
    protected function agentProfileIssue(): ?string
    {
        try {
            $response = Http::withToken($this->integration->api_token)
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim($this->integration->base_url, '/') . '/agents');
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        foreach ((array) ($response->json('data') ?? $response->json() ?? []) as $agent) {
            if ((int) ($agent['id'] ?? 0) !== (int) static::$agentId) {
                continue;
            }

            $status = $agent['status'] ?? null;
            $reason = $agent['meta']['rejection_reason'] ?? null;

            if ($status && $status !== 'on') {
                return 'The Bayut agent profile is "' . $status . '"'
                    . ($reason ? ' (' . $reason . ')' : '') . ', so it cannot publish live ads yet';
            }

            return null;
        }

        return null;
    }

    /**
     * The Bayut listing reference includes the listing agent's code so that
     * incoming leads carry their owner: {AGENTCODE}-{PROPERTYID} e.g. AJ07-416.
     */
    protected function listingReference(Property $property): string
    {
        $code = app(\App\Services\AgentCodeService::class)->codeForProperty($property);

        return $code !== null ? $code . '-' . $property->id : (string) $property->id;
    }

    protected function categoryId(Property $property): ?int
    {
        return self::CATEGORY_IDS[$property->property_category] ?? null;
    }

    protected function category(Property $property): string
    {
        $map = [
            'apartment' => 'Apartment', 'penthouse' => 'Penthouse', 'villa' => 'Villa',
            'villa_compound' => 'Villa Compound', 'townhouse' => 'Townhouse',
            'residential_building' => 'Residential Building', 'hotel_apartment' => 'Hotel Apartment',
            'office' => 'Office', 'shop' => 'Shop', 'showroom' => 'Showroom',
            'warehouse' => 'Warehouse', 'factory' => 'Factory', 'commercial_building' => 'Commercial Building',
            'land' => 'Land', 'other' => 'Commercial / Other',
        ];

        return $map[$property->property_category] ?? ucwords(str_replace('_', ' ', (string) $property->property_category));
    }

    protected function price(Property $property): ?float
    {
        if (in_array($property->intent, ['sale', 'both'], true)) {
            return $property->sale_price;
        }

        return $property->rent_price;
    }

    /**
     * Bayut expects the area in square metres; the CRM stores square footage.
     */
    protected function areaInSqm(Property $property): ?float
    {
        if (blank($property->square_footage)) {
            return null;
        }

        return round(((float) $property->square_footage) * 0.092903, 1);
    }

    protected function resolveAgentId(): ?int
    {
        if (static::$agentId !== null) {
            return static::$agentId;
        }

        try {
            $response = Http::withToken($this->integration->api_token)
                ->acceptJson()
                ->timeout(15)
                ->get(rtrim($this->integration->base_url, '/') . '/agents');
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $agents = $response->json('data') ?? $response->json() ?? [];

        foreach ((array) $agents as $agent) {
            if (($agent['status'] ?? '') === 'on' && isset($agent['id'])) {
                return static::$agentId = (int) $agent['id'];
            }
        }

        if ((array) $agents) {
            $first = reset($agents);

            return static::$agentId = (int) ($first['id'] ?? null);
        }

        return null;
    }

    protected function summarize($response): string
    {
        $body = $response->json();
        if (! is_array($body)) {
            return 'Response HTTP ' . $response->status() . '.';
        }

        $count = $body['count'] ?? $body['total'] ?? count($body);

        return 'Endpoint returned ' . $count . ' items.';
    }

    protected function error(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}