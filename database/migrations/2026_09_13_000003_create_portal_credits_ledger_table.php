<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_credits_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('portal', 30)->default('bayut');
            $table->string('type', 30); // initial, adjust, publish, refund
            $table->integer('amount'); // positive = credit, negative = debit
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_credits_ledger');
    }
};