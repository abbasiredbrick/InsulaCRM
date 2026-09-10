<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'property_id',
        'type',
        'path',
        'external_url',
        'caption',
        'original_name',
        'uploaded_by',
        'mime_type',
        'size',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function uploader()
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }

    /**
     * Absolute URL to display / export this media item.
     */
    public function url(): ?string
    {
        if ($this->external_url) {
            return $this->external_url;
        }

        if ($this->path) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path);
        }

        return null;
    }
}