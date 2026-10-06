<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceProviderRegistrationLink extends Model
{
    protected $fillable = [
        'tenant_id', 'token', 'provider_email', 'provider_name', 'internal_note',
        'created_by', 'expires_at', 'used_at', 'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function state(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->used_at !== null) {
            return 'used';
        }

        if ($this->expires_at->isPast()) {
            return 'expired';
        }

        return 'active';
    }

    public function url(): string
    {
        return url('/service-providers/register/'.$this->token);
    }
}
