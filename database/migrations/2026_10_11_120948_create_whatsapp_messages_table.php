<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('whatsapp_conversation_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // null = inbound from customer
            $table->enum('direction', ['inbound', 'outbound'])->index();
            $table->text('body')->nullable();
            $table->string('wamid')->nullable()->index(); // Meta message id
            $table->json('meta')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('whatsapp_conversation_id')->references('id')->on('whatsapp_conversations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
