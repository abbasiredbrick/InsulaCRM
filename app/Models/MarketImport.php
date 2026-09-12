<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketImport extends Model
{
    use HasFactory;

    public const TYPES = [
        'landlords' => 'Landlords / Owners',
        'investors' => 'Investors / Buyers',
    ];

    protected $fillable = [
        'tenant_id',
        'user_id',
        'name',
        'type',
        'filename',
        'format',
        'status',
        'total_rows',
        'imported_rows',
        'skipped_rows',
        'error_message',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contacts()
    {
        return $this->hasMany(MarketContact::class, 'import_id');
    }
}
