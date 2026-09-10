<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->text('leads_api_token')->nullable()->after('webhook_secret');
            $table->timestamp('leads_last_synced_at')->nullable()->after('leads_api_token');
            $table->text('leads_last_error')->nullable()->after('leads_last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->dropColumn(['leads_api_token', 'leads_last_synced_at', 'leads_last_error']);
        });
    }
};