<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Gross commission agreed for this lead (AED). Set from the closed
            // deal's total_commission and editable by admins before the split is
            // calculated and snapshotted into lead_commissions.
            $table->decimal('commission_amount', 12, 2)->nullable()->after('motivation_score');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('commission_amount');
        });
    }
};