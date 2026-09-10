<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert activities.type ENUM to VARCHAR so tenant-configurable activity
        // types (e.g. whatsapp) are accepted without schema changes.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE activities MODIFY COLUMN type VARCHAR(40) NOT NULL DEFAULT 'note'");
        } else {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('type', 40)->default('note')->change();
            });
        }
    }

    public function down(): void
    {
        // Reverting to ENUM is not safe if new values have been inserted.
        // This migration is intentionally non-reversible.
    }
};