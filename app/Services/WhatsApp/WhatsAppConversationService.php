<?php

namespace App\Services\WhatsApp;

use App\Models\Lead;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationSeen;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;

/**
 * Conversation/message persistence for the WhatsApp Inbox.
 *
 * Every method takes an explicit `$tenantId` rather than reaching for the
 * authenticated user: inbound messages arrive on a public webhook where there
 * is no session, and the tenant is resolved from the Meta phone_number_id
 * before the service is called.
 */
class WhatsAppConversationService
{
    public function findOrCreateConversation(int $tenantId, string $waId, ?Lead $lead = null, ?string $profileName = null): WhatsappConversation
    {
        $waId = $this->normalizeWaId($waId);

        $conversation = WhatsappConversation::where('tenant_id', $tenantId)
            ->where('wa_id', $waId)
            ->first();

        if (! $conversation) {
            $conversation = WhatsappConversation::create([
                'tenant_id' => $tenantId,
                'lead_id' => $lead?->id,
                'assigned_user_id' => $lead?->agent_id,
                'wa_id' => $waId,
                'display_name' => $lead?->full_name ?? $profileName,
                'status' => 'open',
            ]);

            return $conversation;
        }

        if ($lead) {
            if (! $conversation->lead_id) {
                $conversation->lead_id = $lead->id;
            }

            if (! $conversation->assigned_user_id) {
                $conversation->assigned_user_id = $lead->agent_id;
            }

            if (! $conversation->display_name) {
                $conversation->display_name = $lead->full_name;
            }
        }

        if (! $conversation->display_name && $profileName) {
            $conversation->display_name = $profileName;
        }

        if ($conversation->isDirty()) {
            $conversation->save();
        }

        return $conversation;
    }

