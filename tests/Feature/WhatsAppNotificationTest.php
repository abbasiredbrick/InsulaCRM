<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\LeadAssigned;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppNotificationTest extends TestCase
{
    protected function configureWhatsApp(array $config = []): void
    {
        Integration::create([
            'tenant_id' => $this->tenant->id,
            'category' => 'whatsapp',
            'driver' => 'cloud-api',
            'name' => 'WhatsApp Cloud API (Meta)',
            'config' => array_merge([
                'phone_number_id' => '519394444600658',
                'access_token' => 'test-token',
                'api_version' => 'v21.0',
            ], $config),
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    protected function optedInAgent(string $number = '+971501234567')
    {
        $this->adminUser->update([
            'whatsapp_number' => $number,
            'whatsapp_opt_in' => true,
        ]);

        return $this->adminUser;
    }

    public function test_via_includes_whatsapp_when_opted_in_and_configured(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $agent = $this->optedInAgent();

        $notification = new LeadAssigned($this->createLead(), $this->tenant);

        $this->assertContains(WhatsAppChannel::class, $notification->via($agent));
    }

    public function test_via_excludes_whatsapp_when_opt_out(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $this->adminUser->update([
            'whatsapp_number' => '+971501234567',
            'whatsapp_opt_in' => false,
        ]);

        $notification = new LeadAssigned($this->createLead(), $this->tenant);

        $this->assertNotContains(WhatsAppChannel::class, $notification->via($this->adminUser));
    }

    public function test_via_excludes_whatsapp_when_no_provider_configured(): void
    {
        $this->actingAsAdmin();
        $agent = $this->optedInAgent();

        $notification = new LeadAssigned($this->createLead(), $this->tenant);

        $this->assertNotContains(WhatsAppChannel::class, $notification->via($agent));
    }

    public function test_channel_sends_the_assigned_lead_template(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $agent = $this->optedInAgent();

        $lead = $this->createLead([
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971500000000',
            'lead_source' => 'portal',
            'notes' => "Interested in\nMarina Tower",
            'custom_fields' => ['contact_link' => 'https://www.propertyfinder.ae/leads/v1/lead/message/abc'],
        ]);
        $notification = new LeadAssigned($lead, $this->tenant);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);

        app(WhatsAppChannel::class)->send($agent, $notification);

        Http::assertSent(function ($request) use ($agent) {
            $body = $request->data();

            return str_contains($request->url(), '/519394444600658/messages')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $body['to'] === '971501234567'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'new_lead_assigned'
                && $body['template']['language']['code'] === 'en_US'
                && $body['template']['components'][0]['type'] === 'body'
                && $body['template']['components'][0]['parameters'][0]['text'] === $agent->name
                && $body['template']['components'][0]['parameters'][1]['text'] === 'Sara Khan'
                && $body['template']['components'][0]['parameters'][4]['text'] === 'Interested in Marina Tower'
                && $body['template']['components'][0]['parameters'][5]['text'] === 'https://www.propertyfinder.ae/leads/v1/lead/message/abc'
                && count($body['template']['components'] ?? []) === 1
                && ! isset($body['template']['components'][1]);
        });
    }

    public function test_reassignment_whatsapps_the_new_agent_only(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $this->adminUser->update(['whatsapp_number' => '+971501234567', 'whatsapp_opt_in' => true]);

        $old = $this->createUserWithRole('agent', [
            'whatsapp_number' => '+971509999999',
            'whatsapp_opt_in' => true,
        ]);

        $lead = $this->createLead(['first_name' => 'Sara', 'last_name' => 'Khan', 'phone' => '+971500000000']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);

        // New agent (admin) gets the channel; the previous agent does not.
        $toNotification = new \App\Notifications\LeadReassigned($lead, $old, $this->adminUser, 'Reassigned');
        $this->assertContains(WhatsAppChannel::class, $toNotification->via($this->adminUser));

        $fromNotification = new \App\Notifications\LeadReassigned($lead, $old, $this->adminUser, 'Reassigned');
        $this->assertNotContains(WhatsAppChannel::class, $fromNotification->via($old));
    }

    public function test_channel_does_not_send_when_agent_opted_out(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $this->adminUser->update([
            'whatsapp_number' => '+971501234567',
            'whatsapp_opt_in' => false,
        ]);

        Http::fake();

        app(WhatsAppChannel::class)->send(
            $this->adminUser,
            new LeadAssigned($this->createLead(), $this->tenant),
        );

        Http::assertNothingSent();
    }

    public function test_channel_does_not_send_when_no_provider_configured(): void
    {
        $this->actingAsAdmin();
        $agent = $this->optedInAgent();

        Http::fake();

        app(WhatsAppChannel::class)->send(
            $agent,
            new LeadAssigned($this->createLead(), $this->tenant),
        );

        Http::assertNothingSent();
    }

    public function test_settings_page_shows_the_whatsapp_card(): void
    {
        $this->actingAsAdmin();

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee('WhatsApp (Cloud API)');
    }

    public function test_test_whatsapp_endpoint_requires_an_active_provider(): void
    {
        $this->actingAsAdmin();

        $this->postJson(route('settings.testWhatsApp'), ['to' => '+971501234567'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_test_whatsapp_endpoint_sends_the_lead_assigned_template(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);

        $this->postJson(route('settings.testWhatsApp'), ['to' => '+971 50 123 4567'])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['to'] === '971501234567'
                && $body['template']['name'] === 'new_lead_assigned'
                && $body['template']['language']['code'] === 'en_US'
                && $body['template']['components'][0]['type'] === 'body'
                && $body['template']['components'][0]['parameters'][1]['text'] === 'Sample Lead'
                && count($body['template']['components'] ?? []) === 1
                && ! isset($body['template']['components'][1]);
        });
    }

    public function test_resaving_the_whatsapp_config_keeps_the_stored_token(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp(['access_token' => 'secret-token-value']);

        $this->post(route('integrations.store'), [
            'category' => 'whatsapp',
            'driver' => 'cloud-api',
            'config' => [
                'phone_number_id' => '519394444600658',
                'access_token' => '',
                'business_account_id' => '574516909079551',
            ],
        ])->assertRedirect();

        $integration = Integration::withoutGlobalScopes()
            ->where('category', 'whatsapp')
            ->where('driver', 'cloud-api')
            ->firstOrFail();

        $this->assertSame('secret-token-value', $integration->config['access_token']);
        $this->assertSame('574516909079551', $integration->config['business_account_id']);
    }

    public function test_invite_agent_stores_whatsapp_fields(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $role = \App\Models\Role::where('name', 'agent')->first();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'New Agent',
            'email' => 'new-agent@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'receives_leads' => '1',
            'phone' => '+971500000001',
            'whatsapp_number' => '+971500000002',
            'whatsapp_opt_in' => '1',
        ])->assertRedirect();

        $created = \App\Models\User::where('email', 'new-agent@example.com')->firstOrFail();

        $this->assertSame('+971500000001', $created->phone);
        $this->assertSame('+971500000002', $created->whatsapp_number);
        $this->assertTrue($created->whatsapp_opt_in);
    }

    public function test_profile_update_saves_whatsapp_fields(): void
    {
        $this->actingAsAdmin();

        $this->put(route('profile.update'), [
            'name' => $this->adminUser->name,
            'email' => $this->adminUser->email,
            'phone' => '+971509999999',
            'whatsapp_number' => '+971508888888',
            'whatsapp_opt_in' => '1',
        ])->assertRedirect(route('profile.edit'));

        $this->adminUser->refresh();

        $this->assertSame('+971508888888', $this->adminUser->whatsapp_number);
        $this->assertTrue($this->adminUser->whatsapp_opt_in);
    }
}
