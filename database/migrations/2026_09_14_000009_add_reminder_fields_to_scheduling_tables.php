<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $table) {
            if (! Schema::hasColumn($table, 'reminder_minutes')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->unsignedSmallInteger('reminder_minutes')->nullable();
                    $table->timestamp('reminder_sent_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['reminder_minutes', 'reminder_sent_at']);
            });
        }
    }

    private function tables(): array
    {
        return ['showings', 'meetings', 'tasks', 'open_houses'];
    }
};
