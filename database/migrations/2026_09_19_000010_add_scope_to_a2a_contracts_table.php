<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('a2a_contracts', function (Blueprint $table) {
            // What the contract is tied to: a specific lead (and its property)
            // or a property we are sharing with the counterparty's client.
            $table->string('scope_type')->nullable()->after('terms'); // lead | property

            $table->foreignId('lead_id')->nullable()->after('scope_type')->constrained('leads')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->after('lead_id')->constrained('properties')->nullOnDelete();
            $table->string('transaction_type')->nullable()->after('property_id'); // sale | lease

            // Manager sign-off that activates the sharing: the external agent
            // only becomes an external co-agent once this is set.
            $table->timestamp('confirmed_at')->nullable()->after('signed_at');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();

            $table->index(['scope_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('a2a_contracts', function (Blueprint $table) {
            $table->dropIndex(['scope_type', 'status']);
            $table->dropForeign(['lead_id']);
            $table->dropForeign(['property_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn(['scope_type', 'lead_id', 'property_id', 'transaction_type', 'confirmed_at', 'confirmed_by']);
        });
    }
};