<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'calendar_provider')) {
                $table->string('calendar_provider', 20)->nullable()->after('is_completed');
                $table->string('calendar_event_id', 500)->nullable()->after('calendar_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['calendar_provider', 'calendar_event_id']);
        });
    }
};