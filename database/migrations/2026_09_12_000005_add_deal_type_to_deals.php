<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('deal_type', 20)->nullable()->index()->after('tenant_id');
            $table->foreignId('lease_id')->nullable()->after('lead_id')->constrained('leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropForeign(['lease_id']);
            $table->dropColumn(['deal_type', 'lease_id']);
        });
    }
};