    public function addInboundMessage(WhatsappConversation $conversation, string $body, ?string $wamid = null, ?array $meta = null): WhatsappMessage
    {
        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'user_id' => null,
            'direction' => 'inbound',
            'body' => $body,
            'wamid' => $wamid,
            'meta' => $meta,
            'received_at' => Carbon::now(),
        ]);

        $this->touchConversation($conversation, $message);

        return $message;
    }

    public function addInboundMediaMessage(
        WhatsappConversation $conversation,
        string $mediaType,
        string $body,
        array $media,
        ?string $wamid = null,
        ?array $meta = null,
    ): WhatsappMessage {
        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'user_id' => null,
            'direction' => 'inbound',
            'body' => $body,
            'wamid' => $wamid,
            'meta' => $meta,
            'received_at' => Carbon::now(),
            'media_type' => $mediaType,
            'media_path' => $media['path'] ?? null,
            'media_name' => $media['name'] ?? null,
            'media_mime' => $media['mime'] ?? null,
            'media_size' => $media['size'] ?? null,
        ]);

        $this->touchConversation($conversation, $message);

        return $message;
    }

    public function addOutboundMessage(WhatsappConversation $conversation, int $userId, string $body, ?string $wamid = null, ?array $meta = null): WhatsappMessage
    {
        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'user_id' => $userId,
            'direction' => 'outbound',
            'body' => $body,
            'wamid' => $wamid,
            'meta' => $meta,
        ]);

        $this->touchConversation($conversation, $message);

        return $message;
    }

    public function addOutboundMediaMessage(
        WhatsappConversation $conversation,
        int $userId,
        string $mediaType,
        string $body,
        array $media,
        ?string $wamid = null,
        ?array $meta = null,
    ): WhatsappMessage {
        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'user_id' => $userId,
            'direction' => 'outbound',
            'body' => $body,
            'wamid' => $wamid,
            'meta' => $meta,
            'status' => $wamid ? 'sent' : null,
            'media_type' => $mediaType,
            'media_path' => $media['path'] ?? null,
            'media_name' => $media['name'] ?? null,
            'media_mime' => $media['mime'] ?? null,
            'media_size' => $media['size'] ?? null,
        ]);

        $this->touchConversation($conversation, $message);

        return $message;
    }

    /**
     * Advance the delivery receipt (statuses webhook) on an outbound message.
     * Notifications can arrive out of order, so a receipt never regresses.
     */
    public function recordDelivery(WhatsappConversation $conversation, ?string $wamid, string $status): bool
    {
        if (! $wamid) {
            return false;
        }

        $message = WhatsappMessage::where('tenant_id', $conversation->tenant_id)
            ->where('whatsapp_conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('wamid', $wamid)
            ->first();

        if (! $message || WhatsappMessage::deliveryRank($status) <= WhatsappMessage::deliveryRank($message->status)) {
            return false;
        }

        $message->status = $status;
        $message->save();

        return true;
    }

    /**
     * Move a user's read watermark past the given message id (idempotent).
     */
    public function markSeen(WhatsappConversation $conversation, int $userId, ?int $messageId): void
    {
        if (! $messageId) {
            return;
        }

        $seen = WhatsappConversationSeen::where('whatsapp_conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->first();

        if ($seen) {
            if ($messageId > (int) $seen->last_seen_message_id) {
                $seen->last_seen_message_id = $messageId;
                $seen->save();
            }

            return;
        }

        WhatsappConversationSeen::create([
            'whatsapp_conversation_id' => $conversation->id,
            'user_id' => $userId,
            'last_seen_message_id' => $messageId,
        ]);
    }

    /**
     * The highest inbound message id that the user has read in this conversation.
     */
    public function seenMessageId(WhatsappConversation $conversation, int $userId): ?int
    {
        return WhatsappConversationSeen::where('whatsapp_conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->value('last_seen_message_id');
    }

    /**
     * Unread inbound counts for many conversations at once, keyed by
     * conversation id. One query, left-joining the per-user read watermark.
     *
     * @param  array<int>  $conversationIds
     * @return array<int, int>
     */
    public function unreadCounts(int $userId, array $conversationIds): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        return WhatsappMessage::query()
            ->leftJoin('whatsapp_conversation_seens as s', function ($join) use ($userId) {
                $join->on('s.whatsapp_conversation_id', '=', 'whatsapp_messages.whatsapp_conversation_id')
                    ->where('s.user_id', $userId);
            })
            ->whereIn('whatsapp_messages.whatsapp_conversation_id', $conversationIds)
            ->where('whatsapp_messages.direction', 'inbound')
            ->where(function ($query) {
                $query->whereNull('s.last_seen_message_id')
                    ->orWhereColumn('whatsapp_messages.id', '>', 's.last_seen_message_id');
            })
            ->groupBy('whatsapp_messages.whatsapp_conversation_id')
            ->selectRaw('whatsapp_messages.whatsapp_conversation_id, count(*) as total')
            ->pluck('total', 'whatsapp_conversation_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Match an inbound wa_id (digits, with country code) to a lead of the
     * tenant. Lead phones are stored as typed, so the match tolerates a missing
     * country code by also comparing the last nine digits (the national
     * significant number).
     */
    public function findLeadByWaId(int $tenantId, string $waId): ?Lead
    {
        $digits = $this->normalizeWaId($waId);

        if ($digits === '') {
            return null;
        }

        $tail = substr($digits, -9);

        return Lead::where('tenant_id', $tenantId)
            ->whereNotNull('phone')
            ->where(function ($query) use ($digits, $tail) {
                $query->where('phone', 'like', '%'.$digits.'%');

                if ($tail !== $digits) {
                    $query->orWhere('phone', 'like', '%'.$tail.'%');
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    public function normalizeWaId(string $waId): string
    {
        return preg_replace('/[^0-9]/', '', $waId) ?? '';
    }

    protected function touchConversation(WhatsappConversation $conversation, WhatsappMessage $message): void
    {
        $conversation->last_message_at = $message->created_at;
        if ($conversation->status === 'closed') {
            $conversation->status = 'open';
        }
        $conversation->save();
    }
}
