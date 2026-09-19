<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadCommission extends Model
{
    use HasFactory;

    public const TYPE_COMPANY = 'company';
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';

    public const STATUS_EARNED = 'earned';
    public const STATUS_PAID = 'paid';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'agent_id',
        'participant_type',
        'participant_name',
        'participant_email',
        'gross_commission',
        'share_pct',
        'amount',
        'funding_source',
        'basis',
        'status',
        'commissioned_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_commission' => 'decimal:2',
            'share_pct' => 'decimal:2',
            'amount' => 'decimal:2',
            'commissioned_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function isCompany(): bool
    {
        return $this->participant_type === self::TYPE_COMPANY;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function getFundingLabelAttribute(): string
    {
        return match ($this->funding_source) {
            'from_agent' => __('Paid from main agent'),
            'from_company' => __('Paid from company'),
            'from_both' => __('Half company / half main agent'),
            default => __('—'),
        };
    }
}