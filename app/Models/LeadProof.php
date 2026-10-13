<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class LeadProof extends Model
{
    protected $fillable = [
        'tenant_id',
        'lead_id',
        'uploaded_by',
        'type',
        'file_path',
        'original_name',
        'mime_type',
        'size',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_by' => 'integer',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
