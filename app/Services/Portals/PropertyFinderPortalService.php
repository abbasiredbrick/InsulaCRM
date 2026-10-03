<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use App\Models\Property;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PropertyFinderPortalService
{
    /**
     * Production Property Finder Enterprise API host.
     */
    public const PRODUCTION_BASE_URL = 'https://atlas.propertyfinder.com';

    /**
     * Sandbox host used to test listing publication without touching live units.
     */
    public const SANDBOX_BASE_URL = 'https://sandbox.atlas.propertyfinder.com';

    /**
     * Backwards-compatible alias (legacy code referenced the old host).
     */
    public const DEFAULT_BASE_URL = self::PRODUCTION_BASE_URL;

    /**
     * Leads endpoint caps results at 50 per page.
     */
    public const LEADS_PER_PAGE = 50;

    /**
     * createdAtFrom cannot go further back than 3 months.
     */
    public const LEADS_MAX_LOOKBACK_DAYS = 89;

    protected ?string $token = null;

    protected ?\DateTimeInterface $tokenExpiresAt = null;

    protected ?string $authError = null;

    public function __construct(protected PortalIntegration $integration) {}

    public function publish(Property $property): array
    {
        if ($this->inSandboxMode()) {
            if (blank($this->integration->sandbox_api_token) || blank($this->integration->sandbox_api_secret)) {
                return $this->error('Enter the Property Finder sandbox API key and secret to test in sandbox mode.');
            }
        } elseif (blank($this->integration->api_token) || blank($this->integration->api_secret)) {
            return $this->error('Property Finder API key and secret are required.');
        }

        if (blank($this->integration->public_profile_id)) {
            return $this->error('The Property Finder public profile ID is required to publish.');
        }

        if (blank($this->integration->default_location_id)) {
            return $this->error('A Property Finder default location ID is required to publish.');
        }

        $payload = $this->payload($property);

        if (($payload['error'] ?? null) !== null) {
            return $this->error($payload['error']);
        }

        // Reference is stable per unit, so re-pushing updates the same listing
        // (PF upserts by reference) instead of piling up drafts.
        $reference = $property->propertyfinder_listing_reference ?: $payload['reference'];

        $draftResponse = $this->send(fn ($client) => $client->post($this->path('/v1/listings'), $payload));

        if (! $draftResponse->successful()) {
            return $this->error('Property Finder create failed (HTTP '.$draftResponse->status().'): '.Str::limit((string) $draftResponse->body(), 500));
        }

        $listingId = $draftResponse->json('id')
            ?? $draftResponse->json('data.id')
            ?? null;

        if ($listingId === null) {
            return $this->error('Property Finder create failed: no listing id returned. '.Str::limit((string) $draftResponse->body(), 300));
        }

        $publishResponse = $this->send(fn ($client) => $client->post($this->path('/v1/listings/'.$listingId.'/publish')));

        if (! $publishResponse->successful()) {
            return $this->error('Property Finder publish failed (HTTP '.$publishResponse->status().'): '.Str::limit((string) $publishResponse->body(), 500));
        }

        $url = $publishResponse->json('url')
            ?? $publishResponse->json('link')
            ?? $this->listingUrl($reference);

        return [
            'ok' => true,
            'reference' => (string) $reference,
            'id' => (string) $listingId,
            'url' => $url,
            'message' => $this->inSandboxMode()
                ? 'Draft created in the Property Finder sandbox and submitted for publication.'
                : 'Draft created and submitted for publication.',
            'raw' => $publishResponse->json(),
        ];
    }

    public function unpublish(Property $property): array
    {
        $client = $this->client();
        if ($client === null) {
            return $this->error('Property Finder authentication failed. Check the API key and secret.');
        }

        $listingId = $this->resolveListingId($property);

        if ($listingId === null) {
            return $this->error('Property Finder could not find a listing for this unit to remove.');
        }

        $response = $client->post($this->path('/v1/listings/'.$listingId.'/unpublish'));

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Unpublished.'];
        }

        return $this->error('Property Finder unpublish failed (HTTP '.$response->status().'): '.Str::limit((string) $response->body(), 500));
    }

    /**
     * Resolve the PF listing id for a unit from the stored reference.
     */
    protected function resolveListingId(Property $property): ?string
    {
        $reference = $property->propertyfinder_listing_reference;

        if (blank($reference)) {
            return null;
        }

        $response = $this->client()?->get($this->base().'/v1/listings?filter[reference]='.urlencode((string) $reference).'&draft=true');

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $results = (array) ($response->json('results') ?? $response->json('data') ?? []);

        foreach ($results as $listing) {
            if (is_array($listing) && ! blank($listing['id'] ?? null)) {
                return (string) $listing['id'];
            }
        }

        return null;
    }

    public function listingUrl(string $reference): ?string
    {
        $slug = Str::slug((string) $reference);

        return 'https://www.propertyfinder.ae'.($slug !== '' ? '/properties/'.$slug : '');
    }

    /**
     * The public property page for a listing reference.
     *
     * The lead webhook never carries a property URL, and the Enterprise API
     * listing payload does not expose one either - verified against
     * atlas.propertyfinder.com: results carry id/reference, location, price,
     * media and state, but no url, publicUrl or slug.
     *
     * So when the payload has no public URL we fall back to PF's own
     * reference search, which resolves (HTTP 200) and surfaces the property.
     * We deliberately do NOT use listingUrl(): propertyfinder.ae/properties/
     * <slug> was tried against four live references and 404s on all of them,
     * and a stored link that 404s is worse than no link.
     */
    public function listingPageUrl(string $reference, ?string $tenantHost = null): ?string
    {
        if (blank($reference)) {
            return null;
        }

        $response = $this->client()?->get($this->base().'/v1/listings?filter[reference]='.urlencode((string) $reference));

        if ($response !== null && $response->successful()) {
            $results = (array) ($response->json('results') ?? $response->json('data') ?? []);

            foreach ($results as $listing) {
                if (! is_array($listing)) {
                    continue;
                }

                $url = $this->publicUrlFrom($listing, $tenantHost);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return $this->referenceSearchUrl($reference, $tenantHost);
    }

    /**
     * PF's search-by-reference page: a real, working link to the property.
     */
    public function referenceSearchUrl(string $reference, ?string $tenantHost = null): string
    {
        $host = $tenantHost !== null ? rtrim($tenantHost, '/') : 'https://www.propertyfinder.ae';

        return $host.'/en/search?q='.urlencode((string) $reference);
    }

    /**
     * Pull the public property URL out of a listing payload, accepting the
     * handful of shapes PF uses across API versions.
     *
     * @param  array<string, mixed>  $listing
     */
    private function publicUrlFrom(array $listing, ?string $tenantHost = null): ?string
    {
        $candidates = [
            $listing['publicUrl'] ?? null,
            $listing['public_url'] ?? null,
            $listing['url'] ?? null,
            $listing['webUrl'] ?? null,
            $listing['links']['web'] ?? null,
            $listing['links']['public'] ?? null,
        ];

        $slug = $listing['slug'] ?? null;
        $host = $tenantHost !== null ? rtrim($tenantHost, '/') : 'https://www.propertyfinder.ae';

        if (is_string($slug) && $slug !== '') {
            $candidates[] = $host.'/en/property/'.ltrim($slug, '/');
        }

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            // Reject the API's own endpoints and any message/lead link: a
            // listing URL that opens a chat window is worse than none.
            if (str_contains($candidate, 'atlas.propertyfinder.com')
                || str_contains($candidate, '/leads/')
                || str_contains($candidate, '/pm/')) {
                continue;
            }

            return str_starts_with($candidate, 'http') ? $candidate : $host.'/'.ltrim($candidate, '/');
        }

        return null;
    }

    /**
     * Fetch every lead created since the given cursor (RFC 3339, clamped to the
     * API's 3-month lookback).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchLeads(?string $since = null): array
    {
        $result = $this->queryLeads($since);

        return $result['leads'];
    }

    /**
     * Pull matching leads since a cursor and ingest them into the CRM.
     *
     * @return array{created: int, ignored: int, error: string|null, errors: array}
     */
    public function pullLeads(?CarbonInterface $since = null): array
    {
        $result = $this->queryLeads($since?->toIso8601String());

        $created = 0;
        $ignored = 0;

        foreach ($result['leads'] as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $normalized = (new PortalPayloadNormalizer)->normalize('propertyfinder', $raw);
            $lead = (new PortalLeadService)->createFromPayload($this->integration, 'property_finder', $normalized);

            if ($lead === null) {
                $ignored++;
            } elseif ($lead->wasRecentlyCreated) {
                $created++;
            } else {
                $ignored++;
            }
        }

        return [
            'created' => $created,
            'ignored' => $ignored,
            'error' => $result['error'],
            'errors' => $result['errors'],
        ];
    }

    /**
     * Paginate the /v1/leads endpoint, applying the created-since cursor.
     *
     * @return array{leads: array, error: string|null, errors: array}
     */
    protected function queryLeads(?string $since = null): array
    {
        $client = $this->client();

        if ($client === null) {
            return ['leads' => [], 'error' => 'Property Finder authentication failed. Check the API key and secret.', 'errors' => ['auth']];
        }

        $cursor = $since !== null
            ? Carbon::parse($since)->max(now()->subDays(self::LEADS_MAX_LOOKBACK_DAYS))
            : now()->subDays(self::LEADS_MAX_LOOKBACK_DAYS);

        $createdAtFrom = $cursor->format('Y-m-d\TH:i:s').'Z';

        $leads = [];
        $errors = [];
        $page = 1;
        $totalPages = 1;

        do {
            try {
                $response = $this->send(fn ($client) => $client->get($this->path('/v1/leads'), [
                    'page' => $page,
                    'perPage' => self::LEADS_PER_PAGE,
                    'createdAtFrom' => $createdAtFrom,
                ]));
            } catch (\Throwable $e) {
                $errors[] = 'HTTP request failed: '.$e->getMessage();
                break;
            }

            if (! $response->successful()) {
                $errors[] = 'HTTP '.$response->status().' '.Str::limit((string) $response->body(), 300);
                break;
            }

            $body = $response->json() ?? [];

            foreach ((array) ($body['data'] ?? []) as $record) {
                if (is_array($record)) {
                    $leads[] = $record;
                }
            }

            $pagination = (array) ($body['pagination'] ?? []);
            $totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));

            $page++;
        } while ($page <= $totalPages && $page <= 200);

        return [
            'leads' => $leads,
            'error' => $errors ? implode(' | ', array_slice($errors, 0, 5)) : null,
            'errors' => $errors,
        ];
    }

    public function test(): array
    {
        if ($this->inSandboxMode()) {
            if (blank($this->integration->sandbox_api_token) || blank($this->integration->sandbox_api_secret)) {
                return $this->error('Enter the sandbox API key and secret to test the sandbox connection.');
            }
        } elseif (blank($this->integration->api_token) || blank($this->integration->api_secret)) {
            return $this->error('Enter the Property Finder API key and secret first.');
        }

        $mode = $this->inSandboxMode() ? 'sandbox' : 'production';

        try {
            $client = $this->client();

            if ($client === null) {
                return $this->error('Property Finder authentication failed ('.$mode.'): '.($this->authError ?? 'Check the API key and secret.'));
            }

            $probes = [
                'users' => fn ($c) => $c->timeout(15)->get($this->path('/v1/users'), ['page' => 1, 'perPage' => 1]),
                'credits balance' => fn ($c) => $c->timeout(15)->get($this->path('/v1/credits/balance')),
                'leads' => fn ($c) => $c->timeout(15)->get($this->path('/v1/leads'), ['page' => 1, 'perPage' => 1]),
            ];

            foreach ($probes as $label => $probe) {
                $response = $this->send($probe);

                if (! $response->successful()) {
                    continue;
                }

                $suffix = $label === 'credits balance' && $response->json('remaining') !== null
                    ? ' Credits remaining: '.$response->json('remaining').'.'
                    : '';

                return [
                    'ok' => true,
                    'message' => 'Connected to the Property Finder '.$mode.' API ('.$label.' endpoint).'.$suffix,
                ];
            }

            return $this->error('Property Finder responded HTTP '.$response->status().' ('.$mode.'): '.Str::limit((string) $response->body(), 300));
        } catch (\Throwable $e) {
            return $this->error('Could not reach Property Finder ('.$mode.'): '.$e->getMessage());
        }
    }

    /**
     * Execute an authenticated request. PF can intermittently reject a just-minted
     * token with 401; when that happens we drop the cached token, mint a fresh
     * one and retry once. Builds a synthetic response when auth itself fails.
     */
    protected function send(\Closure $request): \Illuminate\Http\Client\Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $client = $this->client();

            if ($client === null) {
                return Http::response(['error' => $this->authError ?? 'Authentication failed.'], 401);
            }

            $response = $request($client);

            if ($response->status() !== 401) {
                return $response;
            }

            $this->token = null;
            $this->tokenExpiresAt = null;
            $this->authError = null;
        }

        return $response;
    }

    protected function client()
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        return Http::acceptJson()->timeout(30)->withToken($token);
    }

    protected function token(): ?string
    {
        if ($this->token !== null && $this->tokenExpiresAt !== null && now()->lessThan($this->tokenExpiresAt)) {
            return $this->token;
        }

        $credentials = $this->credentials();

        if ($credentials === null || blank($credentials['key'])) {
            $this->authError = 'No API key is set for this environment.';

            return null;
        }

        if (blank($credentials['secret'])) {
            $this->authError = 'No API secret is set for this environment.';

            return null;
        }

        try {
            $response = Http::acceptJson()->timeout(15)
                ->post(rtrim($this->base(), '/').'/v1/auth/token', [
                    'apiKey' => $credentials['key'],
                    'apiSecret' => $credentials['secret'],
                ]);

            if (! $response->successful()) {
                $this->authError = 'Auth endpoint HTTP '.$response->status().': '.Str::limit((string) $response->body(), 300);

                return null;
            }

            $accessToken = $response->json('accessToken');

            if (! is_string($accessToken) || $accessToken === '') {
                $this->authError = 'Auth endpoint returned no access token.';

                return null;
            }

            $expiresIn = (int) ($response->json('expiresIn') ?? 1800);

            $this->token = $accessToken;
            $this->tokenExpiresAt = now()->addSeconds($expiresIn - 30);

            return $this->token;
        } catch (\Throwable $e) {
            report($e);
            $this->authError = rtrim($this->base(), '/').': '.$e->getMessage();

            return null;
        }
    }

    protected function credentials(): ?array
    {
        if ($this->inSandboxMode()) {
            return [
                'key' => $this->integration->sandbox_api_token,
                'secret' => $this->integration->sandbox_api_secret,
            ];
        }

        return [
            'key' => $this->integration->api_token,
            'secret' => $this->integration->api_secret,
        ];
    }

    public function inSandboxMode(): bool
    {
        return (bool) $this->integration->use_sandbox;
    }

    /**
     * Build the Property Finder Enterprise listing request body
     * (request-combined-flat) from a CRM unit.
     */
    protected function payload(Property $property): array
    {
        $error = null;

        $type = $this->propertyType($property);
        $category = $this->category($property);
        $emirate = $this->emirate($property);

        if ($type === null) {
            return ['error' => 'Unsupported property category for Property Finder: '.(string) $property->property_category];
        }

        $price = $this->price($property);

        if ($price === null) {
            return ['error' => 'Add a sale or rent price before publishing to Property Finder.'];
        }

        $photos = array_values($property->photo_urls);

        if ($photos === []) {
            return ['error' => 'Add at least one photo before publishing to Property Finder.'];
        }

        $bedrooms = null;
        $bathrooms = null;

        if ($category === 'residential' && ! in_array($type, ['land', 'farm'], true)) {
            if ($property->isStudio()) {
                // PF takes studios in the `bedrooms` slot as a literal.
                $bedrooms = 'studio';
            } elseif ($property->bedrooms !== null) {
                $bedrooms = (string) (int) $property->bedrooms;
            }
            if ($property->bathrooms !== null) {
                $bathrooms = (int) $property->bathrooms === 0 ? 'none' : (string) (int) $property->bathrooms;
            }
        }

        $title = Str::limit($property->listingTitle(), 120);
        $description = Str::limit((string) $property->marketing_description, 4000) ?: $title;

        $payload = [
            'uaeEmirate' => $emirate,
            'category' => $category,
            'type' => $type,
            'furnishingType' => $this->furnishing($property),
            'reference' => (string) ($property->propertyfinder_listing_reference ?: $property->id),
            'price' => $price,
            'location' => ['id' => (int) $this->integration->default_location_id],
            'assignedTo' => ['id' => (int) $this->integration->public_profile_id],
            'title' => ['en' => $title],
            'description' => ['en' => $description],
            'size' => (int) ($property->square_footage ?: 0),
            'media' => [
                'images' => array_map(
                    fn ($url) => ['original' => ['url' => (string) $url]],
                    array_slice($photos, 0, 40)
                ),
            ],
            'unitNumber' => (string) $property->unit_no,
            'floorNumber' => (string) $property->floor_no,
            'developer' => (string) $property->developer_name,
            'ownerName' => (string) $property->owner_name,
        ];

        if ($bedrooms !== null) {
            $payload['bedrooms'] = $bedrooms;
        }

        if ($bathrooms !== null) {
            $payload['bathrooms'] = $bathrooms;
        }

        if ($property->market_class === 'off_plan') {
            $payload['projectStatus'] = 'off_plan_primary';
        } elseif ($property->market_class === 'ready') {
            $payload['projectStatus'] = 'completed_primary';
        }

        if ($property->available_from) {
            $payload['availableFrom'] = $property->available_from->format('Y-m-d');
        }

        if ($emirate === 'dubai' || $emirate === 'abu_dhabi') {
            $payload['compliance'] = [
                'listingAdvertisementNumber' => (string) $property->rera_permit_no,
                'type' => $emirate === 'abu_dhabi' ? 'adrec' : 'rera',
            ];
        }

        if ($type === 'land' && blank($payload['size'])) {
            $payload['plotNumber'] = (string) $property->plot_no;
            $payload['plotSize'] = (float) ($property->lot_size ?: 0);
        }

        foreach ($payload as $key => $value) {
            if ($key === 'price' || $key === 'media' || $key === 'location' || $key === 'assignedTo') {
                continue;
            }

            if ($value === '' || $value === null) {
                unset($payload[$key]);

                continue;
            }

            if ($key === 'size' && (int) $value === 0) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    protected function propertyType(Property $property): ?string
    {
        $map = [
            'apartment' => 'apartment',
            'penthouse' => 'penthouse',
            'villa' => 'villa',
            'villa_compound' => 'compound',
            'townhouse' => 'townhouse',
            'residential_building' => 'whole-building',
            'hotel_apartment' => 'hotel-apartment',
            'office' => 'office-space',
            'shop' => 'shop',
            'showroom' => 'show-room',
            'warehouse' => 'warehouse',
            'factory' => 'factory',
            'commercial_building' => 'whole-building',
            'land' => 'land',
        ];

        return $map[$property->property_category] ?? null;
    }

    protected function category(Property $property): string
    {
        $commercial = ['office', 'shop', 'showroom', 'warehouse', 'factory', 'commercial_building', 'other'];

        return in_array($property->property_category, $commercial, true) ? 'commercial' : 'residential';
    }

    protected function furnishing(Property $property): string
    {
        return match ($property->furnishing) {
            'furnished' => 'furnished',
            'semi_furnished' => 'semi-furnished',
            default => 'unfurnished',
        };
    }

    protected function emirate(Property $property): string
    {
        $permit = $property->locationPermit();

        return match ($permit['key']) {
            'abudhabi' => 'abu_dhabi',
            'dubai' => 'dubai',
            default => 'northern_emirates',
        };
    }

    protected function price(Property $property): ?array
    {
        if (in_array($property->intent, ['sale', 'both'], true) && $property->sale_price) {
            return [
                'type' => 'sale',
                'amounts' => ['sale' => (int) round((float) $property->sale_price)],
            ];
        }

        if (in_array($property->intent, ['rent', 'both'], true) && $property->rent_price) {
            $period = $property->rent_period === 'monthly' ? 'monthly' : 'yearly';

            return [
                'type' => $period,
                'amounts' => [$period => (int) round((float) $property->rent_price)],
            ];
        }

        return null;
    }

    protected function base(): string
    {
        // The Atlas API host is fixed per environment (Production vs Sandbox).
        // The legacy `base_url` column is ignored for Property Finder: it used
        // to hold https://api.propertyfinder.ae which no longer resolves.
        return $this->inSandboxMode()
            ? self::SANDBOX_BASE_URL
            : self::PRODUCTION_BASE_URL;
    }

    protected function path(string $path): string
    {
        return $this->base().$path;
    }

    protected function error(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
