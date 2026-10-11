<?php

namespace App\Services\WhatsApp;

use App\Models\Lead;
use App\Models\WhatsappConversation;
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
    public function findOrCreateConversation(int $tenantId, string $waId, ?Lead $lead = null): WhatsappConversation
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
                'display_name' => $lead?->full_name ?? null,
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

        $conversation->last_message_at = $message->created_at;
        if ($conversation->status === 'closed') {
            $conversation->status = 'open';
        }
        $conversation->save();

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

        $conversation->last_message_at = $message->created_at;
        $conversation->save();

        return $message;
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
}
