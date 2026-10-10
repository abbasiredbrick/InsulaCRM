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
        'ready' => 'Ready / Secondary',
        'off_plan' => 'Off-Plan / Primary',
    ];

    public const AVAILABILITIES = [
        'draft' => 'Draft',
        'ready_to_list' => 'Ready to List',
        'upcoming' => 'Upcoming',
        'listed' => 'Listed',
        'reserved' => 'Reserved',
        'leased' => 'Leased',
        'sold' => 'Sold',
        'unlisted' => 'Unlisted',
    ];

    public const FURNISHING = [
        'unfurnished' => 'Unfurnished',
        'semi_furnished' => 'Semi-Furnished',
        'furnished' => 'Furnished',
    ];

    public const RENT_PERIODS = [
        'yearly' => 'Yearly',
        'monthly' => 'Monthly',
    ];

    /**
     * Bayut-standard categories used by the UAE portals.
     */
    public const CATEGORIES = [
        'apartment' => 'Apartment',
        'penthouse' => 'Penthouse',
        'villa' => 'Villa',
        'villa_compound' => 'Villa Compound',
        'townhouse' => 'Townhouse',
        'residential_building' => 'Residential Building',
        'hotel_apartment' => 'Hotel Apartment',
        'office' => 'Office',
        'shop' => 'Shop',
        'showroom' => 'Showroom',
        'warehouse' => 'Warehouse',
        'factory' => 'Factory',
        'commercial_building' => 'Commercial Building',
        'land' => 'Land / Plot',
        'other' => 'Other',
    ];

    public const PORTAL_STATUSES = [
        'not_listed' => 'Not Listed',
        'live' => 'Live',
        'removed' => 'Removed',
    ];

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'map_location_id',
        'address',
        'city',
        'state',
        'zip_code',
        'property_type',
        'bedrooms',
        'maids_room',
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
        'available_from',
        'title_deed_no',
        'rera_permit_no',
        'plot_no',
        'building_no',
        'unit_no',
        'floor_no',
        'rent_price',
        'deposit_amount',
        'admin_fee',
        'contract_fee',
        'rent_period',
        'service_charge',
        'furnishing',
        'parking',
        'balcony',
        'view',
        'availability',
        'assigned_agent_id',
        'assign_leads_to_owner',
        'owner_id',
        'owner_name',
        'owner_phone',
        'owner_email',
        'marketing_title',
        'marketing_description',
        'virtual_tour_url',
        'bayut_status',
        'bayut_location_id',
        'bayut_location_label',
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
            'deposit_amount' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'contract_fee' => 'decimal:2',
            'listed_at' => 'date',
            'sold_at' => 'date',
            'handover_date' => 'date',
            'available_from' => 'date',
            'bayut_listed_at' => 'datetime',
            'dubizzle_listed_at' => 'datetime',
            'propertyfinder_listed_at' => 'datetime',
            'availability_synced_at' => 'datetime',
            'assign_leads_to_owner' => 'boolean',
            'maids_room' => 'boolean',
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

    public function mapLocation()
    {
        return $this->belongsTo(MapLocation::class, 'map_location_id');
    }

    /**
     * The unit's owner/landlord (the owners master). owner_name/phone/email on
     * this row stay snapshots so existing search and offer letters keep working.
     */
    public function owner()
    {
        return $this->belongsTo(Owner::class, 'owner_id');
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

    /**
     * The DB columns the picker label accessors need. `sale_price` is a
     * computed accessor (from list_price / asking_price), so it must NOT be
     * listed here — selecting it directly breaks on MySQL.
     */
    public static function optionLabelColumns(): array
    {
        return [
            'id',
            'marketing_title',
            'property_category',
            'community',
            'sub_community',
            'bedrooms',
            'maids_room',
            'bathrooms',
            'unit_no',
            'intent',
            'rent_price',
            'rent_period',
            'list_price',
            'asking_price',
        ];
    }

    /**
     * Concise picker label used by searchable property dropdowns.
     */
    public function optionLabel(): string
    {
        // unitLabel() already carries the size, the intent and the location, so
        // only the baths half and the price are worth adding here — repeating
        // them would print "2BR for Rent in Marina Gate, Dubai — Dubai — 2BR".
        $bits = [$this->unitLabel()];

        if ($this->bathrooms) {
            $bits[] = $this->bathrooms.' '.__('BA');
        }

        $bits[] = $this->price_line;

        return trim(implode(' — ', array_filter($bits)));
    }

    /**
     * "Studio" for a zero-bedroom unit, "2BR" otherwise, "" when unrecorded.
     *
     * Studio sits on the *size* axis (studio / 1BR / 2BR / 3BR), not the
     * category axis - a studio is still an apartment. It is stored as
     * bedrooms = 0, which is FALSY in PHP, so every plain
     * `$property->bedrooms ? … : null` silently dropped studios from labels and
     * the inventory showed "0 bd". Render bedrooms through this instead.
     */
    public function bedroomLabel(): string
    {
        if ($this->isStudio()) {
            return __('Studio');
        }

        if ($this->bedrooms === null) {
            return '';
        }

        // No space: "2BR". This is the canonical size format — it matches the
        // marketing titles written at import time
        // (AvailabilityIngestService::buildMarketingTitle) and the client share
        // links, so every surface prints the same thing. Spacing here used to
        // disagree with the stored titles, which made the inventory list show
        // "3BR" and "3 BR" side by side.
        return $this->bedrooms.__('BR');
    }

    /**
     * The size half of a unit label, carrying the maid's-room suffix so every
     * surface prints it in the same place: "2BR + Maid". A maid's room is a
     * premium feature — it goes on the label (client share, inventory, pickers)
     * and on the marketing title at import time.
     *
     * Unrecorded sizes stay empty here; the category word is the caller's
     * fallback (see unitLabel()).
     */
    public function sizeLabel(): string
    {
        $size = $this->bedroomLabel();

        if ($this->maids_room && $size !== '') {
            $size .= ' + '.__('Maid');
        }

        return $size;
    }

    public function isStudio(): bool
    {
        if ($this->bedrooms !== null && (int) $this->bedrooms === 0) {
            return true;
        }

        // Tolerated for rows written while Studio briefly existed as a
        // category, so those still read as Studio rather than "1 BR Apartment".
        return $this->property_category === 'studio';
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->unitLabel();
    }

    /**
     * The unit label used everywhere in the UI and on printed documents:
     *
     *   "3BR for Rent in Reem Hills, Yas Island"
     *   "Studio for Rent in Bloom Towers B, Bloom Towers"
     *   "2BR for Sale in Yas Island"           (no sub-community on file)
     *   "Shop for Rent in Marafid, Al Reem Island"   (no size recorded —
     *        the category word stands in, so a shop/office/showroom never
     *        renders as a bare "for Rent in ...")
     *   "2BR + Maid for Rent in Marafid, Al Reem Island"   (maid's room kept
     *        on the label — it is what makes the unit worth sharing)
     *
     * Deliberately NOT marketing_title: that is the agent's portal copy and it
     * reads as a headline, not as a list label. It is still published to the
     * portals and the XML feed through listingTitle().
     */
    public function unitLabel(): string
    {
        $place = $this->sub_community ?: $this->community;

        if ($place === '') {
            // Nothing to locate it by, so the best remaining label wins.
            return $this->marketing_title ?: $this->address ?: '#'.$this->id;
        }

        $where = $this->sub_community && $this->community && $this->community !== $this->sub_community
            ? "{$this->sub_community}, {$this->community}"
            : $place;

        $size = $this->sizeLabel();
        if ($size === '') {
            // No size on file — say what kind of unit it is instead. A shop
            // ("Shop for Rent in Marafid") otherwise rendered as a bare
            // "for Rent in Marafid", which is why commercial rows looked
            // wrong on the share page. "null must never read as a studio"
            // still holds: the category is not a guessed size.
            $size = self::CATEGORIES[$this->property_category] ?? ucwords(str_replace('_', ' ', (string) $this->property_category));
        }
        $intent = match ($this->intent) {
            'sale' => __('Sale'),
            'both' => __('Rent / Sale'),
            default => __('Rent'),
        };

        return trim(($size !== '' ? $size.' ' : '')."for {$intent} in {$where}");
    }

    /**
     * The title to PUBLISH: the agent's own marketing copy when there is one,
     * otherwise the generated unit label. The portals, the XML feed and the
     * CSV export go through here so this CRM relabel never rewrites live
     * listing copy.
     */
    public function listingTitle(): string
    {
        return $this->marketing_title ?: $this->unitLabel();
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
                .' / '.__(self::RENT_PERIODS[$this->rent_period] ?? ucfirst($this->rent_period));
        }

        if ($this->sale_price) {
            $bits[] = \App\Helpers\TenantFormatHelper::currency($this->sale_price);
        }

        return $bits ? implode(' • ', $bits) : '—';
    }

    /**
     * Human label for the availability badge. "Upcoming" units append the date
     * they become available (e.g. "Upcoming · 14 Sep 2026").
     */
    public function getAvailabilityLabelAttribute(): string
    {
        $label = self::AVAILABILITIES[$this->availability] ?? (string) $this->availability;

        if ($this->availability === 'upcoming' && $this->available_from) {
            return $label.' · '.$this->available_from->format('d M Y');
        }

        return $label;
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

    public function getIsPortalReadyAttribute(): bool
    {
        return $this->isPortalReady();
    }

    /**
     * The permit regime for this unit's emirate. Abu Dhabi uses the Madhmoun
     * permit; Dubai uses the RERA permit. Falls back to the unit's own city.
     */
    public function locationPermit(): array
    {
        $emirate = (string) ($this->bayut_location_label ?: ($this->city ?: $this->state));

        if (str_contains($emirate, 'Abu Dhabi')) {
            return ['key' => 'abudhabi', 'label' => __('Madhmoun Permit No')];
        }

        if (str_contains($emirate, 'Dubai')) {
            return ['key' => 'dubai', 'label' => __('RERA Permit No')];
        }

        return ['key' => 'generic', 'label' => __('Permit No')];
    }

    public function getPermitLabelAttribute(): string
    {
        return $this->locationPermit()['label'];
    }

    public function getPermitRegimeAttribute(): string
    {
        return $this->locationPermit()['key'];
    }

    /**
     * Absolute URLs of the unit's photos (uploaded or CDN), for portal feeds.
     */
    public function getPhotoUrlsAttribute(): array
    {
        return $this->media
            ->where('type', 'photo')
            ->sortBy(fn ($m) => sprintf('%d-%04d', $m->is_primary ? 0 : 1, $m->sort_order))
            ->map(fn ($m) => $m->url())
            ->filter()
            ->values()
            ->all();
    }
}
