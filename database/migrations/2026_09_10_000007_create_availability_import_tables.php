<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('contact_info', 255)->nullable();
            $table->string('default_building', 150)->nullable();
            $table->string('default_category', 40)->nullable();
            $table->string('default_city', 100)->nullable();
            $table->json('column_map')->nullable();
            $table->json('parse_options')->nullable();
            $table->json('status_map')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
        });

        Schema::create('availability_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('availability_sources')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename', 255)->nullable();
            $table->string('format', 20)->default('csv'); // csv | xlsx | text
            $table->string('status', 20)->default('pending'); // pending | processing | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('missing_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->text('notes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['source_id', 'status']);
        });

        $connection = Schema::getConnection()->getDriverName();
        if ($connection !== 'mysql') {
            Schema::table('properties', function (Blueprint $table) {
                $table->foreignId('availability_source_id')->nullable()
                    ->after('notes')->constrained('availability_sources')->nullOnDelete();
                $table->string('source_unit_ref', 60)->nullable()->after('availability_source_id');
                $table->date('availability_synced_at')->nullable()->after('source_unit_ref');
            });

            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('availability_source_id')->nullable()->after('notes');
            $table->string('source_unit_ref', 60)->nullable()->after('availability_source_id');
            $table->date('availability_synced_at')->nullable()->after('source_unit_ref');
        });

        DB::statement('ALTER TABLE properties ADD CONSTRAINT properties_availability_source_id_foreign FOREIGN KEY (availability_source_id) REFERENCES availability_sources(id) ON DELETE SET NULL');

        Schema::table('properties', function (Blueprint $table) {
            $table->index('source_unit_ref');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['availability_source_id']);
            $table->dropColumn(['availability_source_id', 'source_unit_ref', 'availability_synced_at']);
        });
        Schema::dropIfExists('availability_import_runs');
        Schema::dropIfExists('availability_sources');
    }
};