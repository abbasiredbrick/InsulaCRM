<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unit owners (landlords) as a tenant master, so the same person is not
     * re-typed on every unit. Mirrors the map_locations master: one row per
     * owner, units point at it, and the office location is the owner's place
     * of business (a pinned Google Maps link, used for directions/Waze).
     *
     * There is intentionally no unique constraint on the name: two owners can
     * legitimately share a name, so de-dup is normalized-exact in
     * OwnerService (name first, phone as the tie-breaker), the same human-in-
     * the-loop approach as buildings/communities.
     */
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name', 255);
            $table->string('phone', 50)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('office_address', 500)->nullable();
            $table->text('map_url')->nullable();
            $table->string('map_query', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owners');
    }
};
