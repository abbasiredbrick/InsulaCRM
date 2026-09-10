<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('tawtheeq_fee', 12, 2)->nullable()->after('admin_fee');
        });

        // Policy-level defaults that apply to every row of a source sheet
        // (e.g. "Admin Fee: AED 1,050/-", "Tawtheeq: AED 150/-").
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->decimal('default_deposit', 12, 2)->nullable()->after('status_map');
            $table->decimal('default_admin_fee', 12, 2)->nullable()->after('default_deposit');
            $table->decimal('default_tawtheeq_fee', 12, 2)->nullable()->after('default_admin_fee');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('tawtheeq_fee');
        });
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->dropColumn(['default_deposit', 'default_admin_fee', 'default_tawtheeq_fee']);
        });
    }
};