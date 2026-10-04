<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The contract period in whole years, which is what drives the end date.
     *
     * This is stored rather than derived because the end date is a real field an
     * agent types (a tenancy can end part-way through a term) and re-deriving the
     * term from the two dates makes "how many years was this?" unanswerable once
     * someone has overridden the end date.
     */
    public function up(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            $table->unsignedTinyInteger('contract_years')->default(1)->after('contract_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            $table->dropColumn('contract_years');
        });
    }
};
