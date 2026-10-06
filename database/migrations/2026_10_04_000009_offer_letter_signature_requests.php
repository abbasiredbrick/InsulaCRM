<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets an occupant sign an approved offer letter from their phone.
     *
     * The signing token is deliberately separate from `verification_token` even
     * though both point at the same letter, because the two have opposite
     * lifecycles:
     *
     * - The verification token is a permanent, shareable proof that we issued
     *   the document. It must outlive every state change.
     * - The signing token is a live capability. It is rotated on every resend so
     *   a link that leaked from an old email stops working, and it is revoked
     *   outright if the letter is withdrawn or its terms change.
     *
     * Sharing one token would mean a verification link could also sign, and an
     * old email would keep a signing right alive forever.
     */
    public function up(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            // --- the request: who was asked, by whom, and until when ---
            $table->uuid('signature_request_token')->nullable()->after('verification_token');
            $table->timestamp('signature_requested_at')->nullable()->after('signature_request_token');
            // nullOnDelete, not cascade: the record of who asked for a signature
            // must survive that agent being deleted, otherwise a signed letter
            // loses its provenance entirely.
            $table->foreignId('signature_requested_by')->nullable()
                ->constrained('users')->nullOnDelete()
                ->after('signature_requested_at');
            $table->timestamp('signature_request_expires_at')->nullable()->after('signature_requested_by');
            $table->timestamp('signature_reminded_at')->nullable()->after('signature_request_expires_at');
            $table->string('signature_request_email')->nullable()->after('signature_reminded_at');

            // --- the captured signature ---
            // 'drawn' is a canvas capture posted as a data URL; 'uploaded' is a
            // photo of a wet-signed copy or a PDF from another e-sign tool.
            $table->string('occupant_signature_method', 10)->nullable()->after('signature_request_email');
            $table->string('occupant_signature_path')->nullable()->after('occupant_signature_method');
            $table->string('occupant_signer_name')->nullable()->after('occupant_signature_path');
            $table->timestamp('occupant_signed_at')->nullable()->after('occupant_signer_name');
            $table->string('occupant_signed_ip', 45)->nullable()->after('occupant_signed_at');
            $table->string('occupant_signed_ua', 255)->nullable()->after('occupant_signed_ip');

            // SHA-256 of the letter exactly as it was signed. The letter is frozen
            // after approval, but its *rendering* is not: changing the company
            // logo or bank details would silently alter what we produce later.
            // This records which document the occupant actually agreed to.
            $table->string('occupant_content_hash', 64)->nullable()->after('occupant_signed_ua');

            $table->index('signature_request_token');
        });
    }

    public function down(): void
    {
        Schema::table('offer_letters', function (Blueprint $table) {
            $table->dropForeign(['signature_requested_by']);
            $table->dropIndex(['signature_request_token']);
            $table->dropColumn([
                'signature_request_token',
                'signature_requested_at',
                'signature_requested_by',
                'signature_request_expires_at',
                'signature_reminded_at',
                'signature_request_email',
                'occupant_signature_method',
                'occupant_signature_path',
                'occupant_signer_name',
                'occupant_signed_at',
                'occupant_signed_ip',
                'occupant_signed_ua',
                'occupant_content_hash',
            ]);
        });
    }
};
