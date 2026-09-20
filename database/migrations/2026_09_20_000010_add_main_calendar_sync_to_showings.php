<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('showings', function (Blueprint $table) {
            if (! Schema::hasColumn('showings', 'main_calendar_provider')) {
                $table->string('main_calendar_provider', 20)->nullable()->after('calendar_event_id');
                $table->string('main_calendar_event_id', 500)->nullable()->after('main_calendar_provider');
            }
        });
    }

    public function down(): void
    {
        Schema::table('showings', function (Blueprint $table) {
            $table->dropColumn(['main_calendar_provider', 'main_calendar_event_id']);
        });
    }
};