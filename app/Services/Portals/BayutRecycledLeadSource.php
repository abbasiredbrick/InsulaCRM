<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class BayutRecycledLeadSource implements PortalLeadSource
{
    public const ENDPOINTS = [
        'bayut' => 'https://www.bayut.com/api-v7/stats/website-client-leads',
        'dubizzle' => 'https://dubizzle.com/profolio/api-v7/stats/website-client-leads',
    ];

    public const TYPES = [
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'phone' => 'Phone',
        'sms' => 'SMS',
    ];

    public const TARGETS = [
        'listing' => 'Listing page',
        'agent' => 'Agent profile',
        'agency' => 'Agency page',
    ];

    public function __construct(protected PortalIntegration $integration) {}

    public function fetchNext(array $criteria, array $cursor): array
    {
        $portal = (string) $criteria['portal'];
        $types = array_values(array_intersect(array_keys(self::TYPES), array_keys((array) ($criteria['types'] ?? []))));
        $targets = array_values(array_intersect(array_keys(self::TARGETS), array_keys((array) ($criteria['targets'] ?? []))));
        $trulead = (int) ($criteria['trulead'] ?? 1);
        $buckets = [];

        foreach ($types as $type) {
            foreach ($targets as $target) {
                $buckets[] = compact('type', 'target');
            }
        }

        $index = max(0, (int) ($cursor['bucket_index'] ?? 0));

        if ($index >= count($buckets)) {
            return $this->result([], $cursor, true);
        }

        $bucket = $buckets[$index];
        $nextCursor = ['bucket_index' => $index + 1];
        $done = $index + 1 >= count($buckets);

        try {
            $response = $this->client()->timeout(30)->get(self::ENDPOINTS[$portal], [
                'type' => $bucket['type'],
                'target' => $bucket['target'],
                'is_trulead' => $trulead,
                'timestamp' => $criteria['date_from'].' 00:00:00',
            ]);
        } catch (Throwable $e) {
            return $this->result([], $cursor, false, $e->getMessage());
        }

        if (! $response->successful()) {
            $status = $response->status();
            $transient = $status === 408 || $status === 429 || $status >= 500;
            $fatal = ! $transient;

            return $this->result(
                [],
                $fatal ? $nextCursor : $cursor,
                $fatal,
                $this->errorMessage($status, $response->body()),
                $fatal
            );
        }

        $body = $response->json();

        if (is_array($body) && array_is_list($body)) {
            $records = $body;
        } elseif (is_array($body) && is_array($body['data'] ?? null)) {
            $records = $body['data'];
        } else {
            return $this->result([], $cursor, true, 'The portal API returned an invalid response.', true);
        }
        $normalized = [];

        foreach ($records as $record) {
            if (is_array($record)) {
                $normalized[] = $this->normalize($record, $portal, $bucket['type'], $bucket['target']);
            }
        }

        return $this->result($normalized, $nextCursor, $done);
    }

    protected function normalize(array $record, string $portal, string $type, string $target): array
    {
        $details = is_array($record[$target.'_details'] ?? null) ? $record[$target.'_details'] : [];
        $listing = is_array($details['listing_details'] ?? null) ? $details['listing_details'] : $details;
        $inquirer = is_array($record['inquirer_details'] ?? null) ? $record['inquirer_details'] : [];
        $name = trim((string) ($inquirer['name'] ?? ''));
        [$firstName, $lastName] = $this->splitName($name);

        return [
            'external_id' => $record['lead_id'] ?? $record['call_log_id'] ?? null,
            'provider_type' => $type,
            'provider_target' => (string) ($record['lead_target'] ?? $target),
            'provider_status' => null,
            'received_at' => $record['date_time'] ?? $record['call_time'] ?? null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $inquirer['cell'] ?? $inquirer['phone'] ?? null,
            'email' => is_string($inquirer['email'] ?? null) ? $inquirer['email'] : null,
            'message' => is_string($inquirer['message'] ?? null) ? $inquirer['message'] : null,
            'reference' => isset($listing['listing_reference']) ? Str::limit((string) $listing['listing_reference'], 100, '') : null,
            'listing_url' => isset($listing['listing_url']) ? (string) $listing['listing_url'] : null,
            'original_deal_type' => $this->dealType($record['visitor_intent'] ?? null),
            'raw' => $record,
        ];
    }

    protected function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2) ?: [];

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    protected function dealType(mixed $intent): ?string
    {
        $value = strtolower(trim((string) $intent));

        $rent = str_contains($value, 'rent') || str_contains($value, 'lease');
        $sale = str_contains($value, 'buy') || str_contains($value, 'sale') || str_contains($value, 'invest');

        return match (true) {
            $rent && ! $sale => 'rent',
            $sale && ! $rent => 'sale',
            default => null,
        };
    }

    protected function client()
    {
        $token = (string) $this->integration->leads_api_token;

        return Http::acceptJson()->withToken($token);
    }

    protected function errorMessage(int $status, string $body): string
    {
        return 'HTTP '.$status.' '.Str::limit($body, 300);
    }

    protected function result(array $records, array $cursor, bool $done, ?string $error = null, bool $fatal = false): array
    {
        return compact('records', 'cursor', 'done', 'error', 'fatal');
    }
}
