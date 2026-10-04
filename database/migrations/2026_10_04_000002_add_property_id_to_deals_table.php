<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The unit a deal is about.
 *
 * A lease offer is made on one specific unit, which is regularly NOT the unit on
 * the lead — a client views five units and asks for an offer on the second. The
 * trigger for the deal is a viewing feedback that says "client requested an
 * offer", so the viewing's property is the real unit and the lead's property is
 * only a hint. Without this column "which unit did they sign?" is unanswerable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('deals', 'property_id')) {
            return;
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('lease_id')
                ->constrained('properties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('deals', 'property_id')) {
            return;
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
