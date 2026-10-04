<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewChatMessage;
use App\Services\ChatService;
use Tests\TestCase;

class ChatTest extends TestCase
{
    public function test_chat_index_page_loads(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('chat.index'));

        $response->assertStatus(200);
        $response->assertSee('Team Chat');
    }

    public function test_chat_index_supports_conversation_query(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $response = $this->get(route('chat.index', ['conversation' => $conversation->id]));

        $response->assertStatus(200);
        $response->assertSee('chat-thread');
    }

    public function test_lead_page_shows_chat_button(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $response = $this->get(route('leads.show', $lead));

        $response->assertStatus(200);
        $response->assertSee(route('leads.chat', $lead));
        $response->assertSee('Chat');
    }

    public function test_lead_chat_creates_conversation_and_redirects(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $response = $this->get(route('leads.chat', $lead));

        $conversation = Conversation::where('lead_id', $lead->id)->first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->isLeadLinked());
        $response->assertRedirect(route('chat.show', $conversation));
    }

    public function test_lead_chat_reuses_existing_conversation(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $this->get(route('leads.chat', $lead));
        $this->get(route('leads.chat', $lead));

        $this->assertSame(1, Conversation::where('lead_id', $lead->id)->count());
    }

    public function test_direct_conversation_is_reused(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');

        $first = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);
        $second = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $this->assertSame($first->id, $second->id);
    }

    public function test_start_direct_via_route(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');

        $response = $this->post(route('chat.direct'), [
            'user_id' => $other->id,
        ]);

        $conversation = Conversation::where('type', Conversation::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->whereKey($this->adminUser->id))
            ->whereHas('participants', fn ($q) => $q->whereKey($other->id))
            ->first();
        $this->assertNotNull($conversation);
        $response->assertRedirect(route('chat.show', $conversation));
    }

    public function test_start_group_via_route(): void
    {
        $this->actingAsAdmin();
        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('acquisition_agent');

        $response = $this->post(route('chat.group'), [
            'title' => 'Pipeline squad',
            'user_ids' => [$agentA->id, $agentB->id],
        ]);

        $conversation = Conversation::where('title', 'Pipeline squad')->first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->isGroup());
        $this->assertCount(3, $conversation->participants);
        $response->assertRedirect(route('chat.show', $conversation));
    }

    public function test_send_message_stores_message_notification_and_mention(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $response = $this->postJson(route('chat.store', $conversation), [
            'body' => 'Hi <b>there</b> @'.$other->name,
            'mentioned_user_ids' => [$other->id],
        ]);

        $response->assertStatus(200)->assertJson(['body' => 'Hi <b>there</b> @'.$other->name]);

        $message = Message::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($message);
        $this->assertSame($this->adminUser->id, $message->user_id);
        $this->assertContains($other->id, $message->mentions()->pluck('users.id')->all());
        $this->assertStringContainsString('&lt;b&gt;', $message->renderBody());

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $other->id,
            'type' => \App\Notifications\NewChatMessage::class,
        ]);
    }

    public function test_mention_brings_outsider_into_conversation(): void
    {
        $this->actingAsAdmin();
        $conversation = app(ChatService::class)->findOrCreateDirect(
            $this->adminUser,
            $this->createUserWithRole('agent'),
        );
        $outsider = $this->createUserWithRole('disposition_agent');

        $this->postJson(route('chat.store', $conversation), [
            'body' => 'Looping in @'.$outsider->name,
            'mentioned_user_ids' => [$outsider->id],
        ]);

        $this->assertTrue($conversation->fresh()->isParticipant($outsider));
    }

    public function test_message_feed_with_and_without_after(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);
        $first = $conversation->messages()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'body' => 'first',
        ]);
        $second = $conversation->messages()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $other->id,
            'body' => 'second',
        ]);

        $initial = $this->getJson(route('chat.messages', $conversation));
        $initial->assertStatus(200)->assertJsonCount(2, 'messages');

        $incremental = $this->getJson(route('chat.messages', $conversation).'?after='.$first->id);
        $incremental->assertStatus(200);
        $this->assertSame([$second->id], array_column($incremental->json('messages'), 'id'));
    }

    public function test_non_participant_is_blocked_from_thread(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $stranger = $this->createUserWithRole('marketing');
        $this->actingAs($stranger);

        $this->get(route('chat.show', $conversation))->assertStatus(403);
        $this->postJson(route('chat.store', $conversation), ['body' => 'nope'])->assertStatus(403);
    }

    public function test_unread_count_and_mark_read(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        app(ChatService::class)->sendMessage($other, $conversation, 'ping');

        $this->assertSame(1, app(ChatService::class)->unreadCount($this->adminUser->fresh()));
        $this->assertSame(1, $conversation->unreadCountFor($this->adminUser->fresh()));

        app(ChatService::class)->sendMessage($this->adminUser, $conversation, 'pong');

        $this->assertSame(0, $conversation->fresh()->unreadCountFor($this->adminUser->fresh()));
        $this->assertSame(0, app(ChatService::class)->unreadCount($this->adminUser->fresh()));
    }

    public function test_unread_endpoint_returns_count(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);
        app(ChatService::class)->sendMessage($other, $conversation, 'hi');

        $this->getJson(route('chat.unread'))->assertJson(['unread' => 1]);
    }

    public function test_search_users_is_tenant_scoped(): void
    {
        $this->actingAsAdmin();
        $teammate = $this->createUserWithRole('agent', ['name' => 'Alice Teammate']);

        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'email' => 'other@test.com',
            'status' => 'active',
            'timezone' => 'America/New_York',
            'currency' => 'USD',
            'date_format' => 'm/d/Y',
            'country' => 'US',
            'measurement_system' => 'imperial',
            'locale' => 'en',
            'distribution_method' => 'round_robin',
        ]);
        $otherTenantUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role_id' => \App\Models\Role::where('name', 'agent')->first()->id,
            'name' => 'Alice Outsider',
            'is_active' => true,
        ]);

        $response = $this->getJson(route('chat.users.search', ['q' => 'Alice']));

        $response->assertStatus(200)->assertJsonFragment(['value' => $teammate->id]);
        $this->assertNotContains(
            $otherTenantUser->id,
            array_column($response->json('results'), 'value'),
        );
    }

    public function test_invalid_mentioned_ids_are_silently_dropped(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $response = $this->postJson(route('chat.store', $conversation), [
            'body' => 'hello',
            'mentioned_user_ids' => [999999, $other->id],
        ]);

        $response->assertStatus(200);
        $message = Message::where('conversation_id', $conversation->id)->first();
        $this->assertSame([$other->id], $message->mentions()->pluck('users.id')->all());
    }

    public function test_mention_email_channel_is_dispatched_for_mentioned_user(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $fake = $this->bindFakeMailChannel();

        $this->postJson(route('chat.store', $conversation), [
            'body' => 'ping @'.$other->name,
            'mentioned_user_ids' => [$other->id],
        ])->assertStatus(200);

        $this->assertCount(1, $fake->sent, 'Mentioned user should receive an email.');
        [$recipient, $notification] = $fake->sent[0];
        $this->assertInstanceOf(NewChatMessage::class, $notification);
        $this->assertContains('mail', $notification->via($recipient));
    }

    public function test_non_mention_message_does_not_email_participants(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $fake = $this->bindFakeMailChannel();

        $this->postJson(route('chat.store', $conversation), [
            'body' => 'just a note',
        ])->assertStatus(200);

        $this->assertSame([], $fake->sent, 'Plain chat messages must not send email.');
    }

    public function test_mention_respects_daily_digest_preference(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent', [
            'notification_delivery' => 'daily_digest',
        ]);
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $fake = $this->bindFakeMailChannel();

        $this->postJson(route('chat.store', $conversation), [
            'body' => 'ping @'.$other->name,
            'mentioned_user_ids' => [$other->id],
        ])->assertStatus(200);

        $this->assertSame([], $fake->sent, 'Daily-digest users should not get an instant mention email.');
    }

    public function test_new_chat_message_notification_mail_alias(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $lead = $this->createLead();
        $conversation = app(ChatService::class)->leadConversation($lead, $this->adminUser);
        $message = $conversation->messages()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'body' => 'ping',
        ]);

        $mention = new NewChatMessage($conversation, $message, $this->adminUser, true);
        $plain = new NewChatMessage($conversation, $message, $this->adminUser, false);

        $this->assertSame(['database', 'mail'], $mention->via($other));
        $this->assertSame(['database'], $plain->via($other));

        $mail = $mention->toMail($other);
        $this->assertInstanceOf(\Illuminate\Notifications\Messages\MailMessage::class, $mail);
        $this->assertStringContainsString('[Keystone]', (string) $mail->subject);
        $this->assertStringContainsString('mentioned you in chat', (string) $mail->subject);
        $this->assertStringContainsString($lead->full_name, (string) $mail->subject);
        $this->assertStringNotContainsString($this->tenant->name, (string) $mail->subject);
    }

    public function test_mention_email_renders_through_real_mail_channel(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);
        $message = $conversation->messages()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'body' => 'ping @'.$other->name,
        ]);

        $notification = new NewChatMessage($conversation, $message, $this->adminUser, true);
        $notification->id = (string) \Illuminate\Support\Str::uuid();

        $sent = app(\Illuminate\Notifications\Channels\MailChannel::class)->send($other, $notification);

        $this->assertNotNull($sent, 'Mention email should render and send.');
    }

    /** Bind a fake MailChannel to the container and return it for assertions. */
    protected function bindFakeMailChannel(): \Illuminate\Notifications\Channels\MailChannel
    {
        $fake = new class extends \Illuminate\Notifications\Channels\MailChannel
        {
            public array $sent = [];

            public function __construct() {}

            public function send($notifiable, $notification)
            {
                $this->sent[] = [$notifiable, $notification];
            }
        };

        $this->app->instance(\Illuminate\Notifications\Channels\MailChannel::class, $fake);

        return $fake;
    }

    public function test_non_numeric_mention_ids_are_rejected(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);

        $this->postJson(route('chat.store', $conversation), [
            'body' => 'hello',
            'mentioned_user_ids' => ['nonsense', $other->id],
        ])->assertStatus(422);

        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_sidebar_fragment_renders(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');
        $conversation = app(ChatService::class)->findOrCreateDirect($this->adminUser, $other);
        app(ChatService::class)->sendMessage($other, $conversation, 'first message');

        $response = $this->get(route('chat.sidebar'))
            ->assertStatus(200)
            ->assertSee($conversation->id);

        $this->assertStringContainsString('first message', $response->getContent());
    }

    public function test_lead_conversation_syncs_lead_agents(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();
        app(ChatService::class)->leadConversation($lead, $this->adminUser);

        $conversation = Conversation::where('lead_id', $lead->id)->first();
        $this->assertContains($this->adminUser->id, $conversation->participants()->pluck('users.id')->all());
    }
}
