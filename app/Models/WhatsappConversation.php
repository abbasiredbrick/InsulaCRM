<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WhatsappConversation extends Model
{
    use HasFactory;
    use HasTenant;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'assigned_user_id',
        'wa_id',
        'display_name',
        'status',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class)->orderBy('created_at', 'asc');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(WhatsappMessage::class)->latestOfMany();
    }

    public function seens(): HasMany
    {
        return $this->hasMany(WhatsappConversationSeen::class);
    }

    /**
     * How many inbound messages the given user has not opened yet.
     */
    public function unreadCountFor(int $userId): int
    {
        $lastSeen = WhatsappConversationSeen::where('whatsapp_conversation_id', $this->id)
            ->where('user_id', $userId)
            ->value('last_seen_message_id');

        return WhatsappMessage::where('whatsapp_conversation_id', $this->id)
            ->where('direction', 'inbound')
            ->when($lastSeen, fn ($q) => $q->where('id', '>', $lastSeen))
            ->count();
    }
}
