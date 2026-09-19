<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_agents', function (Blueprint $table) {
            $table->foreignId('a2a_contract_id')->nullable()->after('share_funding')->constrained('a2a_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('a2a_contract_id');
        });
    }
};