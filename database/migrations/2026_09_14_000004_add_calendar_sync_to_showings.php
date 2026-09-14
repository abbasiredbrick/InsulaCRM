<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('showings', function (Blueprint $table) {
            if (! Schema::hasColumn('showings', 'calendar_provider')) {
                $table->string('calendar_provider', 20)->nullable()->after('notes');
                $table->string('calendar_event_id', 500)->nullable()->after('calendar_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('showings', function (Blueprint $table) {
            $table->dropColumn(['calendar_provider', 'calendar_event_id']);
        });
    }
};