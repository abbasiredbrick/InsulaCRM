<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'google_client_id')) {
                $table->string('google_client_id', 255)->nullable()->after('storage_disk');
                $table->text('google_client_secret')->nullable()->after('google_client_id');
                $table->string('microsoft_client_id', 255)->nullable()->after('google_client_secret');
                $table->text('microsoft_client_secret')->nullable()->after('microsoft_client_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'google_client_id',
                'google_client_secret',
                'microsoft_client_id',
                'microsoft_client_secret',
            ]);
        });
    }
};