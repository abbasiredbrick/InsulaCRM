<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->longText('location_catalog')->nullable()->after('default_location_id');
            $table->timestamp('locations_synced_at')->nullable()->after('location_catalog');
        });
    }

    public function down(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->dropColumn(['location_catalog', 'locations_synced_at']);
        });
    }
};