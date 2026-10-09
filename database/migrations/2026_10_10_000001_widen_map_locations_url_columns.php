<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Google Maps "place" URL carries its pin/zoom/query in the path
     * (`/maps/place/…/@lat,lng,zoom/data=!3m…!4m…`) and routinely runs past
     * the old VARCHAR(500) — production rejected a Bloom Living Cordoba link
     * with "Data too long for column 'map_url'". The generated search query
     * (building, community, city) can likewise exceed VARCHAR(255). Both are
     * free text, never indexed, so TEXT is the honest type.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE map_locations MODIFY COLUMN map_url TEXT NULL, MODIFY COLUMN map_query TEXT NULL');
        } else {
            Schema::table('map_locations', function (Blueprint $table) {
                $table->text('map_url')->nullable()->change();
                $table->text('map_query')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE map_locations MODIFY COLUMN map_url VARCHAR(500) NULL, MODIFY COLUMN map_query VARCHAR(255) NULL');
        } else {
            Schema::table('map_locations', function (Blueprint $table) {
                $table->string('map_url', 500)->nullable()->change();
                $table->string('map_query', 255)->nullable()->change();
            });
        }
    }
};
