<?php

namespace App\Services\Portals;

use App\Models\AuditLog;
use App\Models\PortalIntegration;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BayutStatusSyncService
{
    protected const LIVE_STATUSES = [
        'live', 'active', 'published', 'listed', 'available', 'active_listing',
        'on', '1', 'true', 'yes', 'for_rent', 'for_sale',
    ];

    public function __construct(protected PortalIntegration $integration)
    {
    }

    /**
     * Fetch every listing from the Bayut push API and reconcile the CRM's
     * portal-status columns for units that carry a listing reference.
     *
     * @return array{checked:int, live:int, updated:int, removed:int, error:string|null, errors:array}
     */
    public function sync(): array
    {
        $remote = $this->fetch();

        if ($remote['error'] !== null) {
            return [
                'checked' => 0,
                'live'    => 0,
                'updated' => 0,
                'removed' => 0,
                'error'   => $remote['error'],
                'errors'  => $remote['errors'],
            ];
        }

        $byReference = $this->indexByReference($remote['listings']);

        $properties = Property::withoutGlobalScopes()
            ->where('tenant_id', $this->integration->tenant_id)
            ->where(fn ($q) => $q
                ->whereNotNull('bayut_listing_id')
                ->orWhereNotNull('dubizzle_listing_reference'))
            ->get();

        $checked = 0;
        $live = 0;
        $updated = 0;
        $removed = 0;

        foreach ($properties as $property) {
            $reference = $property->bayut_listing_id ?: $property->dubizzle_listing_reference;

            if (blank($reference)) {
                continue;
            }

            $listing = $byReference[(string) $reference] ?? null;
            $checked++;

            if ($listing !== null && $this->isLive($listing)) {
                $live++;

                if ($property->bayut_status !== 'live') {
                    $this->markLive($property, $reference, $listing);
                    $updated++;
                }
            } elseif ($property->bayut_status === 'live' || $property->dubizzle_status === 'live') {
                $this->markRemoved($property);
                $removed++;
            }
        }

        return [
            'checked' => $checked,
            'live'    => $live,
            'updated' => $updated,
            'removed' => $removed,
            'error'   => null,
            'errors'  => [],
        ];
    }

    /**
     * Fetch and normalise the remote listing array.
     *
     * @return array{listings:array, error:string|null, errors:array}
     */
    protected function fetch(): array
    {
        if (blank($this->integration->base_url)) {
            return ['listings' => [], 'error' => 'Bayut Push API base URL is missing.', 'errors' => ['Bayut Push API base URL is missing.']];
        }

        try {
            $tries = 0;

            do {
                $response = Http::withToken((string) $this->integration->api_token)
                    ->acceptJson()
                    ->timeout(25)
                    ->get(rtrim($this->integration->base_url, '/') . '/listings');

                $tries++;

                if ($response->status() === 429) {
                    $retryAfter = min((int) ($response->json('retry_after') ?? 60), 120);

                    if ($tries >= 2) {
                        return ['listings' => [], 'error' => 'Bayut listing status sync rate limited (HTTP 429) after retry.', 'errors' => ['HTTP 429 after retry']];
                    }

                    usleep($retryAfter * 1_000_000);

                    continue;
                }

                break;
            } while (true);

            if (! $response->successful()) {
                $message = 'Bayut listing status sync failed (HTTP ' . $response->status() . '): ' . Str::limit((string) $response->body(), 300);

                return ['listings' => [], 'error' => $message, 'errors' => [$message]];
            }

            return [
                'listings' => $this->extractListings($response->json()),
                'error'    => null,
                'errors'   => [],
            ];
        } catch (\Throwable $e) {
            $message = 'Could not reach Bayut listing status API: ' . $e->getMessage();

            return ['listings' => [], 'error' => $message, 'errors' => [$message]];
        }
    }

    protected function extractListings($body): array
    {
        if (! is_array($body)) {
            return [];
        }

        foreach (['listings', 'data', 'items', 'results'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return array_values(array_filter($body[$key], 'is_array'));
            }
        }

        if (array_is_list($body)) {
            return array_values(array_filter($body, 'is_array'));
        }

        return [];
    }

    protected function indexByReference(array $listings): array
    {
        $index = [];

        foreach ($listings as $listing) {
            $reference = $listing['reference'] ?? $listing['listing_reference'] ?? $listing['id'] ?? null;

            if ($reference !== null && ! isset($index[(string) $reference])) {
                $index[(string) $reference] = $listing;
            }
        }

        return $index;
    }

    protected function isLive(array $listing): bool
    {
        $status = strtolower((string) ($listing['status'] ?? ''));

        if ($status === '') {
            return true;
        }

        return in_array($status, self::LIVE_STATUSES, true);
    }

    protected function markLive(Property $property, string $reference, array $listing): void
    {
        $url = is_string($listing['url'] ?? null) ? $listing['url'] : null;
        $listedAt = $this->dateOf($listing);

        $property->update([
            'bayut_status'   => 'live',
            'bayut_listing_id' => $reference,
            'bayut_url'      => $url,
            'bayut_listed_at' => $listedAt,
            'dubizzle_status' => 'live',
            'dubizzle_listing_reference' => $reference,
            'dubizzle_url'   => $url,
            'dubizzle_listed_at' => $listedAt,
        ]);

        $this->audit($property, 'inventory.portal_status_synced_bayut', ['portal' => 'bayut', 'status' => 'live', 'reference' => $reference]);
    }

    protected function markRemoved(Property $property): void
    {
        $property->update([
            'bayut_status'    => 'removed',
            'dubizzle_status' => 'removed',
        ]);

        $this->audit($property, 'inventory.portal_status_synced_bayut', ['portal' => 'bayut', 'status' => 'removed']);
    }

    protected function dateOf(array $listing): ?string
    {
        foreach (['published_at', 'listed_at', 'created_at', 'updated_at'] as $key) {
            $value = $listing[$key] ?? null;

            if (! empty($value)) {
                return date('Y-m-d', strtotime((string) $value) ?: time());
            }
        }

        return now()->toDateString();
    }

    protected function audit(Property $property, string $action, array $newValues): void
    {
        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $this->integration->tenant_id,
            'user_id'   => null,
            'action'    => $action,
            'model_type' => Property::class,
            'model_id'  => $property->id,
            'old_values' => null,
            'new_values' => $newValues,
        ]);
    }
}