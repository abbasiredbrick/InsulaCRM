<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_media', function (Blueprint $table) {
            $table->string('caption')->nullable()->after('external_url');
            $table->string('original_name')->nullable()->after('caption');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('original_name');
            $table->string('mime_type')->nullable()->after('uploaded_by');
            $table->unsignedInteger('size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('property_media', function (Blueprint $table) {
            $table->dropColumn(['caption', 'original_name', 'uploaded_by', 'mime_type', 'size']);
        });
    }
};