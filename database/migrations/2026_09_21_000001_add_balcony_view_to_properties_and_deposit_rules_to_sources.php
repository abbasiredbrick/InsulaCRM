<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('balcony', 20)->nullable()->after('parking');
            $table->string('view', 100)->nullable()->after('balcony');
        });

        // Relevate style default: "Security Deposit: AED 5,000/- or 5% of annual
        // rent, whichever is higher". default_deposit_pct + default_deposit_min
        // let a source express the formula; max(min, pct × rent) is applied per
        // row at import time.
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->decimal('default_deposit_pct', 5, 2)->nullable()->after('default_deposit');
            $table->decimal('default_deposit_min', 12, 2)->nullable()->after('default_deposit_pct');
        });
    }

    public function down(): void
    {
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->dropColumn(['default_deposit_pct', 'default_deposit_min']);
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['balcony', 'view']);
        });
    }
};