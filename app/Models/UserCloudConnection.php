<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCloudConnection extends Model
{
    use HasFactory;

    public const PROVIDERS = ['google', 'microsoft'];

    public const SCOPES = ['calendar', 'drive'];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'provider',
        'scope',
        'provider_account_email',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tokenIsValid(): bool
    {
        return ! $this->token_expires_at || $this->token_expires_at->isAfter(now()->addMinutes(5));
    }
}