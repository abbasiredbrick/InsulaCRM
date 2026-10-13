<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user read watermark: which inbound message id a user last saw in a
 * conversation. Unread counts are inbound messages newer than the watermark.
 */
class WhatsappConversationSeen extends Model
{
    protected $fillable = [
        'whatsapp_conversation_id',
        'user_id',
        'last_seen_message_id',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'whatsapp_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
