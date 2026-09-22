<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recycled_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('source', 30)->default('csv_import')->index(); // csv_import | auto_recycle
            $table->string('portal', 30)->nullable()->index();            // bayut | dubizzle | property_finder
            $table->string('reference', 100)->nullable()->index();        // original portal lead / listing reference
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('original_deal_type', 20)->nullable();         // rent | sale
            $table->string('purchased_project', 255)->nullable();         // development / project the buyer invested in
            $table->string('unit_no', 100)->nullable();
            $table->decimal('gross_price', 12, 2)->nullable();            // purchase / invested amount
            $table->date('expected_handover_date')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->text('call_notes')->nullable();
            $table->dateTime('last_contacted_at')->nullable();
            $table->dateTime('next_call_at')->nullable();
            $table->foreignId('assignee_id')->nullable()->index();
            $table->foreignId('original_lead_id')->nullable()->index();   // auto-recycled from this lead
            $table->foreignId('linked_lead_id')->nullable()->index();     // already-active lead it was matched to
            $table->foreignId('regenerated_lead_id')->nullable()->index(); // the lead born from regeneration
            $table->string('regeneration_intent', 40)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->json('raw_data')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('recycled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recycled_leads');
    }
};
