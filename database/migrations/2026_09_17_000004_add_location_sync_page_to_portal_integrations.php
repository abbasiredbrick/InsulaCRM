<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->unsignedInteger('location_sync_page')->nullable()->default(null)->after('locations_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->dropColumn('location_sync_page');
        });
    }
};