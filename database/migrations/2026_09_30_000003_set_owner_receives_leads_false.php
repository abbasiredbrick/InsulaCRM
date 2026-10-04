<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('roles')
                    ->whereColumn('roles.id', 'users.role_id')
                    ->where('roles.name', 'owner');
            })
            ->update(['receives_leads' => false]);
    }

    public function down(): void
    {
        DB::table('users')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('roles')
                    ->whereColumn('roles.id', 'users.role_id')
                    ->where('roles.name', 'owner');
            })
            ->update(['receives_leads' => true]);
    }
};
