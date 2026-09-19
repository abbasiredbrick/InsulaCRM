<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('bayut_location_id', 100)->nullable()->after('bayut_status');
            $table->string('bayut_location_label', 255)->nullable()->after('bayut_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['bayut_location_label', 'bayut_location_id']);
        });
    }
};