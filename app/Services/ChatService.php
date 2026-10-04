<?php

namespace App\Services;

use App\Events\MessageCreated;
use App\Events\NotificationCreated;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewChatMessage;
use Illuminate\Support\Facades\Log;

/**
 * Business logic for internal team chat: 1:1 and group conversations, lead-linked
 * threads, and @mentions. Keeps participants, read markers, notifications and
 * broadcast events in sync for one atomic "send".
 *
 * All work is synchronous (no queue) because the production server has no worker.
 */
class ChatService
{
    /**
     * Find an existing direct conversation with a colleague, or create one.
     */
    public function findOrCreateDirect(User $actor, User $other, ?int $leadId = null): Conversation
    {
        $existing = Conversation::where('type', Conversation::TYPE_DIRECT)
            ->where(function ($q) use ($actor, $other) {
                $q->whereHas('participants', fn ($p) => $p->whereKey($actor->id))
                    ->whereHas('participants', fn ($p) => $p->whereKey($other->id));
            })
            ->where(function ($q) use ($leadId) {
                $q->whereNull('lead_id')->orWhere('lead_id', $leadId);
            })
            ->latest('id')
            ->first();

        if ($existing) {
            $this->ensureParticipant($existing, $actor);
            $this->ensureParticipant($existing, $other);

            return $existing;
        }

        $conversation = Conversation::create([
            'tenant_id' => $actor->tenant_id,
            'type' => Conversation::TYPE_DIRECT,
            'lead_id' => $leadId,
            'created_by' => $actor->id,
        ]);

        $conversation->participants()->sync([
            $actor->id => ['tenant_id' => $conversation->tenant_id, 'last_read_at' => now()],
            $other->id => ['tenant_id' => $conversation->tenant_id, 'last_read_at' => null],
        ]);

        return $conversation;
    }

    /**
     * Create a group conversation with the given member ids.
     */
    public function createGroup(User $actor, array $memberIds, ?string $title = null, ?int $leadId = null): Conversation
    {
        $memberIds = array_values(array_unique(array_filter(
            array_map('intval', $memberIds),
            fn ($id) => $id !== $actor->id
        )));

        $conversation = Conversation::create([
            'tenant_id' => $actor->tenant_id,
            'type' => Conversation::TYPE_GROUP,
            'title' => $title,
            'lead_id' => $leadId,
            'created_by' => $actor->id,
        ]);

        $conversation->participants()->attach($actor->id, ['tenant_id' => $conversation->tenant_id, 'last_read_at' => now()]);

        foreach ($memberIds as $memberId) {
            $conversation->participants()->attach($memberId, ['tenant_id' => $conversation->tenant_id]);
        }

        return $conversation;
    }

