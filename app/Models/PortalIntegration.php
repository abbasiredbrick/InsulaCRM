<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortalIntegration extends Model
{
    public const PORTALS = [
        'bayut'          => 'Bayut / Dubizzle',
        'propertyfinder' => 'Property Finder',
    ];

    protected $fillable = [
        'tenant_id',
        'portal',
        'is_active',
        'api_token',
        'api_secret',
        'base_url',
        'agent_reference',
        'public_profile_id',
        'default_location_id',
        'webhook_secret',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'api_token' => 'encrypted',
            'api_secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getPortalLabelAttribute(): string
    {
        return __(self::PORTALS[$this->portal] ?? ucfirst($this->portal));
    }

    public function getWebhookUrlAttribute(): string
    {
        return route('portal.webhooks.receive', $this->portal, true);
    }

    public function maskedToken(): ?string
    {
        return $this->mask($this->api_token);
    }

    public function maskedSecret(): ?string
    {
        return $this->mask($this->api_secret);
    }

    public function maskedWebhookSecret(): ?string
    {
        return $this->mask($this->webhook_secret);
    }

    protected function mask(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $tail = substr($value, -4);

        return "••••••••{$tail}";
    }
}