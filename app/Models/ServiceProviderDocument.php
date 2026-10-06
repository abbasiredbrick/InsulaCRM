<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceProviderDocument extends Model
{
    public const TYPES = [
        'trade_license' => 'Trade License',
        'emirates_id' => 'Emirates ID',
        'other' => 'Other',
    ];

    protected $fillable = [
        'service_provider_id', 'doc_type', 'path', 'original_name', 'mime', 'size',
    ];

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->doc_type] ?? $this->doc_type;
    }

    public function humanSize(): string
    {
        if ($this->size >= 1048576) {
            return round($this->size / 1048576, 1).' MB';
        }

        return round($this->size / 1024).' KB';
    }
}
