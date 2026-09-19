<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentCompensation extends Model
{
    use HasFactory;

    public const SPLIT_FIXED = 'fixed';
    public const SPLIT_TIERED = 'tiered';

    public const PAY_COMMISSION_ONLY = 'commission_only';
    public const PAY_SALARY_PLUS_COMMISSION = 'salary_plus_commission';
    public const PAY_FIXED_AMOUNT = 'fixed_amount';

    protected $table = 'agent_compensation';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'split_type',
        'company_pct',
        'agent_pct',
        'pay_structure',
        'base_salary',
        'fixed_amount_per_close',
    ];

    protected function casts(): array
    {
        return [
            'company_pct' => 'decimal:2',
            'agent_pct' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'fixed_amount_per_close' => 'decimal:2',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isTiered(): bool
    {
        return $this->split_type === self::SPLIT_TIERED;
    }

    public function isFixedAmount(): bool
    {
        return $this->pay_structure === self::PAY_FIXED_AMOUNT;
    }
}