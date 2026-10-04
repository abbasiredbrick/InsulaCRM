<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tawtheeq → Contract Fee.
 *
 * "Tawtheeq" is the Abu Dhabi *registration* fee, which is a sales-side word.
 * The fee charged on a residential tenancy is the contract registration fee, and
 * the offer letter is a tenancy document, so the line was named after the wrong
 * instrument. Renaming the column rather than only the label keeps the money and
 * the VAT computation reading from one obvious name.
 *
 * properties.default_tawtheeq_fee is deliberately NOT renamed: it is an import
 * default for a source sheet's own column heading, which is spelled however the
 * PM spelled it, and renaming it would break every re-import.
 *
 * Data is copied rather than converted in place so a rolled-back migration
 * cannot lose the fee: add, copy, drop, and down() reverses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['properties', 'offer_letters'] as $table) {
            if (! Schema::hasColumn($table, 'tawtheeq_fee')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('contract_fee', 14, 2)->nullable()->after('admin_fee');
            });

            DB::table($table)
                ->whereNotNull('tawtheeq_fee')
                ->update(['contract_fee' => DB::raw('tawtheeq_fee')]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('tawtheeq_fee');
            });
        }
    }

    public function down(): void
    {
        foreach (['properties', 'offer_letters'] as $table) {
            if (! Schema::hasColumn($table, 'contract_fee')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('tawtheeq_fee', 14, 2)->nullable()->after('admin_fee');
            });

            DB::table($table)
                ->whereNotNull('contract_fee')
                ->update(['tawtheeq_fee' => DB::raw('contract_fee')]);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('contract_fee');
            });
        }
    }
};
