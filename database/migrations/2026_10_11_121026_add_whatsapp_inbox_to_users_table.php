<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('whatsapp_inbox_enabled')->default(false)->after('whatsapp_opt_in');
            $table->boolean('whatsapp_inbox_admin')->default(false)->after('whatsapp_inbox_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_inbox_enabled', 'whatsapp_inbox_admin']);
        });
    }
};
