<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;

/**
 * Standard brokerage commission engine.
 *
 * The agency commission is a percentage of the full contract value:
 *  - 5%  of the annual lease value for residential units,
 *  - 10% of the annual lease value for commercial units,
 *  - 2%  of the sales value for sales transactions.
 *
 * Rates are tenant-configurable and default to the values above. A manager may
 * approve a discount on the original value; the commission is then calculated
 * on the discounted (approved) amount.
 */
class DealCommissionService
{
    public const RATE_DEFAULTS = [
        'residential_lease' => 5,
        'commercial_lease' => 10,
        'sales' => 2,
        'vat' => 5,
    ];

    /**
     * Property categories treated as commercial.
     */
    public const COMMERCIAL_CATEGORIES = [
        'office', 'shop', 'showroom', 'warehouse', 'factory', 'commercial_building',
    ];

    /**
     * Effective commission rates for a tenant ('residential_lease',
     * 'commercial_lease', 'sales', 'vat').
     */
    public function rates(?Tenant $tenant = null): array
    {
        $saved = $tenant?->custom_options['commission_rates'] ?? [];

        return array_merge(self::RATE_DEFAULTS, $saved);
    }

    /**
     * Whether a unit is commercial real estate.
     */
    public function isCommercial(?Property $property = null): bool
    {
        if (! $property) {
            return false;
        }

        if ($property->property_type === 'commercial') {
            return true;
        }

        return $property->property_category !== null
            && in_array($property->property_category, self::COMMERCIAL_CATEGORIES, true);
    }

    /**
     * The unit linked to a lead deal entity (prefers the dedicated one-to-one
     * property record, then any linked inventory unit).
     */
    public function propertyFor(Lead|Deal $entity): ?Property
    {
        if ($entity instanceof Deal) {
            // dealUnit() is the unit the offer was actually written on
            // (deals.property_id), falling back to the lead's own unit. The two
            // have to agree: reading property() directly here would price the
            // letter off the lead's unit while the fees came off the unit the
            // agent chose, which is how a letter ends up quoting one unit's
            // price against another unit's deposit.
            return $entity->dealUnit();
        }

        if ($entity->property) {
            return $entity->property;
        }

        return $entity->properties()->first();
    }

    /**
     * The commission rate (%) for a lead or deal, based on deal type and unit.
     */
    public function rateFor(Lead|Deal $entity): float
    {
        $tenant = $entity->tenant;
        $rates = $this->rates($tenant);

        if ($entity->dealType() === 'sale') {
            return (float) $rates['sales'];
        }

        return (float) ($this->isCommercial($this->propertyFor($entity))
            ? $rates['commercial_lease']
            : $rates['residential_lease']);
    }

    /**
     * The price the unit is *advertised* at, before any negotiation.
     *
     * This is deliberately not grossFor(): the offer letter prints "Unit Price (as
     * listed)", then the discount, then the contract value, and for that to be
     * honest the first figure has to be the listing. A deal's contract_price is
     * already the negotiated number, so using it as the listed price would make
     * the discount line always read zero and hide the real gap between asking
     * price and agreed price. Falls back to grossFor() when the unit carries no
     * listed price of its own.
     */
    public function listedPriceFor(Lead|Deal $entity): float
    {
        $property = $this->propertyFor($entity);

        if ($entity->dealType() === 'sale') {
            if ($property && (float) $property->sale_price > 0) {
                return round((float) $property->sale_price, 2);
            }
        } elseif ($property && (float) $property->rent_price > 0) {
            return round($this->annualise((float) $property->rent_price, $property->rent_period), 2);
        }

        return $this->grossFor($entity);
    }

    /**
     * Full (pre-discount) value of the transaction:
     *  - annual lease value for rent (monthly figures are annualised),
     *  - the sales value for sales.
     */
    public function grossFor(Lead|Deal $entity): float
    {
        $property = $this->propertyFor($entity);

        if ($entity->dealType() === 'sale') {
            if ($entity instanceof Deal && (float) $entity->contract_price > 0) {
                return round((float) $entity->contract_price, 2);
            }

            return round((float) ($property?->sale_price ?? 0), 2);
        }

        if ($entity instanceof Deal && (float) $entity->contract_price > 0) {
            return round($this->annualise((float) $entity->contract_price), 2);
        }

        if ($property && (float) $property->rent_price > 0) {
            return round($this->annualise((float) $property->rent_price, $property->rent_period), 2);
        }

        if ($entity instanceof Lead) {
            return round($this->annualise((float) ($entity->custom_fields['final_rent'] ?? 0), $entity->custom_fields['rent_period'] ?? null), 2);
        }

        return 0.0;
    }

    /**
     * Commission on the (possibly discounted) value. When no approved amount is
     * supplied the full gross value is used.
     */
    public function commissionFor(Lead|Deal $entity, ?float $approvedAmount = null): float
    {
        $gross = (float) ($approvedAmount ?? $this->grossFor($entity));

        return round($gross * ($this->rateFor($entity) / 100), 2);
    }

    /**
     * VAT on top of a commission.
     *
     * Only ever called on the commission, never on the lease or sale value —
     * residential rent and residential sale consideration are not VATable in the
     * UAE. Zero unless the tenant is VAT registered.
     */
    public function vatFor(Lead|Deal $entity, float $commission, ?Tenant $tenant = null): float
    {
        return ($tenant ?? $entity->tenant)->vatOn($commission);
    }

    /**
     * Monthly rent figures are multiplied by 12; yearly amounts pass through.
     */
    protected function annualise(float $amount, ?string $period = null): float
    {
        if ($period === 'monthly') {
            return round($amount * 12, 2);
        }

        return round($amount, 2);
    }
}
