<?php

use App\Services\ContactNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->string('normalized_phone', 32)->nullable()->after('phone');
            $table->string('normalized_email', 150)->nullable()->after('email');
            $table->index(['tenant_id', 'normalized_phone']);
            $table->index(['tenant_id', 'normalized_email']);
        });

        $countries = DB::table('tenants')->pluck('country', 'id');
        $normalizer = new ContactNormalizer;

        DB::table('recycled_leads')->orderBy('id')->chunkById(500, function ($rows) use ($countries, $normalizer) {
            foreach ($rows as $row) {
                DB::table('recycled_leads')->where('id', $row->id)->update([
                    'normalized_phone' => $normalizer->phone($row->phone, $countries->get($row->tenant_id)),
                    'normalized_email' => $normalizer->email($row->email),
                ]);
            }
        });

        Schema::create('recycled_portal_import_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('portal_integration_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('portal', 30)->index();
            $table->string('mode', 20)->default('preview');
            $table->string('status', 40)->default('queued')->index();
            $table->json('criteria');
            $table->json('cursor')->nullable();
            $table->json('preview_counts')->nullable();
            $table->unsignedInteger('observed_count')->default(0);
            $table->unsignedInteger('out_of_range_count')->default(0);
            $table->unsignedInteger('skipped_invalid_count')->default(0);
            $table->unsignedInteger('skipped_no_contact_count')->default(0);
            $table->unsignedInteger('contactable_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('already_active_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('enriched_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recycled_lead_source_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('recycled_lead_id')->index();
            $table->unsignedBigInteger('import_run_id')->index();
            $table->string('portal', 30)->index();
            $table->string('external_id', 191)->nullable();
            $table->string('external_id_hash', 64);
            $table->string('provider_type', 30)->nullable();
            $table->string('provider_target', 50)->nullable();
            $table->string('provider_status', 50)->nullable();
            $table->dateTime('event_at')->nullable()->index();
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'portal', 'external_id_hash'], 'recycled_source_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recycled_lead_source_events');
        Schema::dropIfExists('recycled_portal_import_runs');

        Schema::table('recycled_leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'normalized_phone']);
            $table->dropIndex(['tenant_id', 'normalized_email']);
            $table->dropColumn(['normalized_phone', 'normalized_email']);
        });
    }
};
