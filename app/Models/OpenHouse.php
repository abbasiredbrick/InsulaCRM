<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class OpenHouse extends Model
{
    public const STATUSES = [
        'scheduled' => 'Scheduled',
        'active' => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'tenant_id',
        'property_id',
        'agent_id',
        'event_date',
        'start_time',
        'end_time',
        'status',
        'description',
        'notes',
        'attendee_count',
        'reminder_minutes',
        'reminder_sent_at',
    ];

    protected $casts = [
        'event_date' => 'date',
        'reminder_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function attendees()
    {
        return $this->hasMany(OpenHouseAttendee::class);
    }

    public static function statusLabel(string $status): string
    {
        return __(self::STATUSES[$status] ?? ucwords(str_replace('_', ' ', $status)));
    }
}
