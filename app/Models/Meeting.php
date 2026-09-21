<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'deal_id',
        'property_id',
        'agent_id',
        'created_by',
        'title',
        'scheduled_at',
        'duration_minutes',
        'status',
        'notes',
        'feedback',
        'calendar_provider',
        'calendar_event_id',
        'reminder_minutes',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The agent who created the meeting.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * External calendar event links, one per involved user.
     */
    public function calendarEventLinks()
    {
        return $this->morphMany(CalendarEventLink::class, 'eventable');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'scheduled'
            && $this->scheduled_at
            && $this->scheduled_at->lt(now()->subMinutes($this->duration_minutes ?? 0));
    }

    public static function statusLabel(string $status): string
    {
        return __(self::STATUSES[$status] ?? ucwords(str_replace('_', ' ', $status)));
    }
}
