<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    protected $fillable = [
        'tenant_id',
        'type',
        'title',
        'lead_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('last_read_at', 'last_read_message_id')
            ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class)->latest('id');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isParticipant(User $user): bool
    {
        return $this->participants()->whereKey($user->id)->exists();
    }

    public function isLeadLinked(): bool
    {
        return $this->lead_id !== null;
    }

    /**
     * Whether the given user has any unread messages in this conversation.
     */
    public function unreadFor(User $user): bool
    {
        return $this->unreadQuery($user)->exists();
    }

    /**
     * Number of messages newer than the user's read watermark.
     */
    public function unreadCountFor(User $user): int
    {
        return $this->unreadQuery($user)->count();
    }

    /**
     * Combined read-check for unreadFor/unreadCountFor: messages by other users
     * that are newer than the reader's watermark (null watermark = never read).
     */
    protected function unreadQuery(User $user)
    {
        $pivot = $this->participants()->whereKey($user->id)->first()?->pivot;
        $watermark = $pivot?->last_read_message_id;

        $query = $this->messages()->where('user_id', '!=', $user->id);

        return $watermark !== null ? $query->where('id', '>', $watermark) : $query;
    }

    /**
     * Mark the given user's messages in this conversation as read, advancing the
     * watermark to the latest message so unread counts stay id-precise (no
     * timestamp-second races).
     */
    public function markRead(User $user): void
    {
        $lastId = $this->messages()->where('user_id', '!=', $user->id)->max('id');

        $this->participants()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
            'last_read_message_id' => $lastId,
        ]);
    }

    /**
     * Human-friendly conversation label for list UIs:
     * title for groups, the other participant's name for 1:1s, else "Lead".
     */
    public function displayNameFor(?User $viewer = null): string
    {
        if ($this->isLeadLinked() && isset($this->lead?->full_name)) {
            return (string) $this->lead->full_name;
        }

        if ($this->isGroup()) {
            return $this->title ?? __('Team chat');
        }

        $other = $this->participants()
            ->whereKeyNot($viewer?->id ?? 0)
            ->first();

        return $other?->name ?? __('Chat');
    }
}
