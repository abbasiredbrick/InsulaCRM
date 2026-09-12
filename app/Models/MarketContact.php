<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketContact extends Model
{
    use HasFactory;

    public const TYPES = [
        'landlord' => 'Landlord / Owner',
        'investor' => 'Investor / Buyer',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'reached' => 'Reached',
        'not_reached' => 'Not Reached',
        'wrong_number' => 'Wrong Number',
        'not_interested' => 'Not Interested',
        'call_back' => 'Call Back Later',
        'do_not_contact' => 'Do Not Contact',
        'converted' => 'Converted',
    ];

    protected $fillable = [
        'tenant_id',
        'import_id',
        'type',
        'first_name',
        'last_name',
        'phone',
        'email',
        'company',
        'unit_no',
        'building',
        'address',
        'community',
        'property_category',
        'bedrooms',
        'bathrooms',
        'rent_price',
        'unit_status',
        'budget',
        'preferred_type',
        'requirements',
        'status',
        'called_by',
        'last_called_at',
        'call_notes',
        'converted_type',
        'converted_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'rent_price' => 'decimal:2',
            'budget' => 'decimal:2',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'last_called_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function import()
    {
        return $this->belongsTo(MarketImport::class, 'import_id');
    }

    public function caller()
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    public function converted()
    {
        if ($this->converted_type === 'property') {
            return $this->belongsTo(Property::class, 'converted_id');
        }

        if ($this->converted_type === 'lead') {
            return $this->belongsTo(Lead::class, 'converted_id');
        }

        return null;
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getUnitSummaryAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->unit_no ? 'Unit '.$this->unit_no : null,
            $this->building,
            $this->community,
        ])));
    }

    /**
     * Whether this row holds enough unit information to convert to inventory.
     */
    public function getHasUnitDetailsAttribute(): bool
    {
        return trim((string) ($this->unit_no ?: $this->building ?: $this->address ?: $this->community)) !== '';
    }

    public function getHasContactDetailsAttribute(): bool
    {
        return trim((string) ($this->phone ?: $this->email ?: $this->first_name)) !== '';
    }
}
