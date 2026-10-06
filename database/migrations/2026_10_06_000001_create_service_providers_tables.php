<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_provider_registration_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('provider_email', 190)->nullable();
            $table->string('provider_name', 120)->nullable();
            $table->string('internal_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('service_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('category', 40)->index();
            $table->string('company_name', 120);
            $table->string('trade_license_number', 60);
            $table->text('services_offered')->nullable();
            $table->string('representative_name', 120);
            $table->string('representative_emirates_id', 30);
            $table->string('email', 190);
            $table->string('mobile', 30);
            $table->string('city', 120)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('edit_token', 64)->unique();
            $table->foreignId('registration_link_id')->nullable()->constrained('service_provider_registration_links')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('service_provider_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 30);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('service_provider_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_providers');
        Schema::dropIfExists('service_provider_documents');
        Schema::dropIfExists('service_provider_reviews');
        Schema::dropIfExists('service_provider_registration_links');
    }
};