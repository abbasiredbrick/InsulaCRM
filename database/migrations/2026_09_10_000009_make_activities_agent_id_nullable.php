<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API (mobile apps, key-authenticated) can log activities without a
        // session user; agent_id is optional there and null otherwise.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE activities MODIFY COLUMN agent_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('activities', function (Blueprint $table) {
                $table->foreignId('agent_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Reverting not possible if null agent_id rows exist.
    }
};