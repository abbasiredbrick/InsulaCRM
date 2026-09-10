<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('rent_price');
            $table->decimal('admin_fee', 12, 2)->nullable()->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['deposit_amount', 'admin_fee']);
        });
    }
};