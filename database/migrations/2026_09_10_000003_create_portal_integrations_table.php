<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('portal', 20); // bayut | propertyfinder
            $table->boolean('is_active')->default(false);

            $table->text('api_token')->nullable();       // Bayut bearer token / Property Finder API key (encrypted)
            $table->text('api_secret')->nullable();      // Property Finder API secret (encrypted)
            $table->string('base_url', 255)->nullable(); // portal base URL for this agency account
            $table->string('agent_reference', 100)->nullable();
            $table->string('public_profile_id', 100)->nullable();
            $table->string('default_location_id', 100)->nullable();

            $table->text('webhook_secret')->nullable();  // shared secret used to verify inbound lead pushes

            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'portal']);
            $table->index(['portal', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_integrations');
    }
};