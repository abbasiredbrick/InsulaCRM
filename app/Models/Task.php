<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
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
        'agent_id',
        'created_by',
        'title',
        'due_date',
        'due_time',
        'is_completed',
        'status',
        'calendar_provider',
        'calendar_event_id',
        'reminder_minutes',
        'reminder_sent_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'reminder_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function getStatusAttribute(): string
    {
        $status = $this->attributes['status'] ?? 'scheduled';

        return $status === 'open' ? 'scheduled' : $status;
    }

    public function setIsCompletedAttribute($value): void
    {
        $this->attributes['status'] = $value ? 'completed' : 'scheduled';
        $this->attributes['is_completed'] = (int) (bool) $value;
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->getStatusAttribute() === 'completed';
    }

    public static function statusLabel(string $status): string
    {
        return __(self::STATUSES[$status] ?? ucwords(str_replace('_', ' ', $status)));
    }

    /**
     * Check if this task is overdue.
     */
    public function getIsOverdueAttribute(): bool
    {
        $due = $this->dueAt();

        return ! $this->is_completed && $due && $due->isPast();
    }

    /**
     * The full due moment (date + optional time). Formatted for the event's
     * start so calendar sync and overdue checks honour the time when set.
     */
    public function dueAt(): ?Carbon
    {
        if (! $this->due_date) {
            return null;
        }

        $tz = config('app.timezone');

        if (! blank($this->due_time)) {
            return Carbon::parse($this->due_date->format('Y-m-d').' '.$this->due_time, $tz);
        }

        return Carbon::parse($this->due_date->format('Y-m-d'), $tz)->endOfDay();
    }

    /**
     * Human-friendly "Due" label (date + time when set).
     */
    public function getDueLabelAttribute(): string
    {
        if (! $this->due_date) {
            return '—';
        }

        $label = $this->due_date->format('M d, Y');

        if (! blank($this->due_time)) {
            $label .= ' • '.Carbon::parse($this->due_time)->format('h:i A');
        }

        return $label;
    }

    /**
     * Get the lead this task belongs to.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Get the agent this task is assigned to.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * The agent who created/assigned this task (the task owner).
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Chronological activity log entries.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->latest();
    }

    /**
     * External calendar event links, one per involved user.
     */
    public function calendarEventLinks(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(CalendarEventLink::class, 'eventable');
    }
}
