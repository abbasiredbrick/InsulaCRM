<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecycledLeadSourceEvent extends Model
{
    protected $fillable = [
        'tenant_id',
        'recycled_lead_id',
        'import_run_id',
        'portal',
        'external_id',
        'external_id_hash',
        'provider_type',
        'provider_target',
        'provider_status',
        'event_at',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function recycledLead(): BelongsTo
    {
        return $this->belongsTo(RecycledLead::class);
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(RecycledPortalImportRun::class, 'import_run_id');
    }
}
