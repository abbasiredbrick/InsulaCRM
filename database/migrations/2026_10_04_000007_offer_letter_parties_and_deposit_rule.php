<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Letterhead identity, occupant identity, the deposit rule, and who each
     * money line is actually paid to.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Which parts of the letterhead print. The logo already exists and is
            // uploaded in Settings; an agency that trades under a different legal
            // name wants one without the other.
            $table->string('letterhead_display', 10)->default('both');
        });

        Schema::table('offer_letters', function (Blueprint $table) {
            // The person the tenancy is for. Previously taken from the lead's
            // name, which is wrong the moment the signatory is a spouse or a
            // nominee rather than the enquiry itself.
            $table->string('occupant_name')->nullable()->after('lead_id');
            $table->string('emirates_id')->nullable()->after('occupant_name');

            // 'percent_5' recomputes the deposit as 5% of the contract value
            // whenever the discount changes; 'custom' keeps whatever was typed.
            $table->string('security_deposit_mode', 12)->default('percent_5');

            // Who collects each line. A landlord who wants the agency to collect
            // everything, and a landlord who takes rent and deposit directly and
            // only pays the commission over, are both normal.
            $table->string('rent_payable_to', 10)->default('landlord');
            $table->string('deposit_payable_to', 10)->default('landlord');
            $table->string('commission_payable_to', 10)->default('broker');
            $table->string('admin_fee_payable_to', 10)->default('broker');
            $table->string('contract_fee_payable_to', 10)->default('broker');
        });
    }

    public function down(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            $table->dropColumn([
                'occupant_name',
                'emirates_id',
                'security_deposit_mode',
                'rent_payable_to',
                'deposit_payable_to',
                'commission_payable_to',
                'admin_fee_payable_to',
                'contract_fee_payable_to',
            ]);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('letterhead_display');
        });
    }
};
