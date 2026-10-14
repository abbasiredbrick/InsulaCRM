<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_contacts', function (Blueprint $table) {
            // Sentiment-derived outcome of the call / comment, used to build the
            // "expected inventory" re-call queue.
            $table->string('disposition', 30)->nullable()->after('requirements');
            $table->boolean('expected_inventory')->default(false)->after('disposition');
            $table->unsignedTinyInteger('priority')->default(0)->after('expected_inventory');
            // Round-robin hand-off stamp so a batch can be identified per interval.
            $table->timestamp('assigned_at')->nullable()->after('called_by');

            $table->index(['tenant_id', 'expected_inventory']);
            $table->index(['tenant_id', 'disposition']);
        });

        Schema::table('market_imports', function (Blueprint $table) {
            $table->string('source', 20)->default('upload')->after('format'); // upload | drive
            $table->string('drive_file_id')->nullable()->after('source');
            $table->string('drive_sheet_name')->nullable()->after('drive_file_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('cold_call_round_robin_index')->default(0)->after('round_robin_index');
        });
    }

    public function down(): void
    {
        Schema::table('market_contacts', function (Blueprint $table) {
            $table->dropColumn(['disposition', 'expected_inventory', 'priority', 'assigned_at']);
        });

        Schema::table('market_imports', function (Blueprint $table) {
            $table->dropColumn(['source', 'drive_file_id', 'drive_sheet_name']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('cold_call_round_robin_index');
        });
    }
};