    /**
     * The conversation for a lead (created on first open). Participants mirror
     * the lead's current team: main agent + active internal co-agents + viewer.
     */
    public function leadConversation(Lead $lead, User $actor): Conversation
    {
        $conversation = Conversation::where('lead_id', $lead->id)
            ->latest('id')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'tenant_id' => $lead->tenant_id,
                'type' => Conversation::TYPE_GROUP,
                'title' => __('Team chat · :name', ['name' => $lead->full_name]),
                'lead_id' => $lead->id,
                'created_by' => $actor->id,
            ]);
        }

        foreach ($this->leadMembers($lead, $actor) as $member) {
            $this->ensureParticipant($conversation, $member);
        }

        return $conversation;
    }

    /**
     * Everyone who should be on a lead thread: the lead owner, active internal
     * co-agents, and the person opening it.
     */
    public function leadMembers(Lead $lead, User $actor): \Illuminate\Support\Collection
    {
        $members = collect();

        if ($lead->agent) {
            $members->push($lead->agent);
        }

        foreach ($lead->activeLeadAgents as $leadAgent) {
            if ($leadAgent->agent_id) {
                $user = $leadAgent->agent;
                if ($user && ! $members->contains('id', $user->id)) {
                    $members->push($user);
                }
            }
        }

        if (! $members->contains('id', $actor->id)) {
            $members->push($actor);
        }

        return $members;
    }

    /**
     * Persist a message, update read/participation state, notify recipients,
     * and broadcast it to the conversation channel.
     *
     * @param  array<int>  $mentionedIds
     */
    public function sendMessage(User $actor, Conversation $conversation, string $body, array $mentionedIds = []): Message
    {
        $mentionedIds = $this->validParticipants($conversation, $mentionedIds, $actor);

        // @mentions pull the target into the conversation so they can reply.
        foreach ($mentionedIds as $mentionedId) {
            $this->ensureParticipant($conversation, User::find($mentionedId));
        }

        $message = Message::create([
            'tenant_id' => $actor->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $actor->id,
            'body' => $body,
        ]);

        if ($mentionedIds) {
            $message->mentions()->attach(
                collect($mentionedIds)
                    ->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $message->tenant_id]])
                    ->all(),
            );
        }

        $conversation->markRead($actor);

        $this->notifyRecipients($message, $conversation, $actor, $mentionedIds);

        return $message;
    }

    /**
     * Conversation list for the user's chat sidebar, newest activity first.
     */
    public function conversationsFor(User $user): \Illuminate\Support\Collection
    {
        return $user->conversations()
            ->with([
                'participants:id,name,role_id,agent_code',
                'lead:id,first_name,last_name',
                'latestMessage.author:id,name',
            ])
            ->withCount(['messages'])
            ->get()
            ->filter(fn (Conversation $c) => $c->messages_count > 0 || $c->participants->count() > 1)
            ->sortByDesc(fn (Conversation $c) => optional($c->latestMessage)->created_at ?? $c->updated_at)
            ->values();
    }

    /**
     * Total unread messages across all of the user's conversations.
     */
    public function unreadCount(User $user): int
    {
        $ids = $user->conversations()->pluck('conversations.id');
        $unread = 0;

        foreach (Conversation::whereIn('id', $ids)->with('participants')->get() as $conversation) {
            $unread += $conversation->unreadCountFor($user);
        }

        return $unread;
    }

    /**
     * Users the current tenant member can chat with (active members only).
     */
    public function conversationableUsers(?User $actor = null): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('tenant_id', $actor?->tenant_id ?? auth()->user()->tenant_id)
            ->where('is_active', true)
            ->with('role:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role_id', 'agent_code']);
    }

    /**
     * Ensure a user is listed as a participant without duplicating the pivot.
     */
    public function ensureParticipant(Conversation $conversation, ?User $user): void
    {
        if (! $user || $conversation->participants()->whereKey($user->id)->exists()) {
            return;
        }

        $conversation->participants()->attach($user->id, ['tenant_id' => $conversation->tenant_id]);
    }

    /**
     * Filter the passed ids down to users who belong to the tenant, silently
     * dropping anything invalid.
     *
     * @param  array<int>  $ids
     * @return array<int>
     */
    protected function validParticipants(Conversation $conversation, array $ids, User $actor): array
    {
        return User::where('tenant_id', $conversation->tenant_id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->reject(fn ($id) => $id === $actor->id)
            ->all();
    }

    /**
     * Create the in-app notification + realtime bell bump for every recipient
     * except the sender. Mentioned users always receive one; other participants
     * receive one per message.
     *
     * @param  array<int>  $mentionedIds
     */
    protected function notifyRecipients(Message $message, Conversation $conversation, User $actor, array $mentionedIds): void
    {
        $recipients = $conversation->participants()
            ->whereKeyNot($actor->id)
            ->pluck('users.id')
            ->merge($mentionedIds)
            ->unique()
            ->all();

        $dbChannel = new \Illuminate\Notifications\Channels\DatabaseChannel;

        foreach ($recipients as $userId) {
            $user = User::find($userId);
            if (! $user) {
                continue;
            }

            try {
                $notification = new NewChatMessage(
                    $conversation,
                    $message,
                    $actor,
                    in_array($userId, $mentionedIds, true),
                );

                // DatabaseChannel expects the id pre-assigned (Laravel's own
                // NotificationSender does this before handing off to the channel).
                $notification->id = (string) \Illuminate\Support\Str::uuid();

                $stored = $dbChannel->send($user, $notification);

                event(new NotificationCreated(
                    $stored->id,
                    $user->id,
                    'chat',
                    $stored->data,
                    $stored->created_at?->toIso8601String() ?? now()->toIso8601String(),
                ));

                if (in_array('mail', $notification->via($user), true)) {
                    app(\Illuminate\Notifications\Channels\MailChannel::class)->send($user, $notification);
                }
            } catch (\Throwable $e) {
                Log::error("ChatService notification failed for user {$userId}: {$e->getMessage()}");
            }
        }

        try {
            broadcast(new MessageCreated($message));
        } catch (\Throwable $e) {
            Log::error('ChatService broadcast failed: '.$e->getMessage());
        }
    }
}
