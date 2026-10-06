<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_letter_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_letter_id')->constrained()->cascadeOnDelete();

            // Who asked, and why. The reason is the whole point: the manager is
            // approving a described change, not clicking through a form they
            // cannot see the effect of.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');

            // pending -> approved | rejected. Only one request per letter is
            // meaningful at a time, but rejected ones are kept as history, so
            // the index is on status rather than unique on letter+status.
            $table->string('status', 20)->default('pending');
            $table->index(['offer_letter_id', 'status']);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_letter_change_requests');
    }
};
