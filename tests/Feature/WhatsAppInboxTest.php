<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppInboxTest extends TestCase
{
    protected function configureWhatsApp(array $config = []): Integration
    {
        return Integration::create([
            'tenant_id' => $this->tenant->id,
            'category' => 'whatsapp',
            'driver' => 'cloud-api',
            'name' => 'WhatsApp Cloud API (Meta)',
            'config' => array_merge([
                'phone_number_id' => '519394444600658',
                'access_token' => 'test-token',
                'api_version' => 'v21.0',
                'verify_token' => 'keystone-verify',
                'app_secret' => 'test-app-secret',
            ], $config),
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_webhook_verification_returns_the_challenge(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $this->get(route('whatsapp.webhook.verify').'?hub_mode=subscribe&hub_verify_token=keystone-verify&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_webhook_verification_rejects_wrong_token(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $this->get(route('whatsapp.webhook.verify').'?hub_mode=subscribe&hub_verify_token=nope&hub_challenge=12345')
            ->assertForbidden();
    }

    public function test_webhook_ingests_an_inbound_message_and_links_the_lead(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $lead = $this->createLead(['first_name' => 'Aisha', 'last_name' => 'Rashid', 'phone' => '+971529603039']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '519394444600658'],
                        'contacts' => [['wa_id' => '971529603039']],
                        'messages' => [[
                            'from' => '971529603039',
                            'id' => 'wamid.INBOUND',
                            'type' => 'text',
                            'text' => ['body' => 'Hello, is the unit still available?'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'test-app-secret');

        $this->postJson('/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => $signature])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $conversation = WhatsappConversation::where('tenant_id', $this->tenant->id)
            ->where('wa_id', '971529603039')
            ->firstOrFail();

        $this->assertSame($lead->id, $conversation->lead_id);
        $this->assertSame('Aisha Rashid', $conversation->display_name);
        $this->assertSame($lead->agent_id, $conversation->assigned_user_id);

        $message = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->firstOrFail();
        $this->assertSame('inbound', $message->direction);
        $this->assertSame('Hello, is the unit still available?', $message->body);
        $this->assertSame('wamid.INBOUND', $message->wamid);
    }

    public function test_webhook_rejects_a_bad_signature(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '519394444600658'],
                        'contacts' => [['wa_id' => '971529603039']],
                        'messages' => [[
                            'from' => '971529603039',
                            'id' => 'wamid.BAD',
                            'type' => 'text',
                            'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/webhooks/whatsapp', $payload, ['X-Hub-Signature-256' => 'sha256=deadbeef'])
            ->assertForbidden();

        $this->assertSame(0, WhatsappConversation::count());
    }

    public function test_webhook_ignores_unknown_phone_number_id(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '999999999'],
                        'contacts' => [['wa_id' => '971529603039']],
                        'messages' => [[
                            'from' => '971529603039',
                            'id' => 'wamid.X',
                            'type' => 'text',
                            'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $this->postJson('/webhooks/whatsapp', $payload)
            ->assertOk();

        $this->assertSame(0, WhatsappConversation::count());
    }

    public function test_inbox_index_requires_the_enabled_flag(): void
    {
        $this->actingAsRole('agent');

        $this->get(route('whatsapp.inbox.index'))->assertForbidden();
    }

    public function test_enabled_agent_reaches_the_inbox(): void
    {
        $admin = $this->actingAsAdmin();
        $agent = $this->createUserWithRole('agent', ['whatsapp_inbox_enabled' => true]);

        WhatsappConversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'assigned_user_id' => $agent->id,
            'wa_id' => '971529603039',
            'display_name' => 'Aisha Rashid',
        ]);

        $this->actingAs($agent);

        $this->get(route('whatsapp.inbox.index'))
            ->assertOk()
            ->assertSee('Aisha Rashid');
    }

    public function test_reply_sends_via_cloud_api_and_stores_the_outbound_message(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $conversation = WhatsappConversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'assigned_user_id' => $this->adminUser->id,
            'wa_id' => '971529603039',
            'display_name' => 'Aisha Rashid',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.REPLY']],
            ], 200),
        ]);

        $this->postJson(route('whatsapp.inbox.reply', $conversation), ['message' => 'Yes, still available.'])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/519394444600658/messages')
                && $body['to'] === '971529603039'
                && $body['type'] === 'text'
                && $body['text']['body'] === 'Yes, still available.';
        });

        $this->assertDatabaseHas('whatsapp_messages', [
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Yes, still available.',
            'wamid' => 'wamid.REPLY',
            'user_id' => $this->adminUser->id,
        ]);
    }

    public function test_reply_failure_is_surfaced_as_an_error(): void
    {
        $this->actingAsAdmin();
        $this->configureWhatsApp();
        $conversation = WhatsappConversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'assigned_user_id' => $this->adminUser->id,
            'wa_id' => '971529603039',
            'display_name' => 'Aisha Rashid',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 400),
        ]);

        $this->postJson(route('whatsapp.inbox.reply', $conversation), ['message' => 'hello'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame(0, WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)->count());
    }

    public function test_agent_scope_only_sees_their_own_conversations(): void
    {
        $this->actingAsAdmin();

        $other = $this->createUserWithRole('agent', ['whatsapp_inbox_enabled' => true]);
        $agent = $this->createUserWithRole('agent', ['whatsapp_inbox_enabled' => true]);

        $mine = WhatsappConversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'assigned_user_id' => $agent->id,
            'wa_id' => '971500000001',
            'display_name' => 'My Conversation',
        ]);
        WhatsappConversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'assigned_user_id' => $other->id,
            'wa_id' => '971500000002',
            'display_name' => 'Other Conversation',
        ]);

        $this->actingAs($agent);

        $this->get(route('whatsapp.inbox.index'))
            ->assertOk()
            ->assertSee('My Conversation')
            ->assertDontSee('Other Conversation');
    }

    public function test_invite_agent_stores_the_inbox_flag(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $role = \App\Models\Role::where('name', 'agent')->first();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'Inbox Agent',
            'email' => 'inbox@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'receives_leads' => '1',
            'phone' => '+971500000001',
            'whatsapp_number' => '+971500000002',
            'whatsapp_opt_in' => '1',
            'whatsapp_inbox_enabled' => '1',
        ])->assertRedirect();

        $this->assertTrue(\App\Models\User::where('email', 'inbox@example.com')->firstOrFail()->whatsapp_inbox_enabled);
    }
}
