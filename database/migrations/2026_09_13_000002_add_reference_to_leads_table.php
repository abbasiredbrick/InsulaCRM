<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'reference')) {
                $table->string('reference')->nullable()->after('id');
                $table->unique(['tenant_id', 'reference']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'reference']);
            $table->dropColumn('reference');
        });
    }
};