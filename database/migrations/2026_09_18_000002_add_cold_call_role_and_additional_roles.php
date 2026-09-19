<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Introduce the Cold Call Agent role and a role_user pivot so every member
     * keeps a single primary role (their rank and identity in the UI) while
     * gaining additional operational roles (e.g. a Listing Agent who also cold
     * calls). Owner/Admin stay primary-only privilege roles.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['role_id', 'user_id']);
        });

        $role = Role::firstOrCreate(
            ['name' => 'cold_call_agent'],
            ['display_name' => 'Cold Call Agent', 'is_system' => true]
        );

        $keys = [
            'leads.view', 'leads.create', 'leads.edit', 'leads.export',
            'properties.view', 'properties.create', 'properties.edit',
            'calendar.view', 'profile.edit',
        ];

        if (Schema::hasTable('permissions') && Schema::hasTable('role_permission')) {
            $permissionIds = Permission::whereIn('key', $keys)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Role::where('name', 'cold_call_agent')->delete();
    }
};
