<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AvailabilityReview extends Model
{
    public const REASONS = [
        'sheet_says_leased' => 'PM sheet marks this unit as leased',
        'missing_from_sheet' => 'Unit dropped off the PM availability sheet',
    ];

    public const STATUSES = [
        'pending' => 'Awaiting decision',
        'keep_listed' => 'Kept listed (upcoming / diverting leads)',
        'unlist' => 'Unlisted',
    ];

    protected $fillable = [
        'tenant_id',
        'property_id',
        'source_id',
        'run_id',
        'reason',
        'availability_before',
        'status',
        'decided_by',
        'decided_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', '!=', 'pending');
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function source()
    {
        return $this->belongsTo(AvailabilitySource::class, 'source_id');
    }

    public function run()
    {
        return $this->belongsTo(AvailabilityImportRun::class, 'run_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getReasonLabelAttribute(): ?string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    public function getStatusLabelAttribute(): ?string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
