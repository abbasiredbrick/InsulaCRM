<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Services\Portals\BayutLeadsPullService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BayutLeadsPullTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function createIntegration(array $overrides = []): PortalIntegration
    {
        return PortalIntegration::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'portal'    => 'bayut',
            'is_active' => true,
        ], $overrides));
    }

    protected function whatsappLead(): array
    {
        return [
            'lead_id'   => 'whatsapp|1f9f-aaaa',
            'source'    => 'bayut',
            'lead_target' => 'listing',
            'date_time' => '2026-09-09 10:00:00',
            'listing_details' => [
                'listing_id'        => 10219,
                'listing_reference' => '10219-HV21nX',
                'current_type'      => 'Apartment',
            ],
            'inquirer_details' => [
                'name'    => 'Sara Ahmed',
                'cell'    => '+971501234567',
                'email'   => '',
                'message' => 'Is it still available?',
            ],
        ];
    }

    protected function dubizzleLead(): array
    {
        return [
            'lead_id'   => 'email|bbbb',
            'source'    => 'dubizzle',
            'lead_target' => 'listing',
            'date_time' => '2026-09-09 12:00:00',
            'listing_details' => [
                'listing_id'        => 505,
                'listing_reference' => 'DZ-505',
                'current_type'      => 'Villa',
            ],
            'inquirer_details' => [
                'name'    => 'Priya Sharma',
                'cell'    => '+971502222222',
                'email'   => 'priya@example.com',
                'message' => 'Schedule a viewing.',
            ],
        ];
    }

    public function test_pull_ingests_bayut_and_dubizzle_leads_and_links_properties(): void
    {
        $bayutProperty = $this->createProperty(['bayut_listing_id' => '10219-HV21nX']);
        $dubizzleProperty = $this->createProperty(['dubizzle_listing_reference' => 'DZ-505']);

        $integration = $this->createIntegration(['leads_api_token' => 'pull-token-1234567890']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'www.bayut.com')) {
                if ($request['type'] === 'whatsapp' && $request['target'] === 'listing') {
                    return Http::response([$this->whatsappLead()], 200);
                }

                return Http::response([], 200);
            }

            if (str_contains($request->url(), 'dubizzle.com')) {
                if ($request['type'] === 'email' && $request['target'] === 'listing') {
                    return Http::response([$this->dubizzleLead()], 200);
                }

                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        $result = (new BayutLeadsPullService($integration))->pull(Carbon::parse('2026-09-01 00:00:00'));

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['ignored']);
        $this->assertNull($result['error']);

        $bayutLead = Lead::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('lead_source', 'bayut')
            ->firstOrFail();

        $this->assertSame('Sara', $bayutLead->first_name);
        $this->assertSame('Ahmed', $bayutLead->last_name);
        $this->assertSame('+971501234567', $bayutLead->phone);
        $this->assertSame('Is it still available?', $bayutLead->notes);
        $this->assertSame($bayutProperty->id, $bayutLead->custom_fields['listing_property_id'] ?? null);
        $this->assertSame('10219-HV21nX', $bayutLead->custom_fields['listing_reference'] ?? null);
        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $bayutLead->id, 'property_id' => $bayutProperty->id,
        ]);

        $dubizzleLead = Lead::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('lead_source', 'dubizzle')
            ->firstOrFail();

        $this->assertSame('Priya', $dubizzleLead->first_name);
        $this->assertSame('Sharma', $dubizzleLead->last_name);
        $this->assertSame('priya@example.com', $dubizzleLead->email);
        $this->assertSame($dubizzleProperty->id, $dubizzleLead->custom_fields['listing_property_id'] ?? null);
        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $dubizzleLead->id, 'property_id' => $dubizzleProperty->id,
        ]);
    }

    public function test_pull_deduplicates_by_phone(): void
    {
        $integration = $this->createIntegration(['leads_api_token' => 'pull-token-1234567890']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'www.bayut.com')
                && $request['type'] === 'whatsapp'
                && $request['target'] === 'listing') {
                return Http::response([$this->whatsappLead()], 200);
            }

            return Http::response([], 200);
        });

        $service = new BayutLeadsPullService($integration);

        $first = $service->pull(Carbon::parse('2026-09-01 00:00:00'));
        $second = $service->pull(Carbon::parse('2026-09-01 00:00:00'));

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['ignored']);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_pull_ignores_records_without_contact_details(): void
    {
        $integration = $this->createIntegration(['leads_api_token' => 'pull-token-1234567890']);

        $incomplete = $this->whatsappLead();
        $incomplete['inquirer_details'] = ['name' => '', 'cell' => '', 'email' => ''];

        Http::fake(function ($request) use ($incomplete) {
            if (str_contains($request->url(), 'www.bayut.com')
                && $request['type'] === 'whatsapp'
                && $request['target'] === 'listing') {
                return Http::response([$incomplete], 200);
            }

            return Http::response([], 200);
        });

        $result = (new BayutLeadsPullService($integration))->pull(Carbon::parse('2026-09-01 00:00:00'));

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['ignored']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_controller_sync_leads_for_bayut_updates_last_synced_at(): void
    {
        $this->createIntegration(['leads_api_token' => 'pull-token-1234567890']);

        Http::fake(['*' => Http::response([], 200)]);

        $this->post(route('portal-integrations.sync-leads', 'bayut'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $integration = PortalIntegration::where('tenant_id', $this->tenant->id)
            ->where('portal', 'bayut')
            ->firstOrFail();

        $this->assertNotNull($integration->leads_last_synced_at);
    }

    public function test_controller_sync_leads_for_bayut_fails_without_token(): void
    {
        $this->createIntegration();

        $this->post(route('portal-integrations.sync-leads', 'bayut'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_update_saves_leads_api_token_encrypted(): void
    {
        $this->post(route('portal-integrations.update', 'bayut'), [
            'leads_api_token' => 'pull-token-abcdef123456',
        ])->assertRedirect();

        $integration = PortalIntegration::where('tenant_id', $this->tenant->id)
            ->where('portal', 'bayut')
            ->firstOrFail();

        $this->assertSame('pull-token-abcdef123456', $integration->leads_api_token);

        $this->get(route('portal-integrations.index'))
            ->assertSee('Leads API token')
            ->assertSee('••••••••3456');
    }
}