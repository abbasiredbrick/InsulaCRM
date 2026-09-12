<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'marketing', 'tenant_id' => null],
            ['display_name' => 'Marketing', 'is_system' => true]
        );

        $keys = [
            'leads.view', 'leads.create', 'leads.edit', 'leads.export',
            'properties.view', 'properties.create', 'properties.edit',
            'calendar.view', 'profile.edit',
        ];

        $permissionIds = Permission::whereIn('key', $keys)->pluck('id');

        $role->permissions()->syncWithoutDetaching($permissionIds);
    }

    public function down(): void
    {
        Role::where('name', 'marketing')->each(fn (Role $role) => $role->delete());
    }
};
