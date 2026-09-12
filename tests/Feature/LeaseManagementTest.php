<?php

namespace Tests\Feature;

use App\Models\Buyer;
use App\Models\Lead;
use App\Models\Lease;
use App\Models\Property;
use App\Notifications\LeaseExpiringReminder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaseManagementTest extends TestCase
{
    private function actingAsRealEstateAdmin(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function createRentLead(array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'deal_type' => 'rent',
            'stage' => 'viewing_done',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+971501234567',
        ], $overrides));
    }

    public function test_recording_lease_converts_lead_to_client_and_saves_unit_details(): void
    {
        $this->actingAsRealEstateAdmin();
        $lead = $this->createRentLead();
        $property = Property::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'address' => 'Marina Heights, Dubai Marina',
        ]);

        $this->post(route('leases.store'), [
            'lead_id' => $lead->id,
            'property_id' => $property->id,
            'contract_start_date' => '2026-09-01',
            'contract_end_date' => '2027-08-31',
            'rent_price' => '120000',
            'admin_fee' => '2500',
            'unit_address' => 'Marina Heights, Dubai Marina',
            'unit_no' => '1204',
            'community' => 'Dubai Marina',
        ])->assertRedirect(route('leases.index'));

        $client = Buyer::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($client);
        $this->assertSame('jane.doe@example.com', $client->email);
        $this->assertSame(1, $client->total_deals_closed);

        $lease = Lease::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($lease);
        $this->assertNotNull($lease->buyer_id);
        $this->assertSame($lead->id, $lease->lead_id);
        $this->assertSame($property->id, $lease->property_id);
        $this->assertSame('2027-08-31', $lease->contract_end_date->format('Y-m-d'));
        $this->assertSame('1204', $lease->unit_no);
        $this->assertSame('Dubai Marina', $lease->community);
        $this->assertSame('Marina Heights, Dubai Marina', $lease->unit_address);
    }

    public function test_recording_second_lease_for_same_client_does_not_duplicate(): void
    {
        $this->actingAsRealEstateAdmin();
        $existing = Buyer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
            'total_deals_closed' => 0,
        ]);

        $lead1 = $this->createRentLead(['email' => 'Jane.Doe@example.com', 'phone' => '+971501234567']);
        $lead2 = $this->createRentLead(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane.doe@example.com', 'phone' => '+971509999999']);

        $this->post(route('leases.store'), ['lead_id' => $lead1->id, 'contract_end_date' => '2027-08-31']);
        $this->post(route('leases.store'), ['lead_id' => $lead2->id, 'contract_end_date' => '2028-08-31']);

        $this->assertSame(1, Buyer::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, Lease::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(2, $existing->fresh()->total_deals_closed);
    }

    public function test_renewal_updates_dates_and_resets_reminder_window(): void
    {
        $this->actingAsRealEstateAdmin();
        $lead = $this->createRentLead();
        $this->post(route('leases.store'), ['lead_id' => $lead->id, 'contract_end_date' => '2027-08-31'])
            ->assertSessionHasNoErrors();

        $lease = Lease::where('tenant_id', $this->tenant->id)->first();
        $lease->update(['reminder_sent_at' => now(), 'status' => 'active']);

        $this->post(route('leases.renew', $lease), ['contract_end_date' => '2028-08-31', 'rent_price' => '130000'])
            ->assertRedirect(route('leases.index'));

        $lease->refresh();
        $this->assertSame('2028-08-31', $lease->contract_end_date->format('Y-m-d'));
        $this->assertNull($lease->reminder_sent_at);
        $this->assertSame('renewed', $lease->status);
        $this->assertSame('130000.00', (string) $lease->rent_price);
    }

    public function test_start_new_search_creates_rent_lead_for_same_agent(): void
    {
        $this->actingAsRealEstateAdmin();
        $agent = $this->createUserWithRole('agent', ['tenant_id' => $this->tenant->id]);
        $lead = $this->createRentLead(['agent_id' => $agent->id]);
        $this->post(route('leases.store'), ['lead_id' => $lead->id, 'contract_end_date' => '2027-08-31']);

        $lease = Lease::where('tenant_id', $this->tenant->id)->first();

        $this->post(route('leases.startNewSearch', $lease), ['requirements' => '2BR, Marina, budget 120k']);

        $newLead = Lead::where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $lead->id)
            ->first();

        $this->assertNotNull($newLead);
        $this->assertSame('rent', $newLead->deal_type);
        $this->assertSame('new_lead', $newLead->stage);
        $this->assertSame($agent->id, $newLead->agent_id);
        $this->assertSame('jane.doe@example.com', $newLead->email);
        $this->assertSame('2BR, Marina, budget 120k', $newLead->custom_fields['sought_unit'] ?? null);
    }

    public function test_expiring_lease_notifies_agent_and_manager_once(): void
    {
        $this->actingAsRealEstateAdmin();

        $manager = $this->createUserWithRole('agent', [
            'tenant_id' => $this->tenant->id,
            'reports_to' => $this->adminUser->id,
        ]);
        $agent = $this->createUserWithRole('agent', [
            'tenant_id' => $this->tenant->id,
            'reports_to' => $manager->id,
        ]);

        $client = Buyer::factory()->create(['tenant_id' => $this->tenant->id]);
        $lease = Lease::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agent->id,
            'buyer_id' => $client->id,
            'contract_end_date' => now()->addDays(20),
            'status' => 'active',
        ]);

        $this->artisan('leases:remind-expiring')->assertSuccessful();

        $agentNotifications = DB::table('notifications')
            ->where('notifiable_id', $agent->id)
            ->where('type', LeaseExpiringReminder::class)
            ->count();
        $managerNotifications = DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('type', LeaseExpiringReminder::class)
            ->count();

        $this->assertSame(1, $agentNotifications, 'Agent should be notified once.');
        $this->assertSame(1, $managerNotifications, 'Agent\'s manager should be notified once.');
        $this->assertNotNull($lease->fresh()->reminder_sent_at);

        // A second run must not re-notify (one-shot reminder).
        $this->artisan('leases:remind-expiring')->assertSuccessful();
        $this->assertSame(
            1,
            DB::table('notifications')->where('notifiable_id', $agent->id)
                ->where('type', LeaseExpiringReminder::class)
                ->count(),
        );
    }

    public function test_lease_index_and_create_pages_render(): void
    {
        $this->actingAsRealEstateAdmin();
        $client = Buyer::factory()->create(['tenant_id' => $this->tenant->id]);
        Lease::factory()->create(['tenant_id' => $this->tenant->id, 'buyer_id' => $client->id]);

        $this->get(route('leases.index'))->assertStatus(200);
        $this->get(route('leases.create'))->assertStatus(200);
    }

    public function test_past_contracts_are_flagged_expired(): void
    {
        $this->actingAsRealEstateAdmin();
        $client = Buyer::factory()->create(['tenant_id' => $this->tenant->id]);
        Lease::factory()->create([
            'tenant_id' => $this->tenant->id,
            'buyer_id' => $client->id,
            'contract_end_date' => now()->subDay(),
            'status' => 'active',
        ]);

        $this->artisan('leases:remind-expiring')->assertSuccessful();

        $this->assertSame('expired', Lease::firstWhere('tenant_id', $this->tenant->id)->status);
    }
}
