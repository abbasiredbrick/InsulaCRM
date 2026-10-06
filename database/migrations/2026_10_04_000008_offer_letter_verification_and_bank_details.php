<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Company assets printed on the letter, plus the public verification token.
     *
     * Signature and stamp are the agency's own authorised signatory and seal —
     * "tenant" throughout this app is the brokerage, not the client — so they are
     * company-wide settings rather than per-letter uploads. They are only printed
     * once a letter is approved; see OfferLetter::isApproved().
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Transparent PNG of the authorised signatory's signature.
            $table->string('signature_path')->nullable()->after('logo_path');
            // The company seal, same idea.
            $table->string('stamp_path')->nullable()->after('signature_path');
            // The tenant's bank/IBAN letter, shown as a final page.
            $table->string('iban_letter_path')->nullable()->after('stamp_path');
            // Typed fallback for agencies with no scanned IBAN letter: free text
            // holding account name, bank, IBAN, SWIFT and account number.
            $table->text('bank_details')->nullable()->after('iban_letter_path');
        });

        Schema::table('offer_letters', function (Blueprint $table) {
            // Unguessable, and carried in a signed URL so the QR cannot be
            // repointed at a different offer.
            $table->uuid('verification_token')->nullable()->after('offer_no');
            // 'upload' prints the tenant's IBAN letter as a final page,
            // 'details' prints typed bank details, 'none' prints neither.
            $table->string('bank_details_source', 10)->default('details');
            // Per-letter override of the tenant's typed bank details.
            $table->text('bank_details')->nullable();

            $table->index('verification_token');
        });

        // Backfill so every existing letter can be verified straight away. Without
        // a token the QR on an old printed letter would have nothing to point at,
        // and these rows can no longer be reached through any other route.
        DB::table('offer_letters')
            ->whereNull('verification_token')
            ->orderBy('id')
            ->each(function ($letter) {
                DB::table('offer_letters')
                    ->where('id', $letter->id)
                    ->update(['verification_token' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            $table->dropIndex(['verification_token']);
            $table->dropColumn(['verification_token', 'bank_details_source', 'bank_details']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'stamp_path', 'iban_letter_path', 'bank_details']);
        });
    }
};
