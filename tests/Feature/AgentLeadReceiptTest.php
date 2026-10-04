<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadDistributionService;
use Tests\TestCase;

/**
 * The per-agent "receives new leads" opt-out.
 *
 * The contract under test: switching it off removes an agent from every
 * automatic assignment path, and leaves the leads they already hold alone.
 */
class AgentLeadReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    /**
     * An agent, optionally opted out of lead distribution.
     */
    protected function createAgent(array $overrides = []): User
    {
        return $this->createUserWithRole('agent', array_merge([
            'name' => 'Agent '.uniqid(),
            'email' => uniqid().'@example.com',
            'receives_leads' => true,
        ], $overrides));
    }

    public function test_agents_receive_leads_by_default(): void
    {
        $agent = $this->createAgent();

        $this->assertTrue($agent->receives_leads);
        $this->assertTrue($agent->isInLeadRotation());
    }

    public function test_round_robin_skips_an_agent_who_opted_out(): void
    {
        $optedOut = $this->createAgent(['receives_leads' => false]);
        $receiving = $this->createAgent();

        // The tenant's own admin is active and would otherwise be in the pool
        // too; exclude it so the assertion is about the two agents.
        $this->adminUser->update(['receives_leads' => false]);

        $this->tenant->update(['distribution_method' => 'round_robin', 'round_robin_index' => 0]);

        $lead = $this->createLead(['agent_id' => null]);
        $assigned = app(LeadDistributionService::class)->distribute($lead->fresh(), $this->tenant);

        $this->assertNotNull($assigned);
        $this->assertSame($receiving->id, $assigned->id);
        $this->assertNotSame($optedOut->id, $lead->fresh()->agent_id);
    }

    public function test_round_robin_falls_through_every_time_when_the_pool_is_empty(): void
    {
        $this->createAgent(['receives_leads' => false]);
        $this->adminUser->update(['receives_leads' => false]);

        $this->tenant->update(['distribution_method' => 'round_robin']);

        $lead = $this->createLead(['agent_id' => null]);

        $this->assertNull(app(LeadDistributionService::class)->distribute($lead->fresh(), $this->tenant));
        $this->assertNull($lead->fresh()->agent_id);
    }

    public function test_round_robin_ignores_a_deactivated_agent_independently_of_the_toggle(): void
    {
        // Two distinct switches: is_active takes somebody out of every pool,
        // receives_leads only opts them out of incoming work.
        $deactivated = $this->createAgent(['is_active' => false, 'receives_leads' => true]);
        $receiving = $this->createAgent(['is_active' => true, 'receives_leads' => true]);
        $this->adminUser->update(['receives_leads' => false]);

        $this->tenant->update(['distribution_method' => 'round_robin', 'round_robin_index' => 0]);

        $assigned = app(LeadDistributionService::class)->distribute($this->createLead(['agent_id' => null])->fresh(), $this->tenant);

        $this->assertSame($receiving->id, $assigned->id);
        $this->assertNotSame($deactivated->id, $assigned->id);
    }

    public function test_ai_smart_never_offers_an_agent_who_opted_out(): void
    {
        $optedOut = $this->createAgent(['receives_leads' => false]);
        $receiving = $this->createAgent();

        // AI is unavailable in tests, so this exercises the ai_smart candidate
        // list and its round-robin fallback. The tenant's own admin is opted
        // out too so the only possible answer is the receiving agent.
        $this->adminUser->update(['receives_leads' => false]);
        $this->tenant->update(['distribution_method' => 'ai_smart', 'round_robin_index' => 0]);

        $lead = $this->createLead(['agent_id' => null]);

        $assigned = app(LeadDistributionService::class)->distribute($lead->fresh(), $this->tenant);

        $this->assertSame($receiving->id, $assigned->id);
        $this->assertNotSame($optedOut->id, $assigned->id);
    }

    public function test_unclaimed_lead_fallback_also_skips_an_agent_who_opted_out(): void
    {
        $optedOut = $this->createAgent(['receives_leads' => false]);
        $receiving = $this->createAgent();
        $this->adminUser->update(['receives_leads' => false]);

        // hybrid leaves the lead unclaimed; the command sweeps it up after the
        // claim window and must not reach past the opt-out.
        $this->tenant->update([
            'distribution_method' => 'hybrid',
            'claim_window_minutes' => 0,
            'round_robin_index' => 0,
        ]);

        $lead = $this->createLead(['agent_id' => null]);
        Lead::whereKey($lead->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('leads:assign-unclaimed')->assertSuccessful();

        $this->assertSame($receiving->id, $lead->fresh()->agent_id);
        $this->assertNotSame($optedOut->id, $lead->fresh()->agent_id);
    }

    public function test_leads_already_on_their_book_are_left_alone_when_they_opt_out(): void
    {
        $agent = $this->createAgent();
        $existing = $this->createLead(['agent_id' => $agent->id, 'status' => 'qualified']);

        $agent->update(['receives_leads' => false]);
        $agent->refresh();

        $this->assertSame($agent->id, $existing->fresh()->agent_id);
        $this->assertSame('qualified', $existing->fresh()->status);
    }

    public function test_admin_can_switch_lead_receipt_off_from_the_agent_profile(): void
    {
        $agent = $this->createAgent();
        $this->assertTrue($agent->receives_leads);

        // An unchecked checkbox is simply absent from the payload.
        $this->put(route('settings.updateAgent', $agent), [
            'name' => $agent->name,
            'email' => $agent->email,
            'role_id' => $agent->role_id,
        ])->assertRedirect(route('settings.index', ['tab' => 'team']));

        $this->assertFalse($agent->fresh()->receives_leads);
    }

    public function test_admin_can_switch_lead_receipt_back_on(): void
    {
        $agent = $this->createAgent(['receives_leads' => false]);

        $this->put(route('settings.updateAgent', $agent), [
            'name' => $agent->name,
            'email' => $agent->email,
            'role_id' => $agent->role_id,
            'receives_leads' => '1',
        ])->assertRedirect();

        $this->assertTrue($agent->fresh()->receives_leads);
    }

    public function test_lead_receipt_state_is_visible_in_the_team_table(): void
    {
        $receiving = $this->createAgent();
        $optedOut = $this->createAgent(['receives_leads' => false]);

        $this->get(route('settings.index', ['tab' => 'team']))
            ->assertOk()
            ->assertSee('Receiving')
            ->assertSee('Not receiving');
    }

    public function test_inviting_a_member_defaults_to_receiving_leads(): void
    {
        $role = Role::where('name', 'agent')->first();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'New Agent',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => User::where('name', 'New Agent')->value('email'),
            'receives_leads' => true,
        ]);
    }

    public function test_inviting_a_member_can_opt_out_up_front(): void
    {
        $role = Role::where('name', 'agent')->first();
        $email = uniqid().'@example.com';

        $this->post(route('settings.inviteAgent'), [
            'name' => 'Opted Out Agent',
            'email' => $email,
            'password' => 'password123',
            'role_id' => $role->id,
            'receives_leads' => '0',
        ])->assertRedirect();

        $this->assertFalse(User::where('email', $email)->value('receives_leads'));
    }

    public function test_the_owner_role_cannot_be_assigned_by_invite(): void
    {
        // Guards the assumption behind the owner default: owners are never
        // created through Settings (assignableRoleIdsFor() excludes the role),
        // only at register/install time.
        $ownerRole = Role::where('name', 'owner')->firstOrFail();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'Sneaky Owner',
            'email' => uniqid().'@example.com',
            'password' => 'password123',
            'role_id' => $ownerRole->id,
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['name' => 'Sneaky Owner']);
    }

    public function test_an_installer_provisioned_owner_starts_out_of_the_rotation(): void
    {
        // The CRM is single-tenant, so an owner is only ever created by the
        // installer (or by hand in the database). Pin the rule that the owner
        // role is out of the distribution pool by default, independent of how
        // the account was created.
        $ownerRole = Role::where('name', 'owner')->firstOrFail();

        $owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $ownerRole->id,
            'is_active' => true,
            'name' => 'Provisioned Owner',
            'email' => uniqid().'@example.com',
            'receives_leads' => false,
        ]);

        $this->assertFalse(
            $owner->isInLeadRotation(),
            'an owner must not be in the rotation unless explicitly opted in'
        );

        // ...and opting in still works, so this is a default and not a lockout.
        $owner->update(['receives_leads' => true]);
        $this->assertTrue($owner->fresh()->isInLeadRotation());
    }

    public function test_an_owner_is_skipped_by_lead_distribution_by_default(): void
    {
        // The acting admin is an admin, not an agent, so take them out of the
        // pool too - otherwise they win the round robin on id order and the
        // assertion says nothing about the owner.
        auth()->user()->update(['receives_leads' => false]);

        $owner = $this->createAgent(['role_id' => Role::where('name', 'owner')->value('id')]);
        $owner->update(['receives_leads' => false]);
        $receiving = $this->createAgent();

        $this->tenant->update(['distribution_method' => 'round_robin', 'round_robin_index' => 0]);

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Round',
            'last_name' => 'Robin',
        ]);

        $this->assertSame(
            $receiving->id,
            app(LeadDistributionService::class)->distribute($lead, $this->tenant)->id
        );
    }

    public function test_the_owner_backfill_migration_clears_the_flag_for_existing_owners(): void
    {
        $owner = $this->createAgent(['role_id' => Role::where('name', 'owner')->value('id')]);
        $owner->update(['receives_leads' => true]);
        $agent = $this->createAgent();

        // RefreshDatabase already ran this migration, so invoke it directly
        // rather than via artisan migrate (which would report it as applied).
        $migration = require database_path('migrations/2026_09_30_000003_set_owner_receives_leads_false.php');
        $migration->up();

        $this->assertFalse($owner->fresh()->receives_leads, 'owner should be cleared');
        $this->assertTrue($agent->fresh()->receives_leads, 'non-owner must be untouched');
    }

    public function test_portal_lead_is_not_routed_to_an_agent_who_opted_out_by_listing_code(): void
    {
        // The listing reference carries the agent code, so a portal lead for
        // their unit routes straight to them - bypassing the distribution
        // formula. The opt-out has to close that back door too.
        $optedOut = $this->createAgent(['agent_code' => 'ZZ', 'receives_leads' => false]);
        $receiving = $this->createAgent();

        $integration = PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $this->tenant->update([
            'distribution_method' => 'round_robin',
            'round_robin_index' => 0,
            'custom_options' => ['portal_leads' => ['unmatched' => 'distribute']],
        ]);
        $this->adminUser->update(['receives_leads' => false]);

        $lead = (new \App\Services\Portals\PortalLeadService)->createFromPayload(
            $integration,
            'property_finder',
            [
                'id' => 'pf-lead-optout',
                'name' => 'Nadia Haddad',
                'phone' => '+971 50 777 1234',
                'reference' => 'ZZ-'.$this->createProperty()->id,
            ],
        );

        $this->assertNotNull($lead);
        $this->assertNotSame($optedOut->id, $lead->agent_id);
        $this->assertSame($receiving->id, $lead->agent_id);
    }

    public function test_portal_lead_is_routed_by_listing_code_to_an_agent_who_did_receive(): void
    {
        // The counterpart: the toggle off must not break the happy path.
        $routed = $this->createAgent(['agent_code' => 'YY']);

        $integration = PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $lead = (new \App\Services\Portals\PortalLeadService)->createFromPayload(
            $integration,
            'property_finder',
            [
                'id' => 'pf-lead-routed',
                'name' => 'Tarek Mansour',
                'phone' => '+971 50 888 1234',
                'reference' => 'YY-'.$this->createProperty()->id,
            ],
        );

        $this->assertNotNull($lead);
        $this->assertSame($routed->id, $lead->agent_id);
    }
}
