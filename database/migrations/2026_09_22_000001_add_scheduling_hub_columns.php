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
            if (! Schema::hasColumn('tasks', 'status')) {
                $table->string('status', 20)->default('scheduled')->after('is_completed');
                $table->index('status');
            }
        });

        DB::table('tasks')->where('is_completed', 1)->where('status', 'scheduled')->update(['status' => 'completed']);

        Schema::table('showings', function (Blueprint $table) {
            if (! Schema::hasColumn('showings', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('agent_id');
                $table->index('created_by');
            }
        });

        DB::statement('UPDATE showings SET created_by = agent_id WHERE created_by IS NULL');

        Schema::table('meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('meetings', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('agent_id');
                $table->index('created_by');
            }
        });

        DB::statement('UPDATE meetings SET created_by = agent_id WHERE created_by IS NULL');
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });

        Schema::table('showings', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
