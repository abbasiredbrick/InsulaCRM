<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            // company | internal | external
            $table->string('participant_type')->default('internal');

            $table->string('participant_name')->nullable();
            $table->string('participant_email')->nullable();

            // Frozen snapshot of the calculation at close time.
            $table->decimal('gross_commission', 12, 2)->default(0);
            $table->decimal('share_pct', 5, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('funding_source')->nullable();
            $table->string('basis')->nullable(); // fixed | tiered | fixed_amount | support

            $table->string('status')->default('earned'); // earned | paid | void
            $table->timestamp('commissioned_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_commissions');
    }
};