<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            // One contact, both intents. Exports split a single person's rental
            // and sale enquiries across rows, so the first row's type used to be
            // the only one kept and the other was dropped silently. This holds
            // the second type ("rent" for a sale row, "sale" for a rental row)
            // so the pool shows "Rent + Sale" and regeneration can pick either.
            $table->string('alternate_deal_type', 20)->nullable()->index()->after('original_deal_type');
        });
    }

    public function down(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->dropColumn('alternate_deal_type');
        });
    }
};
