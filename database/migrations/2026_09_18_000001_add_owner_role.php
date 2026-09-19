<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Introduce the Owner role above Admin and promote the primary workspace
     * administrator so the tenant always has exactly one Owner.
     */
    public function up(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'owner', 'tenant_id' => null],
            ['display_name' => 'Owner', 'is_system' => true]
        );

        if (Schema::hasTable('permissions') && Schema::hasTable('role_permission')) {
            $role->permissions()->syncWithoutDetaching(Permission::pluck('id')->all());
        }

        DB::table('users')
            ->where('email', 'admin@pristineproperties.ae')
            ->update(['role_id' => $role->id]);
    }

    public function down(): void
    {
        $owner = Role::where('name', 'owner')->first();
        if (! $owner) {
            return;
        }

        $admin = Role::where('name', 'admin')->first();
        if ($admin) {
            DB::table('users')->where('role_id', $owner->id)->update(['role_id' => $admin->id]);
        }

        $owner->permissions()->detach();
        $owner->delete();
    }
};
