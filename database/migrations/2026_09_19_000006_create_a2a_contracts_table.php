<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2a_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('contract_number')->unique();
            $table->string('counterparty_name');
            $table->string('counterparty_email')->nullable();
            $table->string('counterparty_company')->nullable();
            $table->string('counterparty_address')->nullable();

            // The external agent's share of the creating agent's commission.
            $table->decimal('share_pct', 5, 2)->default(10);
            $table->string('funding_source')->nullable(); // from_agent | from_company | from_both

            $table->text('terms')->nullable();

            $table->string('status')->default('draft'); // draft | sent | signed | void
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_file_path')->nullable();
            $table->string('signed_original_name')->nullable();

            $table->timestamps();

            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a2a_contracts');
    }
};