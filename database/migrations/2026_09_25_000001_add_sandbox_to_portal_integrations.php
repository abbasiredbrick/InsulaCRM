<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->boolean('use_sandbox')->default(false)->after('is_active');
            $table->text('sandbox_api_token')->nullable()->after('api_secret');
            $table->text('sandbox_api_secret')->nullable()->after('sandbox_api_token');
        });
    }

    public function down(): void
    {
        Schema::table('portal_integrations', function (Blueprint $table) {
            $table->dropColumn(['use_sandbox', 'sandbox_api_token', 'sandbox_api_secret']);
        });
    }
};