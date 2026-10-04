<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Services\DashboardMetricsService;
use Tests\TestCase;

class ClosedMetricsTest extends TestCase
{
    private function reAdmin(): TestCase
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function closedLead(string $dealType, \DateTimeInterface $closedAt): Lead
    {
        $lead = $this->createLead(['deal_type' => $dealType, 'status' => 'closed_won']);
        $lead->update(['status_changed_at' => $closedAt]);

        return $lead;
    }

    public function test_closed_leases_count_respects_close_date(): void
    {
        $this->reAdmin();

        $this->closedLead('rent', now());
        $this->closedLead('rent', now()->subMonth()); // closed last month
        $this->closedLead('sale', now());             // not a lease

        $this->assertEquals(1, DashboardMetricsService::closedLeasesCount());
        $this->assertEquals(1, DashboardMetricsService::closedLeasesCount(null, now()->toDateString()));
    }

    public function test_closed_leases_fees_drive_from_deal_commission(): void
    {
        $this->reAdmin();

        $lead = $this->closedLead('rent', now());
        Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'deal_type' => 'rent',
            'stage' => 'moved_in',
            'contract_price' => 120000,
            'total_commission' => 6000,
        ]);

        $this->assertEquals(6000.0, DashboardMetricsService::closedLeasesFees());
    }

    public function test_teams_counts_deals_closed_this_month_by_close_date(): void
    {
        $this->reAdmin();

        // One sale deal closed this month, one closed last month.
        Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'deal_type' => 'sale',
            'stage' => 'closed_won',
            'stage_changed_at' => now(),
        ]);
        Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'deal_type' => 'sale',
            'stage' => 'closed_won',
            'stage_changed_at' => now()->subMonths(2),
        ]);

        $response = $this->get(route('team.index'));

        $response->assertOk();
        $this->assertEquals(1, $response->viewData('totals')->closed_deals, 'Only deals closed this month.');
    }

    public function test_teams_counts_won_leads_this_month_by_close_date(): void
    {
        $this->reAdmin();

        $this->closedLead('rent', now());
        $this->closedLead('rent', now()->subMonths(2));

        $response = $this->get(route('team.index'));

        $response->assertOk();
        $this->assertEquals(1, $response->viewData('totals')->closed_leads, 'Only leads won this month.');
    }

    public function test_agent_rows_aggregate_commissions_on_teams(): void
    {
        $this->reAdmin();

        $this->closedLead('rent', now());
        $this->get(route('team.index'))->assertOk();

        $member = collect($this->get(route('team.index'))->viewData('members'))
            ->firstWhere('id', $this->adminUser->id);

        $this->assertEquals(1, $member->closed_leads_this_month);
    }
}
