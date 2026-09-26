<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            // How the lead first contacted us (WhatsApp / Phone / Email) —
            // Bayut report exports ship one log per channel, so a lead's
            // category is the channel whose file the row came from.
            $table->string('category', 20)->nullable()->index()->after('portal');
        });
    }

    public function down(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
