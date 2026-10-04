<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Property;
use App\Services\DealCommissionService;
use Tests\TestCase;

class DealCommissionServiceTest extends TestCase
{
    private function reAdmin(): TestCase
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function rentDeal(\App\Models\Lead $lead, float $annualRental, ?array $propertyOverrides = null): \App\Models\Deal
    {
        if ($propertyOverrides !== null) {
            $this->createProperty(array_merge(['lead_id' => $lead->id], $propertyOverrides));
        }

        return \App\Models\Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'deal_type' => 'rent',
            'stage' => 'moved_in',
            'contract_price' => $annualRental,
        ]);
    }

    public function test_sale_commission_is_2_percent_of_contract_price(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 1000000]);

        $service = app(DealCommissionService::class);

        $this->assertEquals(2.0, $service->rateFor($deal));
        $this->assertEquals(1000000.0, $service->grossFor($deal));
        $this->assertEquals(20000.0, $service->commissionFor($deal));
    }

    public function test_residential_rent_commission_is_5_percent_of_annual_value(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent']);
        $deal = $this->rentDeal($lead, 120000.0);

        $service = app(DealCommissionService::class);

        $this->assertEquals(5.0, $service->rateFor($deal));
        $this->assertEquals(120000.0, $service->grossFor($deal));
        $this->assertEquals(6000.0, $service->commissionFor($deal));
    }

    public function test_commercial_rent_commission_is_10_percent(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent']);
        $deal = $this->rentDeal($lead, 100000.0, ['property_category' => 'office']);

        $service = app(DealCommissionService::class);

        $this->assertEquals(10.0, $service->rateFor($deal));
        $this->assertEquals(10000.0, $service->commissionFor($deal));
    }

    public function test_monthly_rent_values_are_annualised(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent']);
        Property::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'property_type' => 'single_family',
            'rent_price' => 10000,
            'rent_period' => 'monthly',
        ]);
        $deal = $this->createDeal(['lead_id' => $lead->id, 'deal_type' => 'rent', 'stage' => 'moved_in', 'contract_price' => null]);

        $service = app(DealCommissionService::class);

        $this->assertEquals(120000.0, $service->grossFor($deal));
        $this->assertEquals(6000.0, $service->commissionFor($deal));
    }

    public function test_custom_rates_override_defaults(): void
    {
        // VAT is only charged when the company is registered for it, so a
        // configured rate alone is not enough to switch it on.
        // The stored vat of 10 is stale on purpose: the custom *commission*
        // rates must still be honoured, while the applied VAT rate is the fixed
        // 5% no matter what the tenant has sitting in settings.
        $this->actingAsAdmin(['business_mode' => 'realestate', 'is_vat_registered' => true, 'custom_options' => [
            'commission_rates' => ['sales' => '3', 'residential_lease' => '7', 'vat' => '10'],
        ]]);

        $sale = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 1000000]);
        $lead = $this->createLead(['deal_type' => 'rent']);
        $rent = $this->rentDeal($lead, 100000.0);

        $service = app(DealCommissionService::class);

        $this->assertEquals(3.0, $service->rateFor($sale));
        $this->assertEquals(30000.0, $service->commissionFor($sale));
        $this->assertEquals(7.0, $service->rateFor($rent));
        $this->assertEquals(7000.0, $service->commissionFor($rent));
        $this->assertEquals(1500.0, $service->vatFor($sale, 30000.0), 'Fixed 5% UAE VAT on the sale commission.');
    }

    public function test_no_vat_is_charged_when_the_company_is_not_registered(): void
    {
        // The configured rate is still sitting in the tenant settings; it must be
        // ignored, because an unregistered brokerage adding VAT to a client
        // invoice is simply overcharging the client.
        $this->actingAsAdmin(['business_mode' => 'realestate', 'is_vat_registered' => false, 'custom_options' => [
            'commission_rates' => ['sales' => '3', 'vat' => '10'],
        ]]);

        $sale = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 1000000]);
        $service = app(DealCommissionService::class);

        $this->assertEquals(30000.0, $service->commissionFor($sale));
        $this->assertEquals(0.0, $service->vatFor($sale, 30000.0));
        $this->assertEquals(0.0, $this->tenant->fresh()->effectiveVatRate());
    }

    public function test_approved_discount_amount_is_the_commission_basis(): void
    {
        $this->reAdmin();

        $deal = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 1000000]);

        $service = app(DealCommissionService::class);

        $this->assertEquals(16000.0, $service->commissionFor($deal, 800000.0), '2% on the discounted approved amount.');
    }

    public function test_lead_without_deal_uses_linked_property_value(): void
    {
        $this->reAdmin();

        $lead = Lead::factory()->create(['tenant_id' => $this->tenant->id, 'agent_id' => $this->adminUser->id, 'deal_type' => 'rent']);
        Property::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'property_type' => 'single_family',
            'rent_price' => 50000,
            'rent_period' => 'monthly',
        ]);

        $this->assertEquals(600000.0, app(DealCommissionService::class)->grossFor($lead), 'Monthly rent is annualised.');
        $this->assertEquals(30000.0, app(DealCommissionService::class)->commissionFor($lead), '5% on 600k annual value.');
    }

    public function test_the_applied_vat_rate_is_the_fixed_uae_five_percent(): void
    {
        // Even a tenant whose stored settings carry a stale rate gets 5%, and an
        // unregistered tenant gets nothing at all. The rate is never a choice.
        $this->actingAsAdmin(['business_mode' => 'realestate', 'is_vat_registered' => true, 'custom_options' => [
            'commission_rates' => ['sales' => '3', 'vat' => '17'],
        ]]);

        $tenant = $this->tenant->fresh();
        $this->assertSame(5.0, $tenant->effectiveVatRate());
        $this->assertSame(5.0, \App\Models\Tenant::VAT_RATE);
        $this->assertEquals(500.0, $tenant->vatOn(10000.0));

        // Zero amounts are not VATable, and no negative line can be created.
        $this->assertEquals(0.0, $tenant->vatOn(0.0));
        $this->assertEquals(0.0, $tenant->vatOn(-100.0));

        $sale = $this->createDeal(['deal_type' => 'sale', 'stage' => 'active_listing', 'contract_price' => 1000000]);
        $service = app(DealCommissionService::class);
        $this->assertEquals(500.0, $service->vatFor($sale, 10000.0));
    }

    public function test_settings_reject_a_submitted_vat_rate(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate', 'is_vat_registered' => true]);

        // A crafted POST cannot move the rate off the legal 5%.
        $this->post(route('settings.updateCommissionRates'), [
            'residential_lease' => 5,
            'commercial_lease' => 5,
            'sales' => 2,
            'vat' => 20,
            'is_vat_registered' => 1,
        ])->assertRedirect();

        $tenant = $this->tenant->fresh();
        $this->assertSame(5.0, $tenant->effectiveVatRate());
        $this->assertSame('5', (string) $tenant->commissionRateSettings()['vat']);
    }
}
