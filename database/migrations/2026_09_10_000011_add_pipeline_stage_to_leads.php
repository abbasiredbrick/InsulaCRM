<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('deal_type', 10)->nullable()->after('temperature');
            $table->string('stage', 60)->nullable()->after('deal_type');
            $table->timestamp('stage_changed_at')->nullable()->after('stage');
        });

        // Backfill the pipeline type for existing leads. When a lead is linked
        // to an inventory unit we derive it from the unit's intent, otherwise we
        // assume leasing (the default deal type for this business).
        $saleLeadIds = DB::table('lead_property')
            ->join('properties', 'properties.id', '=', 'lead_property.property_id')
            ->where('properties.intent', 'sale')
            ->pluck('lead_property.lead_id')
            ->flip();

        DB::table('leads')
            ->whereNull('deal_type')
            ->orderBy('id')
            ->chunkById(500, function ($leads) use ($saleLeadIds) {
                foreach ($leads as $lead) {
                    DB::table('leads')
                        ->where('id', $lead->id)
                        ->update(['deal_type' => isset($saleLeadIds[$lead->id]) ? 'sale' : 'rent']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['deal_type', 'stage', 'stage_changed_at']);
        });
    }
};