<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            // WhatsApp usernames (the handle-based contact route WhatsApp now
            // offers alongside phone numbers). Kept as its own field so a lead
            // reachable only by username is still actionable.
            $table->string('whatsapp_username')->nullable()->after('phone');

            // Portal-hosted call recording for a phone enquiry, playable in the
            // pool. The provider URL is a long presigned link, hence text.
            $table->text('call_recording_url')->nullable()->after('call_notes');
        });
    }

    public function down(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_username', 'call_recording_url']);
        });
    }
};
