<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function createIntegration(string $portal, array $overrides = []): PortalIntegration
    {
        return PortalIntegration::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'portal'    => $portal,
            'is_active' => true,
        ], $overrides));
    }

    public function test_settings_page_shows_both_portals_and_webhook_urls(): void
    {
        $this->createIntegration('bayut', ['webhook_secret' => 's3cret']);

        $this->get(route('portal-integrations.index'))
            ->assertOk()
            ->assertSee('Bayut')
            ->assertSee('Property Finder')
            ->assertSee('portal/webhooks/bayut')
            ->assertSee('Test connection');
    }

    public function test_update_saves_encrypted_credentials(): void
    {
        $this->post(route('portal-integrations.update', 'bayut'), [
            'api_token'       => 'bayut-bearer-token-1234567890',
            'base_url'        => 'https://push.bayut.example',
            'agent_reference' => 'AGENT-1',
            'webhook_secret'  => 'wh-s3cret',
        ])->assertRedirect();

        $integration = PortalIntegration::where('tenant_id', $this->tenant->id)->where('portal', 'bayut')->firstOrFail();

        $this->assertTrue($integration->is_active);
        $this->assertSame('bayut-bearer-token-1234567890', $integration->api_token);
        $this->assertSame('https://push.bayut.example', $integration->base_url);
        $this->assertSame('wh-s3cret', $integration->webhook_secret);

        $this->get(route('portal-integrations.index'))
            ->assertSee('••••••••7890');
    }

    public function test_toggle_disables_integration(): void
    {
        $integration = $this->createIntegration('bayut');

        $this->post(route('portal-integrations.toggle', 'bayut'))->assertRedirect();

        $this->assertFalse($integration->fresh()->is_active);
    }

    public function test_dubizzle_webhook_creates_lead_when_signature_is_valid(): void
    {
        $this->createIntegration('bayut', ['webhook_secret' => 'shh']);
        $this->tenant->update(['custom_options' => array_merge($this->tenant->custom_options ?? [], [
            'portal_leads' => ['unmatched' => 'distribute', 'notify_admins' => true],
        ])]);

        $property = $this->createProperty([
            'intent' => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no' => 'RERA-WH-1',
            'availability' => 'listed',
            'dubizzle_listing_reference' => 'DZ-777',
        ]);

        $payload = [
            'id' => 'chat-123',
            'enquirer' => [
                'name'         => 'Ahmed Khan',
                'phone_number' => '+971 50 111 2222',
            ],
            'listing' => [
                'url'         => 'https://www.dubizzle.ae/listing/dz-777',
                'reference'   => 'DZ-777',
                'received_at' => 1750000000,
            ],
        ];

        $body = json_encode($payload);
        $signature = md5('shh' . $body);

        $this->postJson(route('portal.webhooks.receive', 'bayut'), $payload, [
            'X-dubizzle-Signature' => $signature,
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('leads', [
            'tenant_id'   => $this->tenant->id,
            'first_name'  => 'Ahmed',
            'last_name'   => 'Khan',
            'phone'       => '+971 50 111 2222',
            'lead_source' => 'dubizzle',
        ]);

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('lead_source', 'dubizzle')
            ->first();
        $this->assertSame($property->id, $lead->custom_fields['listing_property_id'] ?? null);
        $this->assertSame('DZ-777', $lead->custom_fields['listing_reference'] ?? null);
        $this->assertSame($this->adminUser->id, $lead->agent_id);

        $this->assertDatabaseHas('lead_property', [
            'lead_id'     => $lead->id,
            'property_id' => $property->id,
        ]);
    }

    public function test_webhook_rejects_bad_signature(): void
    {
        $this->createIntegration('bayut', ['webhook_secret' => 'shh']);

        $payload = ['id' => 'chat-1', 'name' => 'Bad Signer', 'phone' => '+971 00 000 0000'];

        $this->postJson(route('portal.webhooks.receive', 'bayut'), $payload, [
            'X-dubizzle-Signature' => md5('wrong-secret' . json_encode($payload)),
        ])->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_webhook_deduplicates_by_phone(): void
    {
        $this->createIntegration('bayut');

        $first = ['id' => 'chat-1', 'name' => 'Sara Ali', 'phone' => '+971 50 999 0000'];
        $second = ['id' => 'chat-2', 'name' => 'Sara Ali', 'phone' => '+971 50 999 0000', 'message' => 'Again'];

        $this->postJson(route('portal.webhooks.receive', 'bayut'), $first)->assertOk();
        $this->postJson(route('portal.webhooks.receive', 'bayut'), $second)->assertOk();

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_propertyfinder_webhook_creates_lead_without_signature_when_single_integration(): void
    {
        $this->createIntegration('propertyfinder');

        $payload = [
            'lead' => [
                'id'          => 'pf-lead-9',
                'customerName' => 'Priya Sharma',
                'phoneNumber' => '+971 55 888 7777',
                'listing'     => ['reference' => 'PF-11'],
            ],
        ];

        $this->postJson(route('portal.webhooks.receive', 'propertyfinder'), $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('leads', [
            'tenant_id'   => $this->tenant->id,
            'first_name'  => 'Priya',
            'phone'       => '+971 55 888 7777',
            'lead_source' => 'propertyfinder',
        ]);
    }

    public function test_push_to_bayut_marks_unit_live(): void
    {
        $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
            'default_location_id' => '29965',
        ]);
        $this->tenant->update(['custom_options' => array_merge($this->tenant->custom_options ?? [], [
            'portal_wallet' => ['balance' => 100, 'auto_sync' => false, 'endpoint' => null],
        ])]);

        Http::fake([
            'https://push.bayut.example/agents' => Http::response([
                'data' => [
                    ['id' => 2848922, 'status' => 'on', 'name' => 'Admin'],
                ],
            ], 200),
            'https://push.bayut.example/listings' => Http::response([
                'id'  => 'BN-991',
                'url' => 'https://www.bayut.com/.../bn-991',
            ], 200),
        ]);

        $property = $this->createProperty([
            'intent' => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no' => 'RERA-PUSH-1',
            'availability' => 'ready_to_list',
            'rent_price' => 95000,
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://push.bayut.example/listings') {
                return false;
            }

            $body = $request->data();

            return isset($body['rentPrice'], $body['permitNumber'], $body['agentId'])
                && ! isset($body['price'], $body['salePrice']);
        });

        $property->refresh();

        $this->assertSame('live', $property->bayut_status);
        $this->assertSame('BN-991', $property->bayut_listing_id);
        $this->assertSame('https://www.bayut.com/.../bn-991', $property->bayut_url);
    }

    public function test_push_to_bayut_draft_is_not_marked_live(): void
    {
        $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
            'default_location_id' => '29969',
        ]);
        $this->tenant->update(['custom_options' => array_merge($this->tenant->custom_options ?? [], [
            'portal_wallet' => ['balance' => 100, 'auto_sync' => false, 'endpoint' => null],
        ])]);

        Http::fake([
            'https://push.bayut.example/agents' => Http::response([
                'data' => [['id' => 2848922, 'status' => 'rejected', 'meta' => ['rejection_reason' => 'A short bio about yourself is not provided']]],
            ], 200),
            'https://push.bayut.example/listings' => Http::response([
                'id' => '16549743',
                'status' => 'draft',
                'permitNumberStatus' => 'pending',
                'activeImagesCount' => 0,
                'canBeActivated' => 1,
            ], 200),
        ]);

        $property = $this->createProperty([
            'intent' => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no' => 'MADHMOUN-1',
            'availability' => 'ready_to_list',
            'rent_price' => 100000,
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $property->refresh();

        $this->assertSame('draft', $property->bayut_status);
        $this->assertSame('16549743', $property->bayut_listing_id);
        $this->assertSame('not_listed', $property->dubizzle_status);
    }

    public function test_push_to_bayut_sale_sends_price_field(): void
    {
        $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
            'default_location_id' => '29969',
        ]);
        $this->tenant->update(['custom_options' => array_merge($this->tenant->custom_options ?? [], [
            'portal_wallet' => ['balance' => 100, 'auto_sync' => false, 'endpoint' => null],
        ])]);

        Http::fake([
            'https://push.bayut.example/agents' => Http::response([
                'data' => [['id' => 2848922, 'status' => 'on', 'name' => 'Admin']],
            ], 200),
            'https://push.bayut.example/listings' => Http::response([
                'id' => '16542879',
            ], 200),
        ]);

        $property = $this->createProperty([
            'intent' => 'sale',
            'property_category' => 'apartment',
            'rera_permit_no' => 'RERA-PUSH-SALE',
            'availability' => 'ready_to_list',
            'list_price' => 1500000,
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://push.bayut.example/listings') {
                return false;
            }

            $body = $request->data();

            return isset($body['price'], $body['purposeId'])
                && $body['purposeId'] === 1
                && ! isset($body['rentPrice'], $body['salePrice']);
        });

        $property->refresh();
        $this->assertSame('live', $property->bayut_status);
        $this->assertSame('16542879', $property->bayut_listing_id);
    }

    public function test_push_blocks_offplan_rent_on_bayut(): void
    {
        $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
            'default_location_id' => '29969',
        ]);

        $property = $this->createProperty([
            'intent' => 'rent',
            'market_class' => 'off_plan',
            'property_category' => 'apartment',
            'rera_permit_no' => 'RERA-PUSH-OP',
            'availability' => 'ready_to_list',
            'rent_price' => 95000,
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('not_listed', $property->fresh()->bayut_status);
    }

    public function test_push_requires_a_bayut_location_to_be_selected(): void
    {
        $this->createIntegration('bayut', ['base_url' => 'https://push.bayut.example']);

        $property = $this->createProperty([
            'intent' => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no' => 'RERA-PUSH-3',
            'availability' => 'ready_to_list',
            'rent_price' => 95000,
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $property->refresh();
        $this->assertSame('not_listed', $property->bayut_status);
    }

    public function test_update_portal_location_sets_selected_bayut_location(): void
    {
        $this->createIntegration('bayut', [
            'location_catalog' => [
                ['id' => 29965, 'label' => 'UAE | Dubai | Deira | Al Muraqqabat | Mohamed Shamamit Building'],
                ['id' => 29951, 'label' => 'UAE | Dubai | Deira | Hor Al Anz | Ahmed Ali Building'],
            ],
        ]);

        $property = $this->createProperty();

        $this->post(route('inventory.portal-location', $property), ['location_id' => '29965'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $property->refresh();
        $this->assertSame('29965', $property->bayut_location_id);
        $this->assertSame('UAE | Dubai | Deira | Al Muraqqabat | Mohamed Shamamit Building', $property->bayut_location_label);
    }

    public function test_update_portal_location_rejects_unsynced_location(): void
    {
        $this->createIntegration('bayut', [
            'location_catalog' => [
                ['id' => 1, 'label' => 'UAE | Dubai'],
            ],
        ]);

        $property = $this->createProperty();

        $this->post(route('inventory.portal-location', $property), ['location_id' => '999'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $property->refresh();
        $this->assertNull($property->bayut_location_id);
        $this->assertNull($property->bayut_location_label);
    }

    public function test_push_fails_without_configured_integration(): void
    {
        $property = $this->createProperty([
            'intent' => 'sale',
            'property_category' => 'villa',
            'rera_permit_no' => 'RERA-PUSH-2',
            'availability' => 'ready_to_list',
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $property->refresh();
        $this->assertSame('not_listed', $property->bayut_status);
    }

    public function test_push_to_propertyfinder_creates_draft_and_publishes(): void
    {
        $this->createIntegration('propertyfinder', [
            'api_token'            => 'pf-key',
            'api_secret'           => 'pf-secret',
            'public_profile_id'    => 'PUB-1',
            'default_location_id'  => 'LOC-100',
        ]);

        Http::fake([
            'api.propertyfinder.ae*' => Http::response(['id' => 'PF-42'], 200),
        ]);

        $property = $this->createProperty([
            'intent' => 'sale',
            'property_category' => 'townhouse',
            'rera_permit_no' => 'RERA-PF-1',
            'availability' => 'ready_to_list',
            'list_price' => 3500000,
        ]);

        $this->post(route('inventory.push', [$property, 'propertyfinder']))->assertRedirect();

        $property->refresh();

        $this->assertSame('live', $property->propertyfinder_status);
        $this->assertSame('PF-42', $property->propertyfinder_listing_reference);
    }

    public function test_sync_locations_pulls_paginated_catalog_and_dedupes(): void
    {
        $integration = $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
        ]);

        Http::fake([
            'https://push.bayut.example/locations*' => Http::sequence([
                Http::response(['data' => [
                    ['id' => 29951, 'breadcrumb' => ['en' => ['UAE', 'Dubai', 'Deira', 'Hor Al Anz', 'Ahmed Ali Building']]],
                    ['id' => 29951, 'breadcrumb' => ['en' => ['UAE', 'Dubai', 'Duplicate']]],
                ], 'meta' => ['current_page' => 1, 'last_page' => 2, 'per_page' => 15, 'total' => 30]], 200),
                Http::response(['data' => [
                    ['id' => 29964, 'breadcrumb' => 'UAE | Dubai | Deira | Al Nakhee Street | Zenith One Tower'],
                ], 'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 15, 'total' => 30]], 200),
            ]),
        ]);

        $this->post(route('portal-integrations.sync-locations', 'bayut'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $integration->refresh();

        $this->assertCount(2, $integration->location_catalog);
        $this->assertSame('UAE | Dubai | Deira | Al Nakhee Street | Zenith One Tower', $integration->location_catalog[0]['label']);
        $this->assertSame(29964, $integration->location_catalog[0]['id']);
        $this->assertNotNull($integration->locations_synced_at);
    }

    public function test_sync_locations_reports_failure(): void
    {
        $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
        ]);

        Http::fake([
            'https://push.bayut.example/locations*' => Http::response(['message' => 'nope'], 500),
        ]);

        $this->post(route('portal-integrations.sync-locations', 'bayut'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_sync_locations_requires_a_saved_integration(): void
    {
        $this->post(route('portal-integrations.sync-locations', 'bayut'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_sync_locations_resumes_from_stored_cursor(): void
    {
        $integration = $this->createIntegration('bayut', [
            'base_url' => 'https://push.bayut.example',
            'location_sync_page' => 40,
        ]);

        Http::fake([
            'https://push.bayut.example/locations*' => Http::response([
                'data' => [['id' => 29951, 'breadcrumb' => 'UAE | Dubai | Deira | Hor Al Anz | Block A']],
                'meta' => ['current_page' => 41, 'last_page' => 41, 'per_page' => 15, 'total' => 615],
            ], 200),
        ]);

        $this->post(route('portal-integrations.sync-locations', 'bayut'))
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/locations') && str_contains($request->url(), 'page=41');
        });

        $integration->refresh();
        $this->assertNull($integration->location_sync_page, 'cursor should reset once the catalog is fully crawled');
    }

    public function test_location_search_proxies_bayut_and_returns_options(): void
    {
        $this->createIntegration('bayut', ['base_url' => 'https://push.bayut.example']);

        Http::fake([
            'https://push.bayut.example/locations*' => Http::response([
                'data' => [
                    ['id' => 22070, 'breadcrumb' => 'UAE | Abu Dhabi | Al Raha Beach | Al Bandar | Taj Residences'],
                    ['id' => 12320, 'breadcrumb' => 'UAE | Dubai | Business Bay | Executive Towers | Taj Hotel'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 2],
            ], 200),
        ]);

        $this->getJson(route('inventory.bayut-locations-search', ['q' => 'taj']))
            ->assertOk()
            ->assertJsonPath('results.0.value', 22070)
            ->assertJsonPath('results.0.label', 'UAE | Abu Dhabi | Al Raha Beach | Al Bandar | Taj Residences');
    }

    public function test_update_portal_location_accepts_remote_found_location_with_label(): void
    {
        $integration = $this->createIntegration('bayut', ['location_catalog' => []]);
        $property = $this->createProperty();

        $this->post(route('inventory.portal-location', $property), [
            'location_id' => '22070',
            'location_label' => 'UAE | Abu Dhabi | Al Raha Beach | Al Bandar | Taj Residences',
        ])->assertRedirect()->assertSessionHas('success');

        $property->refresh();
        $this->assertSame('22070', $property->bayut_location_id);
        $this->assertSame('UAE | Abu Dhabi | Al Raha Beach | Al Bandar | Taj Residences', $property->bayut_location_label);

        $this->assertCount(1, $integration->fresh()->location_catalog);
    }

    public function test_permit_label_and_regime_adapt_to_bayut_location(): void
    {
        $property = $this->createProperty(['bayut_location_label' => 'UAE | Abu Dhabi | Al Hudayriat Island | Wadeem Gardens']);
        $this->assertSame('Madhmoun Permit No', $property->permit_label);
        $this->assertSame('abudhabi', $property->permit_regime);

        $property->update(['bayut_location_label' => 'UAE | Dubai | Deira | Al Muraqqabat | Mohamed Shamamit Building']);
        $this->assertSame('RERA Permit No', $property->permit_label);
        $this->assertSame('dubai', $property->permit_regime);

        $property->update(['bayut_location_label' => null, 'city' => 'Sharjah']);
        $this->assertSame('Permit No', $property->permit_label);
        $this->assertSame('generic', $property->permit_regime);
    }
}