<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standalone inventory units are not created from a lead, so the
        // foreign key must be nullable.
        $connection = Schema::getConnection()->getDriverName();
        if ($connection === 'mysql') {
            DB::statement('ALTER TABLE properties MODIFY lead_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('properties', function (Blueprint $table) {
                $table->foreignId('lead_id')->nullable()->change();
            });
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->string('intent', 10)->nullable()->after('listing_status'); // rent | sale | both
            $table->string('market_class', 15)->nullable()->after('intent'); // ready | off_plan
            $table->string('property_category', 40)->nullable()->after('market_class');
            $table->string('community', 120)->nullable()->after('property_category');
            $table->string('sub_community', 120)->nullable()->after('community');
            $table->string('developer_name', 150)->nullable()->after('sub_community');
            $table->date('handover_date')->nullable()->after('developer_name');
            $table->string('title_deed_no', 60)->nullable()->after('handover_date');
            $table->string('rera_permit_no', 60)->nullable()->after('title_deed_no');
            $table->string('plot_no', 60)->nullable()->after('rera_permit_no');
            $table->string('building_no', 60)->nullable()->after('plot_no');
            $table->string('unit_no', 60)->nullable()->after('building_no');
            $table->string('floor_no', 60)->nullable()->after('unit_no');

            $table->decimal('rent_price', 12, 2)->nullable()->after('list_price');
            $table->string('rent_period', 20)->default('yearly')->after('rent_price'); // yearly | monthly
            $table->decimal('service_charge', 12, 2)->nullable()->after('rent_period');
            $table->string('furnishing', 20)->nullable()->after('service_charge'); // unfurnished | semi_furnished | furnished
            $table->integer('parking')->nullable()->after('furnishing');

            $table->string('availability', 20)->default('draft')->after('parking'); // draft | ready_to_list | listed | reserved | leased | sold | unlisted
            $table->foreignId('assigned_agent_id')->nullable()->after('availability')->constrained('users')->nullOnDelete();

            $table->string('owner_name', 150)->nullable()->after('notes');
            $table->string('owner_phone', 30)->nullable()->after('owner_name');
            $table->string('owner_email', 190)->nullable()->after('owner_phone');

            $table->string('marketing_title', 200)->nullable()->after('owner_email');
            $table->text('marketing_description')->nullable()->after('marketing_title');
            $table->string('virtual_tour_url', 500)->nullable()->after('marketing_description');

            $table->string('bayut_status', 20)->default('not_listed')->after('virtual_tour_url'); // not_listed | live | removed
            $table->string('bayut_listing_id', 60)->nullable()->after('bayut_status');
            $table->string('bayut_url', 500)->nullable()->after('bayut_listing_id');
            $table->date('bayut_listed_at')->nullable()->after('bayut_url');

            $table->string('dubizzle_status', 20)->default('not_listed')->after('bayut_listed_at'); // not_listed | live | removed
            $table->string('dubizzle_listing_reference', 60)->nullable()->after('dubizzle_status');
            $table->string('dubizzle_url', 500)->nullable()->after('dubizzle_listing_reference');
            $table->date('dubizzle_listed_at')->nullable()->after('dubizzle_url');

            $table->string('propertyfinder_status', 20)->default('not_listed')->after('dubizzle_listed_at'); // not_listed | live | removed
            $table->string('propertyfinder_listing_reference', 60)->nullable()->after('propertyfinder_status');
            $table->string('propertyfinder_url', 500)->nullable()->after('propertyfinder_listing_reference');
            $table->date('propertyfinder_listed_at')->nullable()->after('propertyfinder_url');

            $table->index(['intent', 'availability']);
            $table->index('property_category');
            $table->index('assigned_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'intent', 'market_class', 'property_category', 'community', 'sub_community',
                'developer_name', 'handover_date', 'title_deed_no', 'rera_permit_no',
                'plot_no', 'building_no', 'unit_no', 'floor_no',
                'rent_price', 'rent_period', 'service_charge', 'furnishing', 'parking',
                'availability', 'assigned_agent_id',
                'owner_name', 'owner_phone', 'owner_email',
                'marketing_title', 'marketing_description', 'virtual_tour_url',
                'bayut_status', 'bayut_listing_id', 'bayut_url', 'bayut_listed_at',
                'dubizzle_status', 'dubizzle_listing_reference', 'dubizzle_url', 'dubizzle_listed_at',
                'propertyfinder_status', 'propertyfinder_listing_reference', 'propertyfinder_url', 'propertyfinder_listed_at',
            ]);
        });
    }
};