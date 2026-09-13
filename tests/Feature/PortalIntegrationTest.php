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
        $this->createIntegration('bayut', ['base_url' => 'https://push.bayut.example']);

        Http::fake([
            'https://push.bayut.example/listings' => Http::response([
                'reference' => 'BN-991',
                'url'       => 'https://www.bayut.com/.../bn-991',
            ], 200),
        ]);

        $property = $this->createProperty([
            'intent' => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no' => 'RERA-PUSH-1',
            'availability' => 'ready_to_list',
            'rent_price' => 95000,
        ]);

        $this->post(route('inventory.push', [$property, 'bayut']))->assertRedirect();

        $property->refresh();

        $this->assertSame('live', $property->bayut_status);
        $this->assertSame('BN-991', $property->bayut_listing_id);
        $this->assertSame('https://www.bayut.com/.../bn-991', $property->bayut_url);
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
}