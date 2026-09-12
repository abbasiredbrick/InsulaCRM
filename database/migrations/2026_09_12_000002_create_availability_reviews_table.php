<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('availability_sources')->nullOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('availability_import_runs')->nullOnDelete();
            $table->string('reason', 30); // sheet_says_leased | missing_from_sheet
            $table->string('availability_before', 30)->default('listed');
            $table->string('status', 20)->default('pending'); // pending | keep_listed | unlist
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('availability_import_runs', function (Blueprint $table) {
            $table->unsignedInteger('conflict_rows')->default(0)->after('missing_rows');
        });
    }

    public function down(): void
    {
        Schema::table('availability_import_runs', function (Blueprint $table) {
            $table->dropColumn('conflict_rows');
        });

        Schema::dropIfExists('availability_reviews');
    }
};
