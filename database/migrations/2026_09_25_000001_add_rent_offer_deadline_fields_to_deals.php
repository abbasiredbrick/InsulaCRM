<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->date('offer_sent_date')->nullable()->after('due_diligence_end_date');
            $table->date('offer_signed_date')->nullable()->after('offer_sent_date');
            $table->unsignedTinyInteger('offer_validity_days')->nullable()->default(7)->after('offer_signed_date');
            $table->unsignedTinyInteger('registration_deadline_days')->nullable()->default(7)->after('offer_validity_days');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'offer_sent_date',
                'offer_signed_date',
                'offer_validity_days',
                'registration_deadline_days',
            ]);
        });
    }
};
