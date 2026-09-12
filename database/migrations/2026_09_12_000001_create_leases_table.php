<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('unit_address')->nullable();
            $table->string('community')->nullable();
            $table->string('unit_no')->nullable();

            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date');
            $table->decimal('rent_price', 12, 2)->nullable();
            $table->decimal('admin_fee', 12, 2)->nullable();

            $table->string('status', 20)->default('active');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contract_end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
