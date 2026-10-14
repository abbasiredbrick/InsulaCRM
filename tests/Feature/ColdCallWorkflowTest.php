<?php

namespace Tests\Feature;

use App\Models\MarketContact;
use App\Services\ColdCallAssignmentService;
use App\Services\ColdCallSentimentService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ColdCallWorkflowTest extends TestCase
{
    private function actingAsRealEstateAdmin(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_import_classifies_comment_into_expected_inventory(): void
    {
        $this->actingAsRealEstateAdmin();

        $csv = 'Name,Phone,Requirements
Willing Owner,+971501111111,Owner willing to rent out the unit
Busy Landlord,+971502222222,call back next week
Not Now,+971503333333,not interested
Plain,+971504444444,
';

        $file = UploadedFile::fake()->createWithContent('market.csv', $csv);
        $this->post(route('market.import'), ['name' => 'Drive list', 'type' => 'landlords', 'file' => $file])
            ->assertRedirect(route('market.index'));

        $willing = MarketContact::where('phone', '+971501111111')->first();
        $this->assertSame('willing_rent', $willing->disposition);
        $this->assertTrue($willing->expected_inventory);
        $this->assertSame(3, $willing->priority);

        $busy = MarketContact::where('phone', '+971502222222')->first();
        $this->assertSame('call_back', $busy->disposition);
        $this->assertTrue($busy->expected_inventory);

        $notNow = MarketContact::where('phone', '+971503333333')->first();
        $this->assertSame('not_interested', $notNow->disposition);
        $this->assertFalse($notNow->expected_inventory);

        $plain = MarketContact::where('phone', '+971504444444')->first();
        $this->assertSame('unclear', $plain->disposition);
        $this->assertFalse($plain->expected_inventory);
    }

    public function test_expected_inventory_filter_returns_only_recall_contacts(): void
    {
        $this->actingAsRealEstateAdmin();

        MarketContact::factory()->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'expected_inventory' => true,
            'disposition' => 'willing_rent',
        ]);
        MarketContact::factory()->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'expected_inventory' => false,
        ]);

        $response = $this->get(route('market.index', ['expected' => 1]));
        $response->assertStatus(200);
        $response->assertSee('Expected Inventory');
    }

    public function test_round_robin_assigns_pending_contacts_across_cold_call_agents(): void
    {
        $this->actingAsRealEstateAdmin();

        $agentA = $this->actingAsRole('cold_call_agent', ['business_mode' => 'realestate']);
        $agentA->update(['name' => 'AAA Agent']);
        $agentB = $this->createUserWithRole('cold_call_agent', ['name' => 'BBB Agent']);

        MarketContact::factory()->count(5)->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'pending',
            'called_by' => null,
        ]);

        $service = app(ColdCallAssignmentService::class);
        $result = $service->assignBatch($this->tenant, 3);

        $this->assertSame(5, $result['assigned']);
        $this->assertSame(2, $result['agents']);

        $assignedA = MarketContact::where('called_by', $agentA->id)->count();
        $assignedB = MarketContact::where('called_by', $agentB->id)->count();
        $this->assertSame(3, $assignedA);
        $this->assertSame(2, $assignedB);

        $this->assertSame(0, MarketContact::whereNull('called_by')->count());
    }

    public function test_round_robin_skips_non_cold_call_roles(): void
    {
        $this->actingAsRealEstateAdmin();

        $this->createUserWithRole('agent');

        MarketContact::factory()->count(2)->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'pending',
            'called_by' => null,
        ]);

        $result = app(ColdCallAssignmentService::class)->assignBatch($this->tenant, 5);

        $this->assertSame(0, $result['agents']);
        $this->assertSame(0, $result['assigned']);
        $this->assertSame(2, MarketContact::whereNull('called_by')->count());
    }

    public function test_sentiment_service_classifies_sell_and_invest(): void
    {
        $service = app(ColdCallSentimentService::class);

        $sell = new MarketContact(['type' => 'landlord', 'requirements' => 'Ready to sell the villa']);
        $this->assertSame('willing_sell', $service->classify($sell)['disposition']);

        $invest = new MarketContact(['type' => 'investor', 'requirements' => 'Wants to buy an offplan']);
        $this->assertSame('willing_invest', $service->classify($invest)['disposition']);
    }

    public function test_drive_page_redirects_when_drive_not_connected(): void
    {
        $this->actingAsRealEstateAdmin();

        $this->get(route('market.drive'))
            ->assertRedirect(route('market.create'));
    }
}
