<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BayutPortalService
{
    public function __construct(protected PortalIntegration $integration)
    {
    }

    public function publish(Property $property): array
    {
        if (blank($this->integration->base_url)) {
            return $this->error('Bayut Push API base URL is missing. Add it under Settings → Portal Integrations.');
        }

        $payload = $this->payload($property);

        $response = Http::withToken($this->integration->api_token)
            ->asJson()
            ->acceptJson()
            ->post(rtrim($this->integration->base_url, '/') . '/listings', $payload);

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

    protected function payload(Property $property): array
    {
        return [
            'reference'      => (string) $property->id,
            'permit_number'  => $property->rera_permit_no,
            'purpose'        => $property->intent === 'sale' ? 'sell' : 'rent',
            'property'       => $this->category($property),
            'title'          => Str::limit($property->display_name, 120),
            'description'    => Str::limit((string) $property->marketing_description, 4000),
            'price'          => $this->price($property),
            'price_period'   => $property->rent_period === 'monthly' ? 'monthly' : 'yearly',
            'province'       => '',
            'city'           => (string) $property->city ?: (string) $property->community,
            'area'           => (string) ($property->sub_community ?: $property->community),
            'address'        => (string) $property->address,
            'beds'           => (int) ($property->bedrooms ?? 0),
            'baths'          => (int) ($property->bathrooms ?? 0),
            'size'           => (string) ($property->square_footage ?? ''),
            'furnished'      => $property->furnishing === 'furnished',
            'parking'        => (int) ($property->parking ?? 0),
            'photos'         => array_values($property->photo_urls),
            'agent_reference'=> $this->integration->agent_reference,
            'contact_name'   => $property->assignedAgent?->name,
            'contact_email'  => $property->assignedAgent?->email,
        ];
    }

    protected function interpret($response, Property $property): array
    {
        if ($response->successful()) {
            $body = $response->json() ?? [];

            return [
                'ok'        => true,
                'reference' => $body['reference'] ?? $body['id'] ?? $body['listing'] ?? null,
                'url'       => $body['url'] ?? $body['link'] ?? null,
                'message'   => 'Accepted by Bayut.',
                'raw'       => $response->json(),
            ];
        }

        $error = 'Bayut Push API error (HTTP ' . $response->status() . '): ' . Str::limit((string) $response->body(), 500);

        return $this->error($error);
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