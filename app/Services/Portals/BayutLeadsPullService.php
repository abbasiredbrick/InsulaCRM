<?php

namespace App\Services\Portals;

use App\Models\PortalIntegration;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BayutLeadsPullService
{
    public const ENDPOINTS = [
        'bayut'    => 'https://www.bayut.com/api-v7/stats/website-client-leads',
        'dubizzle' => 'https://dubizzle.com/profolio/api-v7/stats/website-client-leads',
    ];

    public const LEAD_TYPES = ['email', 'whatsapp', 'phone', 'sms'];

    public const TARGETS = ['listing', 'agent', 'agency'];

    protected array $errors = [];

    protected int $created = 0;

    protected int $ignored = 0;

    public function __construct(protected PortalIntegration $integration)
    {
    }

    /**
     * Pull leads from both Bayut and Dubizzle for the configured leads API token.
     *
     * @return array{created: int, ignored: int, error: string|null, errors: array}
     */
    public function pull(?CarbonInterface $since = null): array
    {
        $this->created = 0;
        $this->ignored = 0;
        $this->errors = [];

        if (blank($this->integration->leads_api_token)) {
            return $this->result('The Bayut / Dubizzle leads API token is required.');
        }

        $timestamp = ($since ?: now()->subDay())->format('Y-m-d H:i:s');

        foreach (self::ENDPOINTS as $source => $endpoint) {
            $this->pullEndpoint($endpoint, $source, $timestamp);
        }

        return $this->result();
    }

    protected function pullEndpoint(string $endpoint, string $source, string $timestamp): void
    {
        foreach (self::TARGETS as $target) {
            foreach (self::LEAD_TYPES as $type) {
                $this->ingest($this->request($endpoint, $source, $type, $target, $timestamp), $source);
            }
        }

        $this->ingest($this->request($endpoint, $source, 'story_leads', 'listing', $timestamp), $source);
    }

    protected function request(string $endpoint, string $source, string $type, string $target, string $timestamp): array
    {
        try {
            $response = $this->client()->timeout(25)->get($endpoint, [
                'type'       => $type,
                'target'     => $target,
                'is_trulead' => 1,
                'timestamp'  => $timestamp,
            ]);

            if (! $response->successful()) {
                $this->errors[] = "{$source}/{$type}/{$target}: HTTP {$response->status()} " . Str::limit((string) $response->body(), 200);

                return [];
            }

            $body = $response->json();

            return is_array($body) ? $body : [];
        } catch (\Throwable $e) {
            $this->errors[] = "{$source}/{$type}/{$target}: {$e->getMessage()}";

            return [];
        }
    }

    protected function ingest(array $records, string $source): void
    {
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $lead = (new PortalLeadService)->createFromPayload($this->integration, $source, $this->normalizeLead($record));

            if ($lead === null) {
                $this->ignored++;
            } elseif ($lead->wasRecentlyCreated) {
                $this->created++;
            } else {
                $this->ignored++;
            }
        }
    }

    /**
     * Map a raw Bayut / Dubizzle pull record onto the normalised payload shape.
     */
    protected function normalizeLead(array $record): array
    {
        $target = (string) ($record['lead_target'] ?? '');
        $details = is_array($record[$target . '_details'] ?? null) ? $record[$target . '_details'] : [];

        $reference = null;
        $url = null;

        if (is_array($details['listing_details'] ?? null)) {
            $sub = $details['listing_details'];
            $reference = $sub['listing_reference'] ?? null;
            $url = is_string($sub['listing_url'] ?? null) ? $sub['listing_url'] : null;
        } else {
            $reference = is_string($details['listing_reference'] ?? null) ? $details['listing_reference'] : null;
            $url = is_string($details['listing_url'] ?? null) ? $details['listing_url'] : null;
        }

        if ($reference === null) {
            $reference = is_string($record['listing_reference'] ?? null) ? $record['listing_reference'] : null;
        }
        if ($url === null) {
            $url = is_string($record['listing_url'] ?? null) ? $record['listing_url'] : null;
        }

        $inquirer = is_array($record['inquirer_details'] ?? null) ? $record['inquirer_details'] : [];

        return [
            'id'           => $record['lead_id'] ?? null,
            'name'         => is_string($inquirer['name'] ?? null) ? $inquirer['name'] : '',
            'phone'        => $inquirer['cell'] ?? $inquirer['phone'] ?? null,
            'email'        => is_string($inquirer['email'] ?? null) ? $inquirer['email'] : null,
            'message'      => is_string($inquirer['message'] ?? null) ? $inquirer['message'] : null,
            'reference'    => $reference,
            'url'          => $url,
            'contact_link' => null,
            'received_at'  => $record['date_time'] ?? null,
        ];
    }

    protected function client()
    {
        return Http::acceptJson()->withToken((string) $this->integration->leads_api_token);
    }

    protected function result(?string $error = null): array
    {
        return [
            'created' => $this->created,
            'ignored' => $this->ignored,
            'error'   => $error ?? ($this->errors ? implode(' | ', array_slice($this->errors, 0, 5)) : null),
            'errors'  => $this->errors,
        ];
    }
}