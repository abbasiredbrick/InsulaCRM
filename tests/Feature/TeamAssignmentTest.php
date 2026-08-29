<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Tests\TestCase;

/**
 * Covers the fresh-install lead assignment block reported in issue #3 and the
 * business mode switch that made issue #4 look like missing files.
 */
class TeamAssignmentTest extends TestCase
{
    // ── Assignment dropdown ──────────────────────────────────

    public function test_admin_is_assignable_on_a_fresh_single_user_install(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/leads/create');

        $response->assertStatus(200);
        $agents = $response->viewData('agents');

        $this->assertTrue(
            $agents->contains('id', $this->adminUser->id),
            'The admin must be selectable, otherwise a fresh install cannot create its first lead.'
        );
    }

    public function test_admin_can_create_a_lead_assigned_to_themselves(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/leads', [
            'agent_id'    => $this->adminUser->id,
            'first_name'  => 'Dana',
            'last_name'   => 'Whitfield',
            'lead_source' => 'referral',
            'status'      => 'new',
            'temperature' => 'warm',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('leads', [
            'first_name' => 'Dana',
            'agent_id'   => $this->adminUser->id,
        ]);
    }

    public function test_deactivated_members_are_not_offered_for_new_leads(): void
    {
        $this->actingAsAdmin();
        $this->createUserWithRole('agent', ['is_active' => false]);

        $agents = $this->get('/leads/create')->viewData('agents');

        $this->assertTrue($agents->every(fn ($a) => (bool) $a->is_active));
    }

    public function test_editing_keeps_a_deactivated_current_owner_selectable(): void
    {
        $this->actingAsAdmin();
        $member = $this->createUserWithRole('agent');
        $lead = $this->createLead(['agent_id' => $member->id]);

        $member->update(['is_active' => false]);

        $agents = $this->get("/leads/{$lead->id}/edit")->viewData('agents');

        $this->assertTrue(
            $agents->contains('id', $member->id),
            'A deactivated owner must stay selectable or saving the form silently reassigns the lead.'
        );
    }

    // ── Deleting a team member ───────────────────────────────

    public function test_deleting_a_member_reassigns_their_records_instead_of_cascading(): void
    {
        $this->actingAsAdmin();
        $member = $this->createUserWithRole('agent');

        $lead = $this->createLead(['agent_id' => $member->id]);
        $deal = $this->createDeal(['agent_id' => $member->id]);

        $response = $this->delete(route('settings.destroyAgent', $member), [
            'reassign_to' => $this->adminUser->id,
        ]);

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);

        // The cascade would have taken these with the user.
        $this->assertSame($this->adminUser->id, Lead::find($lead->id)?->agent_id);
        $this->assertSame($this->adminUser->id, Deal::find($deal->id)?->agent_id);
    }

    public function test_deleting_a_member_frees_their_email_for_reuse(): void
    {
        $this->actingAsAdmin();
        $member = $this->createUserWithRole('agent', ['email' => 'reused@example.com']);

        $this->delete(route('settings.destroyAgent', $member), [
            'reassign_to' => $this->adminUser->id,
        ]);

        $response = $this->post(route('settings.inviteAgent'), [
            'name'     => 'Second Attempt',
            'email'    => 'reused@example.com',
            'password' => 'password123',
            'role_id'  => \App\Models\Role::where('name', 'agent')->first()->id,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'reused@example.com', 'name' => 'Second Attempt']);
    }

    public function test_deletion_requires_a_reassignment_target(): void
    {
        $this->actingAsAdmin();
        $member = $this->createUserWithRole('agent');

        $this->delete(route('settings.destroyAgent', $member))
            ->assertSessionHasErrors('reassign_to');

        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_records_cannot_be_reassigned_to_the_member_being_deleted(): void
    {
        $this->actingAsAdmin();
        $member = $this->createUserWithRole('agent');

        $this->delete(route('settings.destroyAgent', $member), ['reassign_to' => $member->id])
            ->assertSessionHasErrors('reassign_to');

        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $this->actingAsAdmin();
        $other = $this->createUserWithRole('agent');

        $this->delete(route('settings.destroyAgent', $this->adminUser), [
            'reassign_to' => $other->id,
        ]);

        $this->assertDatabaseHas('users', ['id' => $this->adminUser->id]);
    }

    public function test_the_last_admin_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();
        $secondAdmin = $this->createUserWithRole('admin');

        // Acting as the second admin, the first is now deletable...
        $this->actingAs($secondAdmin);
        $this->delete(route('settings.destroyAgent', $this->adminUser), [
            'reassign_to' => $secondAdmin->id,
        ]);
        $this->assertDatabaseMissing('users', ['id' => $this->adminUser->id]);

        // ...but nobody can remove the one that remains.
        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);
        $this->delete(route('settings.destroyAgent', $secondAdmin), ['reassign_to' => $agent->id]);
        $this->assertDatabaseHas('users', ['id' => $secondAdmin->id]);
    }

    // ── Business mode switch ─────────────────────────────────

    public function test_business_mode_switch_requires_typed_confirmation(): void
    {
        $this->actingAsAdmin();

        $this->put(route('settings.updateBusinessMode'), [
            'business_mode' => 'realestate',
            'confirmation'  => 'yes',
        ]);

        $this->assertSame('wholesale', $this->tenant->fresh()->business_mode);
    }

    public function test_business_mode_switches_when_confirmed(): void
    {
        $this->actingAsAdmin();

        $this->put(route('settings.updateBusinessMode'), [
            'business_mode' => 'realestate',
            'confirmation'  => 'SWITCH',
        ]);

        $this->assertSame('realestate', $this->tenant->fresh()->business_mode);
        $this->assertDatabaseHas('audit_log', ['action' => 'tenant.business_mode_changed']);
    }

    public function test_business_mode_switch_does_not_touch_existing_records(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal(['stage' => 'dispositions']);

        $this->put(route('settings.updateBusinessMode'), [
            'business_mode' => 'realestate',
            'confirmation'  => 'SWITCH',
        ]);

        // Data is deliberately left alone - the operator remaps it themselves.
        $this->assertSame('dispositions', Deal::find($deal->id)->stage);
    }

    public function test_non_admin_cannot_switch_business_mode(): void
    {
        $this->createTenantWithAdmin();
        $this->actingAsRole('agent');

        $this->put(route('settings.updateBusinessMode'), [
            'business_mode' => 'realestate',
            'confirmation'  => 'SWITCH',
        ]);

        $this->assertSame('wholesale', $this->tenant->fresh()->business_mode);
    }
}
