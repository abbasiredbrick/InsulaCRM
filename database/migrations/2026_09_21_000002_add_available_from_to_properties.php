<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A dedicated "available from" date for units that are not vacant yet
        // (e.g. Relevate's "Upcoming" units with an expected vacating date),
        // kept separate from handover_date which is reserved for off-plan keys.
        Schema::table('properties', function (Blueprint $table) {
            $table->date('available_from')->nullable()->after('handover_date');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('available_from');
        });
    }
};
