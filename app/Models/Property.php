<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use App\Services\AddressNormalizationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;

    // ── UAE brokerage inventory ────────────────────────────────────────────

    public const INTENTS = [
        'rent' => 'For Rent',
        'sale' => 'For Sale',
        'both' => 'Both (Rent & Sale)',
    ];

    public const MARKET_CLASSES = [
        'ready'   => 'Ready / Secondary',
        'off_plan' => 'Off-Plan / Primary',
    ];

    public const AVAILABILITIES = [
        'draft'       => 'Draft',
        'ready_to_list' => 'Ready to List',
        'listed'      => 'Listed',
        'reserved'    => 'Reserved',
        'leased'      => 'Leased',
        'sold'        => 'Sold',
        'unlisted'    => 'Unlisted',
    ];

    public const FURNISHING = [
        'unfurnished'   => 'Unfurnished',
        'semi_furnished' => 'Semi-Furnished',
        'furnished'     => 'Furnished',
    ];

    public const RENT_PERIODS = [
        'yearly'  => 'Yearly',
        'monthly' => 'Monthly',
    ];

    /**
     * Bayut-standard categories used by the UAE portals.
     */
    public const CATEGORIES = [
        'apartment'           => 'Apartment',
        'penthouse'           => 'Penthouse',
        'villa'               => 'Villa',
        'villa_compound'      => 'Villa Compound',
        'townhouse'           => 'Townhouse',
        'residential_building' => 'Residential Building',
        'hotel_apartment'     => 'Hotel Apartment',
        'office'              => 'Office',
        'shop'                => 'Shop',
        'showroom'            => 'Showroom',
        'warehouse'           => 'Warehouse',
        'factory'             => 'Factory',
        'commercial_building' => 'Commercial Building',
        'land'                => 'Land / Plot',
        'other'               => 'Other',
    ];

    public const PORTAL_STATUSES = [
        'not_listed' => 'Not Listed',
        'live'       => 'Live',
        'removed'    => 'Removed',
    ];

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'address',
        'city',
        'state',
        'zip_code',
        'property_type',
        'bedrooms',
        'bathrooms',
        'square_footage',
        'year_built',
        'lot_size',
        'estimated_value',
        'repair_estimate',
        'after_repair_value',
        'asking_price',
        'our_offer',
        'maximum_allowable_offer',
        'condition',
        'distress_markers',
        'list_price',
        'listing_status',
        'listed_at',
        'sold_at',
        'sold_price',
        'notes',
        'availability_source_id',
        'source_unit_ref',
        'availability_synced_at',
        // ── Brokerage fields ──
        'intent',
        'market_class',
        'property_category',
        'community',
        'sub_community',
        'developer_name',
        'handover_date',
        'title_deed_no',
        'rera_permit_no',
        'plot_no',
        'building_no',
        'unit_no',
        'floor_no',
        'rent_price',
        'deposit_amount',
        'admin_fee',
        'rent_period',
        'service_charge',
        'furnishing',
        'parking',
        'availability',
        'assigned_agent_id',
        'owner_name',
        'owner_phone',
        'owner_email',
        'marketing_title',
        'marketing_description',
        'virtual_tour_url',
        'bayut_status',
        'bayut_listing_id',
        'bayut_url',
        'bayut_listed_at',
        'dubizzle_status',
        'dubizzle_listing_reference',
        'dubizzle_url',
        'dubizzle_listed_at',
        'propertyfinder_status',
        'propertyfinder_listing_reference',
        'propertyfinder_url',
        'propertyfinder_listed_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'repair_estimate' => 'decimal:2',
            'after_repair_value' => 'decimal:2',
            'asking_price' => 'decimal:2',
            'our_offer' => 'decimal:2',
            'maximum_allowable_offer' => 'decimal:2',
            'distress_markers' => 'array',
            'list_price' => 'decimal:2',
            'sold_price' => 'decimal:2',
            'rent_price' => 'decimal:2',
            'service_charge' => 'decimal:2',
            'listed_at' => 'date',
            'sold_at' => 'date',
            'handover_date' => 'date',
            'bayut_listed_at' => 'date',
            'dubizzle_listed_at' => 'date',
            'propertyfinder_listed_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::saving(function (Property $property) {
            if ($property->isDirty('address') && $property->address) {
                $property->address = AddressNormalizationService::normalize($property->address);
            }
            if ($property->isDirty('city') && $property->city) {
                $property->city = AddressNormalizationService::normalizeCity($property->city);
            }
            if ($property->isDirty('state') && $property->state) {
                $property->state = AddressNormalizationService::normalizeState($property->state);
            }
            if ($property->isDirty('zip_code') && $property->zip_code) {
                $property->zip_code = AddressNormalizationService::normalizeZipCode($property->zip_code);
            }
        });
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function leads()
    {
        return $this->belongsToMany(Lead::class, 'lead_property')
            ->withPivot('relation_type')
            ->withTimestamps();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function media()
    {
        return $this->hasMany(PropertyMedia::class)->orderBy('sort_order');
    }

    public function availabilitySource()
    {
        return $this->belongsTo(AvailabilitySource::class, 'availability_source_id');
    }

    public function comparableSales()
    {
        return $this->hasMany(ComparableSale::class)->latest('sale_date');
    }

    public function showings()
    {
        return $this->hasMany(Showing::class);
    }

    public function openHouses()
    {
        return $this->hasMany(OpenHouse::class);
    }

    public function getFullAddressAttribute(): string
    {
        return "{$this->address}, {$this->city}, {$this->state} {$this->zip_code}";
    }

    public function getAssignmentFeeAttribute(): ?float
    {
        if ($this->our_offer && $this->after_repair_value && $this->repair_estimate) {
            return $this->our_offer - ($this->after_repair_value * 0.70) - $this->repair_estimate;
        }
        return null;
    }

    public function getMaoAttribute(): ?float
    {
        if ($this->after_repair_value && $this->repair_estimate) {
            return ($this->after_repair_value * 0.70) - $this->repair_estimate;
        }
        return null;
    }

    // ── Brokerage helpers ──────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        if ($this->marketing_title) {
            return $this->marketing_title;
        }

        $parts = [];
        if ($this->bedrooms) {
            $parts[] = $this->bedrooms . ' ' . __('BR');
        }
        if ($this->property_category) {
            $parts[] = __(self::CATEGORIES[$this->property_category] ?? ucwords(str_replace('_', ' ', $this->property_category)));
        }
        if ($this->sub_community) {
            $parts[] = $this->sub_community;
        } elseif ($this->community) {
            $parts[] = $this->community;
        }

        if ($parts) {
            return implode(' ', $parts);
        }

        return $this->address ?: '#' . $this->id;
    }

    /**
     * The sale asking price; Null when the unit is not on the market for sale.
     */
    public function getSalePriceAttribute(): ?float
    {
        if (in_array($this->intent, ['sale', 'both'], true)) {
            return $this->list_price ? $this->list_price : $this->asking_price;
        }
        return null;
    }

    /**
     * The rent price; Null when the unit is not on the market for rent.
     */
    public function getRentAdvertisedPriceAttribute(): ?float
    {
        if (in_array($this->intent, ['rent', 'both'], true)) {
            return $this->rent_price;
        }
        return null;
    }

    /**
     * Natural-language price line, e.g. "AED 120,000 / yearly".
     */
    public function getPriceLineAttribute(): string
    {
        $bits = [];

        if ($this->rent_advertised_price) {
            $bits[] = \App\Helpers\TenantFormatHelper::currency($this->rent_advertised_price)
                . ' / ' . __(self::RENT_PERIODS[$this->rent_period] ?? ucfirst($this->rent_period));
        }

        if ($this->sale_price) {
            $bits[] = \App\Helpers\TenantFormatHelper::currency($this->sale_price);
        }

        return $bits ? implode(' • ', $bits) : '—';
    }

    /**
     * Whether this unit has everything portals need to publish it.
     */
    public function isPortalReady(): bool
    {
        return in_array($this->availability, ['ready_to_list', 'listed'], true)
            && $this->intent !== null
            && $this->rera_permit_no
            && $this->property_category;
    }

    public function getPortalReadyAttribute(): bool
    {
        return $this->isPortalReady();
    }

    /**
     * Absolute URLs of the unit's photos (uploaded or CDN), for portal feeds.
     */
    public function getPhotoUrlsAttribute(): array
    {
        return $this->media
            ->where('type', 'photo')
            ->map(fn ($m) => $m->url())
            ->filter()
            ->values()
            ->all();
    }
}
