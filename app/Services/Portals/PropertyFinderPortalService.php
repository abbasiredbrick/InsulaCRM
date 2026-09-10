<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PropertyFinderPortalService
{
    public const DEFAULT_BASE_URL = 'https://api.propertyfinder.ae';

    protected ?string $token = null;

    protected ?\DateTimeInterface $tokenExpiresAt = null;

    public function __construct(protected PortalIntegration $integration)
    {
    }

    public function publish(Property $property): array
    {
        if (blank($this->integration->api_token) || blank($this->integration->api_secret)) {
            return $this->error('Property Finder API key and secret are required.');
        }

        if (blank($this->integration->public_profile_id)) {
            return $this->error('The Property Finder public profile ID is required to publish.');
        }

        $draftResponse = $this->client()->post($this->path('/v1/listings'), $this->payload($property));

        $listingId = $draftResponse->successful()
            ? ($draftResponse->json('id') ?? $draftResponse->json('reference') ?? $draftResponse->json('data.id') ?? null)
            : null;

        if ($listingId === null) {
            return $this->error('Property Finder create failed (HTTP ' . $draftResponse->status() . '): ' . Str::limit((string) $draftResponse->body(), 500));
        }

        $publishResponse = $this->client()->post($this->path('/v1/listings/' . $listingId . '/publish'));

        if (! $publishResponse->successful()) {
            return $this->error('Property Finder publish failed (HTTP ' . $publishResponse->status() . '): ' . Str::limit((string) $publishResponse->body(), 500));
        }

        return [
            'ok'        => true,
            'reference' => is_string($listingId) ? $listingId : null,
            'url'       => $publishResponse->json('url') ?? $publishResponse->json('link') ?? null,
            'message'   => 'Draft created and submitted for publication.',
            'raw'       => $publishResponse->json(),
        ];
    }

    public function unpublish(Property $property): array
    {
        $reference = $property->propertyfinder_listing_reference;

        if (blank($reference)) {
            return $this->error('This unit has no Property Finder reference to remove.');
        }

        $response = $this->client()->post($this->path('/v1/listings/' . $reference . '/unpublish'));

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Unpublished.'];
        }

        return $this->error('Property Finder unpublish failed (HTTP ' . $response->status() . '): ' . Str::limit((string) $response->body(), 500));
    }

    public function fetchLeads(?string $from = null): array
    {
        $query = [];
        if ($from !== null) {
            $query['createdAfter'] = $from;
        }

        $response = $this->client()->get($this->path('/v1/leads') . ($query ? '?' . http_build_query($query) : ''));

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json() ?? [];

        return $body['data'] ?? $body['leads'] ?? (is_array($body) ? $body : []);
    }

    public function test(): array
    {
        if (blank($this->integration->api_token) || blank($this->integration->api_secret)) {
            return $this->error('Enter the Property Finder API key and secret first.');
        }

        try {
            $response = $this->client()->timeout(15)->get($this->path('/v1/credits/balance'));
            $label = 'credits balance';

            if ($response->status() === 404 || $response->status() === 403) {
                $response = $this->client()->timeout(15)->get($this->path('/v1/users'));
                $label = 'users';
            }

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'message' => 'Connected. ' . Str::limit((string) $response->body(), 300),
                ];
            }

            return $this->error('Property Finder responded HTTP ' . $response->status() . ' on ' . $label . ': ' . Str::limit((string) $response->body(), 300));
        } catch (\Throwable $e) {
            return $this->error('Could not reach Property Finder: ' . $e->getMessage());
        }
    }

    protected function client()
    {
        $client = Http::acceptJson()->timeout(30);

        $token = $this->token();

        if ($token !== null) {
            return $client->withToken($token);
        }

        return $client->withBasicAuth($this->integration->api_token, $this->integration->api_secret);
    }

    protected function token(): ?string
    {
        if ($this->token !== null && $this->tokenExpiresAt !== null && now()->lessThan($this->tokenExpiresAt)) {
            return $this->token;
        }

        try {
            $response = Http::acceptJson()->asForm()->timeout(15)
                ->post(rtrim($this->base(), '/') . '/v1/oauth/token', [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->integration->api_token,
                    'client_secret' => $this->integration->api_secret,
                ]);

            $accessToken = $response->successful()
                ? ($response->json('access_token') ?? $response->json('token') ?? null)
                : null;

            if ($accessToken !== null) {
                $this->token = $accessToken;
                $this->tokenExpiresAt = now()->addSeconds((int) ($response->json('expires_in') ?? 3600) - 30);

                return $this->token;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    protected function payload(Property $property): array
    {
        return [
            'market'      => 'AE',
            'developer'   => $property->developer_name,
            'reference'   => (string) $property->id,
            'property'    => [
                'propertyType' => $this->category($property),
                'bedrooms'     => (int) ($property->bedrooms ?? 0),
                'bathrooms'    => (int) ($property->bathrooms ?? 0),
                'area'         => (string) ($property->square_footage ?? ''),
                'furnished'    => $property->furnishing,
                'floor'        => (string) $property->floor_no,
                'unit'         => (string) $property->unit_no,
                'photos'       => array_values($property->photo_urls),
                'virtualTour'  => $property->virtual_tour_url,
            ],
            'location'    => [
                'locationId'      => $this->integration->default_location_id,
                'additionalAreas' => null,
            ],
            'price'       => [
                'value'         => $this->price($property),
                'currency'      => 'AED',
                'rentFrequency' => $property->rent_period === 'monthly' ? 'monthly' : 'yearly',
            ],
            'agent'       => [
                'publicProfileId' => $this->integration->public_profile_id,
            ],
            'title'       => Str::limit($property->display_name, 120),
            'description' => Str::limit((string) $property->marketing_description, 4000),
            'permit'      => $property->rera_permit_no,
            'titleDeed'   => $property->title_deed_no,
            'methods'     => ['publish' => true],
        ];
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

    protected function base(): string
    {
        return rtrim((string) ($this->integration->base_url ?: self::DEFAULT_BASE_URL), '/');
    }

    protected function path(string $path): string
    {
        return $this->base() . $path;
    }

    protected function error(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}