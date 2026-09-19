<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            // External (A2A) collaborator fields — used when the co-agent is not
            // a CRM user yet. Internal co-agents leave these blank.
            $table->string('external_name')->nullable();
            $table->string('external_email')->nullable();
            $table->string('external_company')->nullable();

            // Commission sharing: the agreed % of the gross commission this
            // participant earns, and how that share is funded.
            $table->decimal('commission_pct', 5, 2)->nullable();
            $table->string('share_funding')->nullable(); // from_agent | from_company | from_both

            $table->string('status')->default('active'); // active | removed
            $table->timestamps();

            $table->unique(['lead_id', 'agent_id']);
            $table->unique(['lead_id', 'external_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_agents');
    }
};