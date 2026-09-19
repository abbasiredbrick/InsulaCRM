<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_compensation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // How the company/agent split is computed:
            //   fixed  → company_pct / agent_pct as agreed (e.g. 40/60)
            //   tiered → agent share comes from the tenant's tier schedule keyed
            //            on the total commission amount.
            $table->string('split_type')->default('fixed');

            $table->decimal('company_pct', 5, 2)->nullable();
            $table->decimal('agent_pct', 5, 2)->nullable();

            // Payment structure:
            //   commission_only       → agent is paid purely on commission
            //   salary_plus_commission→ receives a base salary AND commission
            //   fixed_amount          → salaried listing/cold-call agent paid a
            //                           fixed amount per closed lead
            $table->string('pay_structure')->default('commission_only');

            $table->decimal('base_salary', 12, 2)->nullable();
            $table->decimal('fixed_amount_per_close', 12, 2)->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_compensation');
    }
};