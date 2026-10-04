<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAT registration, and the fee lines VAT applies to.
 *
 * Two facts drive this:
 *
 *  1. VAT only exists if the company is registered for it. This was a free-text
 *     box on the offer letter form, so it was whatever someone last typed, and a
 *     not-registered brokerage could quietly add 5% to a client's invoice. The
 *     switch moves to the tenant, where it is a fact about the company rather
 *     than a per-letter guess, and the rate comes from the tenant's own
 *     commission settings rather than the form.
 *
 *  2. VAT attaches to the *services* — agency commission, admin fee and contract
 *     fee — and never to the residential lease or sale value itself. So admin fee
 *     and contract fee get their own VAT and total columns; contract value gets
 *     none, deliberately, and there is no column here that would let it acquire one.
 *
 * Defaults to NOT registered: charging VAT that the company cannot reclaim, or
 * cannot charge at all, is worse than under-charging, and the tenant opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_vat_registered')->default(false)->after('currency');
        });

        Schema::table('offer_letters', function (Blueprint $blueprint) {
            // 'percentage' of the contract value, or a stated 'value'.
            $blueprint->string('commission_basis', 20)->default('percentage')->after('commission_rate_pct');

            $blueprint->decimal('admin_fee_vat', 14, 2)->nullable()->after('admin_fee');
            $blueprint->decimal('admin_fee_total', 14, 2)->nullable()->after('admin_fee_vat');

            $blueprint->decimal('contract_fee_vat', 14, 2)->nullable()->after('contract_fee');
            $blueprint->decimal('contract_fee_total', 14, 2)->nullable()->after('contract_fee_vat');
        });

        // Existing letters were priced with VAT shown but no per-line breakdown,
        // and every one of them was written before this column existed. Keep the
        // rate the tenant already used so old letters still render.
        DB::table('offer_letters')->whereNull('commission_basis')->update(['commission_basis' => 'percentage']);
    }

    public function down(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            $table->dropColumn([
                'commission_basis',
                'admin_fee_vat',
                'admin_fee_total',
                'contract_fee_vat',
                'contract_fee_total',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('is_vat_registered');
        });
    }
};
