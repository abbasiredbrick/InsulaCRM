<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AvailabilitySource extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'contact_info',
        'default_building',
        'default_category',
        'default_city',
        'column_map',
        'parse_options',
        'status_map',
        'last_imported_at',
    ];

    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'parse_options' => 'array',
            'status_map' => 'array',
            'last_imported_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function runs()
    {
        return $this->hasMany(AvailabilityImportRun::class, 'source_id')->latest();
    }

    public function latestRun()
    {
        return $this->hasOne(AvailabilityImportRun::class, 'source_id')->latestOfMany();
    }

    public function properties()
    {
        return $this->hasMany(Property::class, 'availability_source_id');
    }
}