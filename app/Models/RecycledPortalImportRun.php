<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecycledPortalImportRun extends Model
{
    public const MODES = [
        'preview' => 'Preview',
        'import' => 'Import',
    ];

    public const STATUSES = [
        'queued' => 'Queued',
        'running' => 'Running',
        'ready' => 'Ready to Import',
        'ready_with_errors' => 'Ready with Warnings',
        'completed' => 'Completed',
        'completed_with_errors' => 'Completed with Warnings',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ];

    public const PORTALS = [
        'bayut' => 'Bayut',
        'dubizzle' => 'Dubizzle',
        'property_finder' => 'Property Finder',
    ];

    protected $fillable = [
        'tenant_id',
        'portal_integration_id',
        'user_id',
        'portal',
        'mode',
        'status',
        'criteria',
        'cursor',
        'preview_counts',
        'observed_count',
        'out_of_range_count',
        'skipped_invalid_count',
        'skipped_no_contact_count',
        'contactable_count',
        'imported_count',
        'already_active_count',
        'duplicate_count',
        'enriched_count',
        'error_count',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'cursor' => 'array',
            'preview_counts' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(PortalIntegration::class, 'portal_integration_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecycledLeadSourceEvent::class, 'import_run_id');
    }
}
