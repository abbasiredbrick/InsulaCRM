<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail for Bayut listing credits: adjustments, publishes and refunds.
 */
class PortalCreditsLedger extends Model
{
    use HasFactory;

    protected $table = 'portal_credits_ledger';

    protected $fillable = [
        'tenant_id',
        'property_id',
        'portal',
        'type',
        'amount',
        'reason',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        static::creating(function (PortalCreditsLedger $entry) {
            if (blank($entry->tenant_id) && auth()->check()) {
                $entry->tenant_id = auth()->user()->tenant_id;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}