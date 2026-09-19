<?php

namespace Tests\Feature;

use App\Models\LeadAgent;
use App\Models\LeadCommission;
use App\Services\CommissionCalculationService;
use Tests\TestCase;

class CommissionCalculationServiceTest extends TestCase
{
    private function reAdmin(): self
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function leadWithGross(float $gross): \App\Models\Lead
    {
        return $this->createLead(['commission_amount' => $gross, 'deal_type' => 'rent']);
    }

    private function coAgent(\App\Models\Lead $lead, float $pct, string $funding): LeadAgent
    {
        return LeadAgent::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => null,
            'external_name' => 'Karim Broker',
            'external_email' => 'karim@broker.ae',
            'commission_pct' => $pct,
            'share_funding' => $funding,
            'status' => LeadAgent::STATUS_ACTIVE,
        ]);
    }

    public function test_default_fixed_split_is_50_50(): void
    {
        $this->reAdmin();
        $lead = $this->leadWithGross(10000);

        $result = app(CommissionCalculationService::class)->calculate($lead, persist: false);

        $this->assertEquals(10000.0, $result['gross']);
        $company = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_COMPANY);
        $main = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_INTERNAL);
        $this->assertEquals(5000.0, $company['amount']);
        $this->assertEquals(5000.0, $main['amount']);
    }

    public function test_co_agent_funded_from_company(): void
    {
        $this->reAdmin();
        $lead = $this->leadWithGross(10000);
        $this->coAgent($lead, 10, LeadAgent::FUNDING_FROM_COMPANY);

        $result = app(CommissionCalculationService::class)->calculate($lead, persist: false);

        $company = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_COMPANY);
        $support = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_EXTERNAL);
        $this->assertEquals(4000.0, $company['amount'], 'Company pays the co-agent share.');
        $this->assertEquals(1000.0, $support['amount']);
    }

    public function test_co_agent_funded_from_main_agent(): void
    {
        $this->reAdmin();
        $lead = $this->leadWithGross(10000);
        $this->coAgent($lead, 10, LeadAgent::FUNDING_FROM_AGENT);

        $result = app(CommissionCalculationService::class)->calculate($lead, persist: false);

        $company = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_COMPANY);
        $main = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_INTERNAL);
        $this->assertEquals(5000.0, $company['amount']);
        $this->assertEquals(4000.0, $main['amount'], 'Main agent pays the co-agent share.');
    }

    public function test_co_agent_share_split_half_half(): void
    {
        $this->reAdmin();
        $lead = $this->leadWithGross(10000);
        $this->coAgent($lead, 10, LeadAgent::FUNDING_FROM_BOTH);

        $result = app(CommissionCalculationService::class)->calculate($lead, persist: false);

        $company = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_COMPANY);
        $main = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_INTERNAL);
        $this->assertEquals(4500.0, $company['amount']);
        $this->assertEquals(4500.0, $main['amount']);
        $this->assertSame(0, (int) array_sum(array_column($result['rows'], 'amount')) - 10000);
    }

    public function test_fixed_amount_plan_caps_agent_at_gross(): void
    {
        $this->reAdmin();
        $planId = \App\Models\AgentCompensation::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->adminUser->id,
            'split_type' => 'fixed',
            'pay_structure' => \App\Models\AgentCompensation::PAY_FIXED_AMOUNT,
            'fixed_amount_per_close' => 3000,
        ])->id;

        $lead = $this->leadWithGross(10000);

        $result = app(CommissionCalculationService::class)->calculate($lead, persist: false);

        $company = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_COMPANY);
        $main = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_INTERNAL);
        $this->assertEquals(3000.0, $main['amount']);
        $this->assertEquals(7000.0, $company['amount']);
        $this->assertDatabaseHas('agent_compensation', ['id' => $planId]);
    }

    public function test_tiered_split_uses_lookup_table(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate', 'custom_options' => [
            'commission_calculation' => [
                'default_split_type' => 'tiered',
                'tiers' => [
                    ['from' => null, 'max' => 100000, 'agent_pct' => '50'],
                    ['from' => 100000.01, 'max' => 200000, 'agent_pct' => '55'],
                    ['from' => 200000.01, 'max' => null, 'agent_pct' => '60'],
                ],
            ],
        ]]);
        $lead = $this->leadWithGross(150000);

        $result = app(CommissionCalculationService::class)->calculate($lead, persist: false);

        $main = collect($result['rows'])->firstWhere('participant_type', LeadCommission::TYPE_INTERNAL);
        $this->assertEquals(82500.0, $main['amount'], '55% of 150k.');
        $this->assertEquals('tiered', $main['basis']);
    }

    public function test_snapshot_persists_and_protects_paid_rows(): void
    {
        $this->reAdmin();
        $lead = $this->leadWithGross(10000);

        $service = app(CommissionCalculationService::class);
        $service->calculate($lead);

        $this->assertDatabaseHas('lead_commissions', [
            'lead_id' => $lead->id,
            'status' => LeadCommission::STATUS_EARNED,
        ]);

        // Mark the main agent's row as paid — recalculating must not touch it.
        $mainRow = $lead->commissions()->where('status', LeadCommission::STATUS_EARNED)->whereNotNull('agent_id')->first();
        $mainRow->update(['status' => LeadCommission::STATUS_PAID]);

        $service->calculate($lead);

        $this->assertEquals(LeadCommission::STATUS_PAID, $mainRow->fresh()->status, 'Paid rows must survive a recalculation.');
        $this->assertEquals(2, $lead->commissions()->where('status', LeadCommission::STATUS_EARNED)->count(), 'Earned rows are replaced on recalculation.');
        $this->assertTrue($lead->hasCommissionSnapshot());
    }

    public function test_zero_gross_throws(): void
    {
        $this->reAdmin();
        $lead = $this->leadWithGross(0);

        $this->expectException(\RuntimeException::class);
        app(CommissionCalculationService::class)->calculate($lead, persist: false);
    }
}