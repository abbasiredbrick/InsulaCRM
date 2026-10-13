<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->date('permit_expires_at')->nullable()->after('rera_permit_no');
            // Fired reminder offsets (days-before) for the current expiry date,
            // e.g. [7, 3, 1]. Reset when the date changes so a re-issued permit
            // starts a fresh reminder cycle.
            $table->json('permit_reminders_sent')->nullable()->after('permit_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['permit_expires_at', 'permit_reminders_sent']);
        });
    }
};
