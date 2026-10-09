<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function normalize(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strtolower($name);
    }

    public function up(): void
    {
        Schema::table('map_locations', function (Blueprint $table) {
            $table->foreignId('community_id')
                ->nullable()
                ->after('city')
                ->constrained('communities')
                ->nullOnDelete();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('map_location_id')
                ->nullable()
                ->after('sub_community')
                ->constrained('map_locations')
                ->nullOnDelete();
        });

        // ── Link communities to buildings ────────────────────────────────
        $communities = DB::table('communities')->get(['id', 'tenant_id', 'name']);
        $byNormalized = [];
        foreach ($communities as $community) {
            $byNormalized[$community->tenant_id.'|'.$this->normalize($community->name)] = $community->id;
        }

        foreach (DB::table('map_locations')->whereNull('community_id')->get(['id', 'tenant_id', 'community']) as $location) {
            if ($location->community === null || $location->community === '') {
                continue;
            }
            $id = $byNormalized[$location->tenant_id.'|'.$this->normalize($location->community)] ?? null;
            if ($id !== null) {
                DB::table('map_locations')->where('id', $location->id)->update(['community_id' => $id]);
            }
        }

        // ── Link every building to its map location, creating rows for the
        //    buildings import never saw (so the FK is the single source of
        //    truth, and the review screen lists the whole inventory).
        $locations = DB::table('map_locations')->get(['id', 'tenant_id', 'sub_community']);
        $byBuilding = [];
        foreach ($locations as $location) {
            $byBuilding[$location->tenant_id.'|'.$this->normalize($location->sub_community)] = $location->id;
        }

        $buildings = DB::table('properties')
            ->whereNotNull('sub_community')
            ->where('sub_community', '<>', '')
            ->select('tenant_id', 'sub_community')
            ->selectRaw('MAX(community) as community')
            ->selectRaw('MAX(city) as city')
            ->groupBy('tenant_id', 'sub_community')
            ->orderBy('tenant_id')
            ->orderBy('sub_community')
            ->get();

        foreach ($buildings as $building) {
            $locationId = $byBuilding[$building->tenant_id.'|'.$this->normalize($building->sub_community)] ?? null;

            if ($locationId === null) {
                $locationId = DB::table('map_locations')->insertGetId([
                    'tenant_id' => $building->tenant_id,
                    'sub_community' => $building->sub_community,
                    'community' => $building->community ?: null,
                    'city' => $building->city ?: null,
                    'map_url' => null,
                    'map_query' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $byBuilding[$building->tenant_id.'|'.$this->normalize($building->sub_community)] = $locationId;
            }

            DB::table('properties')
                ->where('tenant_id', $building->tenant_id)
                ->where('sub_community', $building->sub_community)
                ->update(['map_location_id' => $locationId]);
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('map_location_id');
        });

        Schema::table('map_locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('community_id');
        });
    }
};