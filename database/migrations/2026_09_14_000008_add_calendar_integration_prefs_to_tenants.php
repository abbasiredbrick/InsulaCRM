<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'calendar_sync_enabled')) {
                $table->boolean('calendar_sync_enabled')->default(true)->after('microsoft_client_secret');
                $table->unsignedSmallInteger('calendar_reminder_default_minutes')->nullable()->after('calendar_sync_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['calendar_sync_enabled', 'calendar_reminder_default_minutes']);
        });
    }
};
