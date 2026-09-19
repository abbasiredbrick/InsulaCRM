<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('agent_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('tasks', 'due_time')) {
                $table->time('due_time')->nullable()->after('due_date');
            }
        });

        DB::table('tasks')->whereNull('created_by')->update(['created_by' => DB::raw('agent_id')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'due_time')) {
                $table->dropColumn('due_time');
            }
            if (Schema::hasColumn('tasks', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};