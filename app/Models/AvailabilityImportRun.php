<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AvailabilityImportRun extends Model
{
    protected $fillable = [
        'tenant_id',
        'source_id',
        'user_id',
        'filename',
        'format',
        'status',
        'total_rows',
        'created_rows',
        'updated_rows',
        'missing_rows',
        'conflict_rows',
        'skipped_rows',
        'notes',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function source()
    {
        return $this->belongsTo(AvailabilitySource::class, 'source_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
