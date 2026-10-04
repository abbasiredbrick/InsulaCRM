<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PropertyFinderRecycledLeadSource implements PortalLeadSource
{
    public const PER_PAGE = 50;

    public const MAX_PAGES = 200;

    protected ?string $token = null;

    protected ?CarbonImmutable $tokenExpiresAt = null;

    protected ?string $authError = null;

    public function __construct(protected PortalIntegration $integration) {}

    public function fetchNext(array $criteria, array $cursor): array
    {
        $page = max(1, (int) ($cursor['page'] ?? 1));
        $knownTotal = max(1, (int) ($cursor['total_pages'] ?? 1));

        if ($page > $knownTotal) {
            return $this->result([], $cursor, true);
        }

        $client = $this->client();

        if (! $client instanceof PendingRequest) {
            return $this->result([], ['page' => $page, 'total_pages' => $knownTotal], true, $this->authError, true);
        }

        try {
            $response = $this->send($client, [
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'createdAtFrom' => CarbonImmutable::parse(
                    $criteria['date_from'],
                    $criteria['timezone'] ?? 'UTC'
                )->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
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
                $cursor,
                $fatal,
                'HTTP '.$status.' '.Str::limit((string) $response->body(), 300),
                $fatal
            );
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            return $this->result([], $cursor, true, 'Property Finder returned an invalid response.', true);
        }

        $totalPages = max(1, (int) ($body['pagination']['totalPages'] ?? 1));

        if ($totalPages > self::MAX_PAGES) {
            return $this->result(
                [],
                ['page' => $page, 'total_pages' => $knownTotal],
                true,
                'Property Finder returned more than '.self::MAX_PAGES.' pages. Narrow the date range before importing.',
                true
            );
        }

        $records = [];

        foreach ((array) ($body['data'] ?? []) as $record) {
            if (is_array($record)) {
                $records[] = $this->normalize($record);
            }
        }

        $next = ['page' => $page + 1, 'total_pages' => $totalPages];

        return $this->result($records, $next, $page >= $totalPages);
    }

    protected function normalize(array $record): array
    {
        $normalized = (new PortalPayloadNormalizer)->normalize('property_finder', $record);
        [$firstName, $lastName] = $this->splitName($normalized['name'] ?? null);
        $channel = strtolower(trim((string) ($record['channel'] ?? '')));

        return [
            'external_id' => $normalized['id'] ?? null,
            'provider_type' => $this->category($channel, $normalized),
            'provider_target' => is_string($record['entityType'] ?? null) ? $record['entityType'] : null,
            'provider_status' => is_string($record['status'] ?? null) ? $record['status'] : null,
            'received_at' => $normalized['received_at'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $normalized['phone'],
            'email' => $normalized['email'],
            'message' => $normalized['message'],
            'reference' => $normalized['reference'],
            'listing_url' => $normalized['url'],
            'original_deal_type' => $this->dealType($record['visitorIntent'] ?? $record['intent'] ?? null),
            'raw' => $record,
        ];
    }

    protected function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name), 2) ?: [];

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    protected function category(string $channel, array $lead): string
    {
        return match ($channel) {
            'whatsapp' => 'whatsapp',
            'email' => 'email',
            'sms' => 'sms',
            'call', 'phone', 'voice' => 'phone',
            default => filled($lead['phone'] ?? null) ? 'phone' : 'email',
        };
    }

    protected function dealType(mixed $intent): ?string
    {
        $value = strtolower(trim((string) $intent));

        return match (true) {
            str_contains($value, 'rent') || str_contains($value, 'lease') => 'rent',
            str_contains($value, 'buy') || str_contains($value, 'sale') || str_contains($value, 'invest') => 'sale',
            default => null,
        };
    }

    protected function client(): ?PendingRequest
    {
        if ($this->token !== null && $this->tokenExpiresAt !== null && now()->lessThan($this->tokenExpiresAt)) {
            return $this->authorizedClient();
        }

        if (blank($this->integration->api_token) || blank($this->integration->api_secret)) {
            $this->authError = 'Property Finder production API credentials are required.';

            return null;
        }

        try {
            $response = Http::acceptJson()->timeout(20)->post(PropertyFinderPortalService::PRODUCTION_BASE_URL.'/v1/auth/token', [
                'apiKey' => $this->integration->api_token,
                'apiSecret' => $this->integration->api_secret,
            ]);
        } catch (Throwable $e) {
            $this->authError = $e->getMessage();

            return null;
        }

        if (! $response->successful()) {
            $this->authError = 'Authentication failed with HTTP '.$response->status().'.';

            return null;
        }

        $token = $response->json('accessToken');

        if (! is_string($token) || blank($token)) {
            $this->authError = 'Property Finder returned no access token.';

            return null;
        }

        $this->token = $token;
        $this->tokenExpiresAt = CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expiresIn', 1800) - 30));

        return $this->authorizedClient();
    }

    protected function authorizedClient(): PendingRequest
    {
        return Http::acceptJson()->timeout(30)->withToken($this->token);
    }

    protected function send(PendingRequest $client, array $query): \Illuminate\Http\Client\Response
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $client->get(PropertyFinderPortalService::PRODUCTION_BASE_URL.'/v1/leads', $query);

            if ($response->status() !== 401) {
                return $response;
            }

            $this->token = null;
            $this->tokenExpiresAt = null;
            $client = $this->client();

            if (! $client instanceof PendingRequest) {
                return Http::response(['error' => $this->authError], 401);
            }
        }

        return $response;
    }

    protected function result(array $records, array $cursor, bool $done, ?string $error = null, bool $fatal = false): array
    {
        return compact('records', 'cursor', 'done', 'error', 'fatal');
    }
}
