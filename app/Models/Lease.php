<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lease extends Model
{
    use HasFactory;

    /**
     * Window (in days) before contract expiry when reminders are fired.
     */
    public const REMINDER_WINDOW_DAYS = 45;

    public const STATUSES = [
        'active' => 'Active',
        'renewed' => 'Renewed',
        'expired' => 'Expired',
        'moved_out' => 'Moved Out',
    ];

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'property_id',
        'buyer_id',
        'agent_id',
        'unit_address',
        'community',
        'unit_no',
        'contract_start_date',
        'contract_end_date',
        'rent_price',
        'admin_fee',
        'status',
        'reminder_sent_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'rent_price' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Full days between now and the contract end date (negative once past).
     */
    public function getDaysUntilExpiryAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->contract_end_date, false);
    }

    /**
     * Effective status shown on the UI — overlays the stored status with the
     * run-down window once the contract is within 45 days or has passed.
     */
    public function getExpiryStatusAttribute(): string
    {
        if (in_array($this->status, ['expired', 'moved_out'], true)) {
            return $this->status;
        }

        if ($this->days_until_expiry < 0) {
            return 'expired';
        }

        if ($this->days_until_expiry <= self::REMINDER_WINDOW_DAYS) {
            return 'expiring';
        }

        return 'active';
    }

    public function getExpiryStatusLabelAttribute(): string
    {
        return match ($this->expiry_status) {
            'expiring' => 'Expiring Soon',
            'active' => 'Active',
            default => self::STATUSES[$this->expiry_status] ?? ucfirst($this->expiry_status),
        };
    }

    /**
     * Contracts whose expiry reminder has not been fired yet for the given window.
     */
    public function scopeRemindable($query, ?Carbon $now = null)
    {
        $now ??= now();

        return $query
            ->whereIn('status', ['active', 'renewed'])
            ->whereNull('reminder_sent_at')
            ->whereBetween('contract_end_date', [
                $now->startOfDay(),
                $now->startOfDay()->addDays(self::REMINDER_WINDOW_DAYS),
            ]);
    }

    /**
     * Active leases whose contract has already passed.
     */
    public function scopeOverdue($query, ?Carbon $now = null)
    {
        $now ??= now();

        return $query
            ->whereIn('status', ['active', 'renewed'])
            ->where('contract_end_date', '<', $now->startOfDay());
    }

    /**
     * Unit title for display (snapshot first, then property name).
     */
    public function getUnitLabelAttribute(): string
    {
        $parts = array_filter([
            $this->unit_no,
            $this->community,
            $this->unit_address,
        ]);

        if ($parts) {
            return implode(' · ', array_values($parts));
        }

        return $this->property?->display_name ?? '#'.$this->id;
    }
}
