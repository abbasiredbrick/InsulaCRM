<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * The Owner sits above Admin. Only the Owner manages Admins (an Admin manages
 * only the operational roles below them), and exactly one Owner exists at a
 * time - ownership is handed over, never added.
 */
class OwnerRoleTest extends TestCase
{
    private function owner(): User
    {
        if (! isset($this->tenant)) {
            $this->createTenantWithAdmin();
        }

        return $this->createUserWithRole('owner');
    }

    public function test_owner_outranks_admin_and_agents(): void
    {
        $owner = $this->owner();
        $admin = $this->createUserWithRole('admin');
        $agent = $this->createUserWithRole('agent');

        $this->assertSame(3, $owner->roleRank());
        $this->assertSame(2, $admin->roleRank());
        $this->assertSame(1, $agent->roleRank());

        $this->assertTrue($owner->outranks($admin));
        $this->assertTrue($owner->outranks($agent));
        $this->assertFalse($admin->outranks($owner));
        $this->assertTrue($admin->outranks($agent));
        $this->assertFalse($agent->outranks($admin));
    }

    public function test_owner_passes_existing_admin_gates(): void
    {
        $owner = $this->owner();

        $this->assertTrue($owner->isAdmin());
        $this->assertTrue($owner->hasRole('admin'));
        $this->assertTrue($owner->isOwner());

        $this->actingAs($owner)
            ->get(route('settings.index', ['tab' => 'team']))
            ->assertOk();
    }

    public function test_admin_cannot_manage_an_admin(): void
    {
        $this->actingAsAdmin();
        $peer = $this->createUserWithRole('admin');

        $this->patch(route('settings.toggleAgent', $peer))->assertForbidden();
        $this->delete(route('settings.reset2fa', $peer))->assertForbidden();
        $this->delete(route('settings.destroyAgent', $peer), ['reassign_to' => $this->adminUser->id])
            ->assertForbidden();

        $this->assertTrue((bool) $peer->fresh()->is_active);
    }

    public function test_admin_cannot_manage_the_owner(): void
    {
        $this->actingAsAdmin();
        $owner = $this->owner();

        $this->patch(route('settings.toggleAgent', $owner))->assertForbidden();
    }

    public function test_owner_can_manage_an_admin(): void
    {
        $this->createTenantWithAdmin();
        $owner = $this->createUserWithRole('owner');
        $this->actingAs($owner);

        $this->patch(route('settings.toggleAgent', $this->adminUser))->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->adminUser->fresh()->is_active);
    }

    public function test_admin_cannot_assign_the_admin_role(): void
    {
        $this->actingAsAdmin();
        $adminRole = Role::where('name', 'admin')->first();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'password123',
            'role_id' => $adminRole->id,
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'newadmin@example.com']);
    }

    public function test_owner_can_assign_the_admin_role(): void
    {
        $this->createTenantWithAdmin();
        $owner = $this->createUserWithRole('owner');
        $this->actingAs($owner);
        $adminRole = Role::where('name', 'admin')->first();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'password123',
            'role_id' => $adminRole->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newadmin@example.com', 'role_id' => $adminRole->id]);
    }

    public function test_owner_role_cannot_be_assigned(): void
    {
        $this->createTenantWithAdmin();
        $owner = $this->createUserWithRole('owner');
        $this->actingAs($owner);
        $ownerRole = Role::where('name', 'owner')->first();

        $this->post(route('settings.inviteAgent'), [
            'name' => 'Second Owner',
            'email' => 'secondowner@example.com',
            'password' => 'password123',
            'role_id' => $ownerRole->id,
        ])->assertSessionHasErrors('role_id');

        $this->assertDatabaseMissing('users', ['email' => 'secondowner@example.com']);
    }

    public function test_only_owner_can_transfer_ownership(): void
    {
        $this->actingAsAdmin();
        $agent = $this->createUserWithRole('agent');

        $this->post(route('settings.transferOwnership'), ['user_id' => $agent->id])
            ->assertForbidden();
    }

    public function test_ownership_transfer_swaps_roles_and_keeps_a_single_owner(): void
    {
        $this->createTenantWithAdmin();
        $owner = $this->createUserWithRole('owner');
        $admin = $this->adminUser;
        $this->actingAs($owner);

        $this->post(route('settings.transferOwnership'), ['user_id' => $admin->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('owner', $admin->fresh()->role->name);
        $this->assertSame('admin', $owner->fresh()->role->name);

        $ownerRoleId = Role::where('name', 'owner')->first()->id;
        $this->assertSame(1, User::where('tenant_id', $this->tenant->id)
            ->where('role_id', $ownerRoleId)
            ->count());
    }

    public function test_ownership_cannot_be_transferred_to_a_non_admin(): void
    {
        $this->createTenantWithAdmin();
        $owner = $this->createUserWithRole('owner');
        $agent = $this->createUserWithRole('agent');
        $this->actingAs($owner);

        $this->post(route('settings.transferOwnership'), ['user_id' => $agent->id])
            ->assertSessionHas('error');

        $this->assertSame('owner', $owner->fresh()->role->name);
        $this->assertSame('agent', $agent->fresh()->role->name);
    }

    public function test_owner_cannot_be_impersonated_by_an_admin(): void
    {
        $this->actingAsAdmin();
        $owner = $this->owner();

        $this->post(route('settings.impersonate', $owner), ['password' => 'password'])
            ->assertForbidden();
    }
}
