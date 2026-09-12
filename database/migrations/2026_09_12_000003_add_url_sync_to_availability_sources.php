<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->string('url', 500)->nullable()->after('contact_info');
            $table->string('missing_status', 20)->default('leased')->after('status_map');
        });
    }

    public function down(): void
    {
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->dropColumn(['url', 'missing_status']);
        });
    }
};
