<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('sub_community', 255);
            $table->string('community', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('map_url', 500)->nullable();
            $table->string('map_query', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'sub_community']);
        });

        // The location was previously stored per unit. It is really a property
        // of the building (sub_community), so collapse each building's rows into
        // one entry. All units of a building shared the same link, so MAX() is a
        // faithful pick; community/city are kept as reference for the editor.
        $rows = DB::table('properties')
            ->whereNotNull('sub_community')
            ->where('sub_community', '<>', '')
            ->where(function ($q) {
                $q->whereNotNull('map_url')->orWhereNotNull('map_query');
            })
            ->select('tenant_id', 'sub_community')
            ->selectRaw('MAX(map_url) as map_url')
            ->selectRaw('MAX(map_query) as map_query')
            ->selectRaw('MAX(community) as community')
            ->selectRaw('MAX(city) as city')
            ->groupBy('tenant_id', 'sub_community')
            ->get();

        foreach ($rows as $row) {
            DB::table('map_locations')->insert([
                'tenant_id' => $row->tenant_id,
                'sub_community' => $row->sub_community,
                'community' => $row->community,
                'city' => $row->city,
                'map_url' => $row->map_url,
                'map_query' => $row->map_query,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['map_url', 'map_query']);
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('map_url', 500)->nullable()->after('sub_community');
            $table->string('map_query', 255)->nullable()->after('map_url');
        });

        // Best-effort restore: re-stamp every unit of a building with its link.
        $locations = DB::table('map_locations')->get();
        foreach ($locations as $location) {
            DB::table('properties')
                ->where('tenant_id', $location->tenant_id)
                ->where('sub_community', $location->sub_community)
                ->update([
                    'map_url' => $location->map_url,
                    'map_query' => $location->map_query,
                ]);
        }

        Schema::dropIfExists('map_locations');
    }
};
