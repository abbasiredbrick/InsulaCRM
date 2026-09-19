<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ColdCallRoleTest extends TestCase
{
    public function test_cold_call_agent_role_exists(): void
    {
        $role = Role::where('name', 'cold_call_agent')->first();

        $this->assertNotNull($role);
        $this->assertSame('Cold Call Agent', $role->display_name);
        $this->assertTrue($role->is_system);
    }

    public function test_additional_role_is_respected_by_has_role(): void
    {
        $this->createTenantWithAdmin();
        $user = $this->createUserWithRole('listing_agent');
        $user->secondaryRoles()->attach(Role::where('name', 'cold_call_agent')->first()->id);

        $this->assertTrue($user->hasRole('cold_call_agent'));
        $this->assertTrue($user->isColdCallAgent());
        $this->assertTrue($user->isAgent());
        $this->assertIsList($user->roleNames());
        $this->assertContains('listing_agent', $user->roleNames());
        $this->assertContains('cold_call_agent', $user->roleNames());
    }

    public function test_agent_without_additional_role_is_not_a_cold_call_agent(): void
    {
        $this->createTenantWithAdmin();
        $user = $this->createUserWithRole('listing_agent');

        $this->assertFalse($user->isColdCallAgent());
        $this->assertFalse($user->hasRole('cold_call_agent'));
    }

    public function test_cold_call_agent_can_manage_leads_and_holds_marketing_permissions(): void
    {
        $this->createTenantWithAdmin();
        $user = $this->createUserWithRole('cold_call_agent');

        $this->assertTrue($user->isAgent());
        $this->assertTrue($user->canManageLeads());
        $this->assertTrue($user->hasPermission('leads.view'));
        $this->assertTrue($user->hasPermission('properties.view'));
    }

    public function test_owner_and_admin_cannot_be_granted_as_additional_roles(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $adminId = Role::where('name', 'admin')->first()->id;
        $agentRoleId = Role::where('name', 'agent')->first()->id;

        $this->post(route('settings.inviteAgent'), [
            'name' => 'Dup Admin',
            'email' => 'dup@example.com',
            'password' => 'secret-pass-123',
            'role_id' => $agentRoleId,
            'additional_roles' => [$adminId],
        ])->assertSessionHasErrors('additional_roles.0');

        $this->assertDatabaseMissing('role_user', ['role_id' => $adminId]);
    }

    public function test_additional_roles_are_synced_when_inviting_a_member(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $roleId = Role::where('name', 'agent')->first()->id;
        $coldCallId = Role::where('name', 'cold_call_agent')->first()->id;

        $this->post(route('settings.inviteAgent'), [
            'name' => 'Called Agent',
            'email' => 'cold@example.com',
            'password' => 'secret-pass-123',
            'role_id' => $roleId,
            'additional_roles' => [$coldCallId],
        ])->assertRedirect();

        $member = User::where('email', 'cold@example.com')->first();
        $this->assertNotNull($member);
        $this->assertTrue($member->secondaryRoles()->where('name', 'cold_call_agent')->exists());
    }

    public function test_additional_roles_are_synced_when_updating_a_member(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $member = $this->createUserWithRole('agent');

        $coldCallId = Role::where('name', 'cold_call_agent')->first()->id;

        $this->put(route('settings.updateAgent', $member), [
            'name' => $member->name,
            'email' => $member->email,
            'role_id' => $member->role_id,
            'additional_roles' => [$coldCallId],
        ])->assertRedirect();

        $this->assertTrue($member->refresh()->secondaryRoles()->where('name', 'cold_call_agent')->exists());
    }

    public function test_cold_calls_are_open_to_owner_and_admin(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->get(route('market.index'))->assertStatus(200);
    }

    public function test_cold_calls_are_rejected_for_unrelated_roles(): void
    {
        foreach (['acquisition_agent', 'disposition_agent', 'field_scout'] as $role) {
            $this->actingAsRole($role, ['business_mode' => 'realestate']);
            $this->get(route('market.index'))->assertStatus(403);
        }
    }

    public function test_cold_call_agent_sees_cold_calls_in_marketing_menu_but_not_admin_items(): void
    {
        $this->actingAsRole('cold_call_agent', ['business_mode' => 'realestate']);
        \App\Models\MarketContact::factory()->count(2)->create(['tenant_id' => $this->tenant->id]);

        $this->get(route('market.index'))
            ->assertStatus(200)
            ->assertSee('Cold Calls')
            ->assertDontSee('>Sequences</a>')
            ->assertDontSee('>Campaigns</a>');
    }

    public function test_secondary_role_options_exclude_owner_and_admin_on_team_settings(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $html = $this->get(route('settings.index', ['tab' => 'team']))->assertStatus(200)->getContent();

        $start = strpos($html, 'name="additional_roles[]"');
        $end = strpos($html, '</select>', $start);
        $additionalSelect = substr($html, $start, $end - $start);

        $coldCallId = Role::where('name', 'cold_call_agent')->first()->id;
        $adminId = Role::where('name', 'admin')->first()->id;
        $ownerId = Role::where('name', 'owner')->first()->id;

        $this->assertStringNotContainsString('<option value="'.$adminId.'"', $additionalSelect);
        $this->assertStringNotContainsString('<option value="'.$ownerId.'"', $additionalSelect);
        $this->assertStringContainsString('<option value="'.$coldCallId.'"', $additionalSelect);
    }
}
