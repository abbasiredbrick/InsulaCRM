<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'user_id',
        'body',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mentions()
    {
        return $this->belongsToMany(User::class, 'message_mentions')->withTimestamps();
    }

    /**
     * Preview text for badges/notifications (keeps @mentions readable).
     */
    public function preview(): string
    {
        $text = preg_replace('/\s+/', ' ', trim($this->body));

        return mb_strlen($text) > 120
            ? mb_substr($text, 0, 120).'…'
            : $text;
    }

    /**
     * Escape the body and wrap resolved mentions in a highlight span, so the
     * timeline renders user content safely with @names standing out.
     */
    public function renderBody(): string
    {
        $body = e((string) $this->body);

        foreach ($this->mentions as $mentioned) {
            $token = '@'.e($mentioned->name);
            $highlight = '<span class="badge bg-azure-lt ms-1 fw-normal">'.$token.'</span>';
            $body = preg_replace('/'.preg_quote($token, '/').'(?![A-Za-z0-9])/u', $highlight, $body);
        }

        return $body !== '' && $body !== null ? nl2br($body, true) : '';
    }
}
