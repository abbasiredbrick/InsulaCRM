<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->text('api_token')->nullable()->after('url');   // encrypted bearer token for the source feed
            $table->string('org_slug', 100)->nullable()->after('api_token'); // TrueRentor operator slug
            $table->string('base_url', 255)->nullable()->after('org_slug');  // TrueRentor base URL (share links / OAuth)
        });
    }

    public function down(): void
    {
        Schema::table('availability_sources', function (Blueprint $table) {
            $table->dropColumn(['api_token', 'org_slug', 'base_url']);
        });
    }
};
