<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            if (! Schema::hasColumn('activities', 'subject_type')) {
                $table->string('subject_type', 120)->nullable()->after('deal_id');
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
                $table->index(['subject_type', 'subject_id'], 'activities_subject_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_subject_index');
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
