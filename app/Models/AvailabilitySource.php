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
        'url',
        'default_building',
        'default_category',
        'default_city',
        'default_deposit',
        'default_admin_fee',
        'default_tawtheeq_fee',
        'column_map',
        'parse_options',
        'status_map',
        'missing_status',
        'last_imported_at',
    ];

    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'parse_options' => 'array',
            'status_map' => 'array',
            'missing_status' => 'string',
            'default_deposit' => 'decimal:2',
            'default_admin_fee' => 'decimal:2',
            'default_tawtheeq_fee' => 'decimal:2',
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
