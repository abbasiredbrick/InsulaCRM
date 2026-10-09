<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 255);
            $table->string('city', 255)->nullable();
            $table->timestamps();

            // Case-insensitive on the utf8mb4_unicode_ci collation in use by
            // the brokerage tables (MySQL); the service normalizes for SQLite.
            $table->unique(['tenant_id', 'name']);
        });

        // Backfill from the community strings already written on properties
        // and map_locations. Groups that differ only by case/whitespace
        // collapse into one row (the alphabetic-first spelling wins), which is
        // the same normalized-exact rule the service applies on re-import.
        $seen = [];
        $rows = DB::table('properties')
            ->whereNotNull('community')
            ->where('community', '<>', '')
            ->select('tenant_id', 'community')
            ->selectRaw('MAX(city) as city')
            ->groupBy('tenant_id', 'community')
            ->orderBy('tenant_id')
            ->orderBy('community')
            ->get();

        $rows = $rows->merge(DB::table('map_locations')
            ->whereNotNull('community')
            ->where('community', '<>', '')
            ->select('tenant_id', 'community')
            ->selectRaw('MAX(city) as city')
            ->groupBy('tenant_id', 'community')
            ->orderBy('tenant_id')
            ->orderBy('community')
            ->get());

        foreach ($rows as $row) {
            $name = trim((string) $row->community);
            $key = $row->tenant_id.'|'.mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            DB::table('communities')->insert([
                'tenant_id' => $row->tenant_id,
                'name' => $name,
                'city' => $row->city ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};