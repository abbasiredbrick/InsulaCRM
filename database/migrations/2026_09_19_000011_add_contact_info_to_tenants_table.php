<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('email');
            $table->string('phone', 100)->nullable()->after('address');
            $table->string('website', 255)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['address', 'phone', 'website']);
        });
    }
};