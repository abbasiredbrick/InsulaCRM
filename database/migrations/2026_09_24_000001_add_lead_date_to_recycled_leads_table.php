<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            // The date the portal lead enquiry came in (Bayut "Date" column) —
            // drives date-range search and the bulk assign-to-regenerate flow.
            $table->date('lead_date')->nullable()->index()->after('email_verification_message');
        });
    }

    public function down(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->dropColumn('lead_date');
        });
    }
};
