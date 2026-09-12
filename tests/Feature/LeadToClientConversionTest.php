<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Deal;
use Tests\TestCase;

class LeadToClientConversionTest extends TestCase
{
    public function test_won_deal_converts_lead_to_client_with_activity(): void
    {
        $this->actingAsAdmin();
        $agent = $this->createUserWithRole('agent');
        $lead = \App\Models\Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agent->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+971501234567',
        ]);
        $deal = Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $agent->id,
            'stage' => 'closing',
            'title' => 'Jane Doe Mandate',
        ]);

        $this->patch(route('deals.updateStage', $deal), ['stage' => 'closed_won']);

        $client = Buyer::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($client);
        $this->assertSame('Jane', $client->first_name);
        $this->assertSame('Doe', $client->last_name);
        $this->assertSame('jane.doe@example.com', $client->email);
        $this->assertSame('+971501234567', $client->phone);
        $this->assertSame(1, $client->total_deals_closed);
        $this->assertNotNull($client->last_purchase_at);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'conversion',
        ]);
    }

    public function test_existing_client_is_updated_not_duplicated(): void
    {
        $this->actingAsAdmin();
        $agent = $this->createUserWithRole('agent');
        $existing = Buyer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'total_deals_closed' => 0,
        ]);
        $lead = \App\Models\Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agent->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'Jane.Doe@example.com',
            'phone' => '+971509999999',
        ]);
        $deal = Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $agent->id,
            'stage' => 'closing',
            'title' => 'Jane Doe Mandate',
        ]);

        $this->patch(route('deals.updateStage', $deal), ['stage' => 'closed_won']);

        $this->assertSame(1, Buyer::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $existing->fresh()->total_deals_closed);
    }

    public function test_reverting_away_and_back_does_not_duplicate_clients(): void
    {
        $this->actingAsAdmin();
        $agent = $this->createUserWithRole('agent');
        $lead = \App\Models\Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agent->id,
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'email' => 'bob.smith@example.com',
        ]);
        $deal = Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $agent->id,
            'stage' => 'closing',
            'title' => 'Bob Deal',
        ]);

        $this->patch(route('deals.updateStage', $deal), ['stage' => 'closed_won']);
        $this->patch(route('deals.updateStage', $deal), ['stage' => 'closing']);
        $this->patch(route('deals.updateStage', $deal), ['stage' => 'closed_won']);

        $this->assertSame(1, Buyer::where('tenant_id', $this->tenant->id)->count());
    }
}
