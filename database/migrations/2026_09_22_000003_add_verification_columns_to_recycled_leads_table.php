<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            // Contacts that should not be auto-worked until an agent reviews
            // them (masked / relay emails, portal "whatsapp.<n>@id.bayut.com"
            // placeholders, internal self-tests that slipped through).
            $table->boolean('needs_review')->default(false)->index()->after('status');
            $table->string('review_reason', 150)->nullable()->after('needs_review');
            $table->string('email_verification_status', 20)->default('unverified')->index()
                ->after('review_reason'); // unverified | verified | undeliverable | no_mx | unknown | review
            $table->dateTime('email_verified_at')->nullable()->after('email_verification_status');
            $table->datetime('email_verification_checked_at')->nullable()->after('email_verified_at');
            $table->string('email_verification_message', 250)->nullable()->after('email_verification_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->dropColumn([
                'needs_review',
                'review_reason',
                'email_verification_status',
                'email_verified_at',
                'email_verification_checked_at',
                'email_verification_message',
            ]);
        });
    }
};
