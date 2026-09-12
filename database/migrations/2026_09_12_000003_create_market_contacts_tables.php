<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('landlords'); // landlords | investors
            $table->string('filename');
            $table->string('format', 10)->default('csv');       // xlsx | csv | txt
            $table->string('status', 20)->default('completed'); // completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });

        Schema::create('market_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('market_imports')->nullOnDelete();

            $table->string('type', 20)->default('landlord');    // landlord | investor
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();

            // Unit details supplied for the exact owner/buildings.
            $table->string('unit_no')->nullable();
            $table->string('building')->nullable();
            $table->string('address')->nullable();
            $table->string('community')->nullable();
            $table->string('property_category', 40)->nullable();
            $table->integer('bedrooms')->nullable();
            $table->integer('bathrooms')->nullable();
            $table->decimal('rent_price', 12, 2)->nullable();
            $table->string('unit_status', 40)->nullable();       // available / leased / sold as listed

            // Investor intentions.
            $table->decimal('budget', 14, 2)->nullable();
            $table->string('preferred_type', 100)->nullable();
            $table->text('requirements')->nullable();

            // Cold call workflow.
            $table->string('status', 30)->default('pending');
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_called_at')->nullable();
            $table->text('call_notes')->nullable();

            // Conversion targets.
            $table->string('converted_type', 20)->nullable();   // property | lead
            $table->unsignedBigInteger('converted_id')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'called_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_contacts');
        Schema::dropIfExists('market_imports');
    }
};
