<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // When the lead's status last changed. Drives the auto-recycle rule
            // (leads stuck in lost/dead/nurture for 60+ days move to the
            // Recycled Leads pool). Backfilled from updated_at for existing rows.
            $table->timestamp('status_changed_at')->nullable()->after('stage_changed_at');

            // Set once the lead is moved into the Recycled Leads pool. Recycled
            // leads are excluded from the live pipeline and reappear when they
            // are regenerated (recycled_at is cleared).
            $table->timestamp('recycled_at')->nullable()->after('status_changed_at');

            $table->index(['status', 'status_changed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['status', 'status_changed_at']);
            $table->dropColumn(['status_changed_at', 'recycled_at']);
        });
    }
};
