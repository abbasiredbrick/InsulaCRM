<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            $table->string('offer_no', 50)->nullable();
            $table->string('status', 30)->default('draft');
            $table->dateTime('issued_at')->nullable();
            $table->date('valid_until')->nullable();

            // Tenure
            $table->date('contract_start_date')->nullable();
            $table->date('contract_end_date')->nullable();
            $table->string('payment_period', 100)->nullable();
            $table->text('documents_required')->nullable();

            // Money
            $table->decimal('original_amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('approved_amount', 14, 2)->default(0);
            $table->foreignId('discount_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('discount_approved_at')->nullable();

            // Commission
            $table->decimal('commission_rate_pct', 5, 2)->default(0);
            $table->decimal('commission_vat_pct', 5, 2)->default(0);
            $table->decimal('commission_amount', 14, 2)->default(0);
            $table->decimal('commission_vat', 14, 2)->default(0);
            $table->decimal('commission_total', 14, 2)->default(0);

            // Fees
            $table->decimal('security_deposit', 14, 2)->nullable();
            $table->decimal('admin_fee', 14, 2)->nullable();
            $table->decimal('tawtheeq_fee', 14, 2)->nullable();

            // Signing
            $table->string('signed_pdf_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'deal_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letters');
    }
};
