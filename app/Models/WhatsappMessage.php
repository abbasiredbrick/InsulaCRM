<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessage extends Model
{
    use HasFactory;
    use HasTenant;

    protected $fillable = [
        'tenant_id',
        'whatsapp_conversation_id',
        'user_id',
        'direction',
        'body',
        'wamid',
        'meta',
        'received_at',
        'status',
        'media_type',
        'media_path',
        'media_name',
        'media_mime',
        'media_size',
    ];

    protected $casts = [
        'meta' => 'array',
        'received_at' => 'datetime',
        'media_size' => 'integer',
    ];

    public const MEDIA_LABELS = [
        'image' => 'Photo',
        'video' => 'Video',
        'audio' => 'Voice note',
        'document' => 'Document',
        'sticker' => 'Sticker',
        'location' => 'Location',
        'contact' => 'Contact',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'whatsapp_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isMedia(): bool
    {
        return $this->media_type !== null;
    }

    /**
     * Short human label used in the conversation list preview, e.g. "Photo".
     */
    public function previewText(): string
    {
        if ($this->media_type === 'document' && $this->media_name) {
            return '📄 '.$this->media_name;
        }

        $label = self::MEDIA_LABELS[$this->media_type ?? ''] ?? null;

        if ($label) {
            $emoji = ['image' => '📷', 'video' => '🎬', 'audio' => '🎤', 'sticker' => '🃏', 'location' => '📍', 'contact' => '👤'][$this->media_type] ?? '📎';

            return $emoji.' '.$label;
        }

        return (string) $this->body;
    }

    /**
     * Rank used to only advance delivery receipts, never regress them.
     */
    public static function deliveryRank(?string $status): int
    {
        return match ($status) {
            'read' => 4,
            'delivered' => 3,
            'sent' => 2,
            default => 1,
        };
    }
}
