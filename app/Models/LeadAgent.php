<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadAgent extends Model
{
    use HasFactory;

    public const FUNDING_FROM_AGENT = 'from_agent';
    public const FUNDING_FROM_COMPANY = 'from_company';
    public const FUNDING_FROM_BOTH = 'from_both';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'agent_id',
        'external_name',
        'external_email',
        'external_company',
        'commission_pct',
        'share_funding',
        'a2a_contract_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'commission_pct' => 'decimal:2',
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

    public function isExternal(): bool
    {
        return $this->agent_id === null;
    }

    public function getDisplayNameAttribute(): string
    {
        if (! $this->isExternal()) {
            return $this->agent?->name ?? __('Deactivated agent');
        }

        return trim($this->external_name ?: $this->external_email) ?: __('External agent');
    }

    /**
     * A concise label describing how this participant's share is funded.
     */
    public function fundingLabel(): string
    {
        return match ($this->share_funding) {
            self::FUNDING_FROM_AGENT => __('Paid from main agent'),
            self::FUNDING_FROM_COMPANY => __('Paid from company'),
            self::FUNDING_FROM_BOTH => __('Paid half company / half agent'),
            default => __('Not set'),
        };
    }
}