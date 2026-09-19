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
        'is_primary',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
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
            $driveId = static::driveFileId($this->external_url);

            if ($driveId) {
                return 'https://drive.google.com/thumbnail?id='.rawurlencode($driveId).'&sz=w1600';
            }

            return $this->external_url;
        }

        if ($this->path) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($this->path);
        }

        return null;
    }

    /**
     * Extract the readable Google Drive file id from a Drive URL.
     */
    public static function driveFileId(string $url): ?string
    {
        if (! str_contains($url, 'drive.google.com')) {
            return null;
        }

        if (preg_match('~/(?:file/d/([^/?#]+)|open\?id=([^&#]+)|uc\?.*?id=([^&#]+))~', $url, $m)) {
            return $m[1] ?: $m[2] ?: $m[3];
        }

        if (preg_match('~[?&]id=([^&]+)~', $url, $m)) {
            return $m[1];
        }

        return null;
    }
}