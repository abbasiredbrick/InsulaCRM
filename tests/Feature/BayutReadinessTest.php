<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\PropertyMedia;
use App\Services\Portals\BayutListingValidator;
use Tests\TestCase;

class BayutReadinessTest extends TestCase
{
    protected ?\App\Models\User $listingAgent = null;

    protected function listingAgent(): \App\Models\User
    {
        return $this->listingAgent ??= $this->createUserWithRole('agent', ['agent_code' => 'AD01']);
    }

    protected function completeProperty(array $overrides = []): Property
    {
        $skipPhotos = $overrides['skip_photos'] ?? false;
        unset($overrides['skip_photos']);

        $property = $this->createProperty(array_merge([
            'intent'                => 'rent',
            'property_category'     => 'apartment',
            'rera_permit_no'        => 'RERA-TEST-1',
            'marketing_title'       => 'Studyo Two Bedroom in Marina Gate',
            'marketing_description' => 'A bright two bedroom apartment overlooking the marina.',
            'rent_price'            => 120000,
            'rent_period'           => 'yearly',
            'community'             => 'Dubai Marina',
            'sub_community'         => 'Marina Gate',
            'address'               => 'Marina Walk, Street 1',
            'bedrooms'              => 2,
            'bathrooms'             => 2,
            'square_footage'        => 1200,
            'furnishing'            => 'furnished',
            'parking'               => 1,
            'availability'          => 'ready_to_list',
            'assigned_agent_id'     => $this->listingAgent()->id,
        ], $overrides));

        if (! $skipPhotos) {
            PropertyMedia::create([
                'tenant_id'     => $this->tenant->id,
                'property_id'   => $property->id,
                'type'          => 'photo',
                'external_url'  => 'https://images.example.test/marina-gate-1.jpg',
            ]);
        }

        return $property;
    }

    public function test_validator_reports_missing_fields(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $property = $this->createProperty([
            'tenant_id'     => $this->tenant->id,
            'availability'  => 'ready_to_list',
            'intent'        => null,
        ]);

        $missing = (new BayutListingValidator($this->tenant))->missing($property);
        $keys = array_column($missing, 'key');

        $this->assertContains('intent', $keys);
        $this->assertContains('property_category', $keys);
        $this->assertContains('rera_permit_no', $keys);
        $this->assertContains('marketing_title', $keys);
        $this->assertContains('marketing_description', $keys);
        $this->assertContains('photos', $keys);
        $this->assertContains('agent', $keys);
    }

    public function test_validator_is_ready_for_complete_unit(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $property = $this->completeProperty();

        $validator = new BayutListingValidator($this->tenant);

        $this->assertSame([], $validator->missing($property));
        $this->assertTrue($validator->isReady($property));
    }

    public function test_missing_photo_blocks_unit(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $property = $this->completeProperty(['skip_photos' => true]);

        $missing = (new BayutListingValidator($this->tenant))->missing($property);

        $this->assertContains('photos', array_column($missing, 'key'));
    }

    public function test_report_counts_ready_and_blocked(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->completeProperty();
        $this->completeProperty();
        $this->createProperty([
            'tenant_id'    => $this->tenant->id,
            'availability' => 'listed',
            'intent'       => null,
        ]);

        $report = (new BayutListingValidator($this->tenant))->report();

        $this->assertSame(3, $report['total']);
        $this->assertSame(2, $report['ready']);
        $this->assertSame(1, $report['blocked']);
        $this->assertArrayHasKey('photos', $report['miss_by_key']->all());
    }

    public function test_admin_can_view_readiness_page(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $blocked = $this->createProperty([
            'tenant_id'    => $this->tenant->id,
            'availability' => 'ready_to_list',
            'intent'       => null,
        ]);

        $this->get(route('listings.readiness'))
            ->assertOk()
            ->assertSee('Bayut Readiness')
            ->assertSee($blocked->display_name);
    }

    public function test_non_admin_cannot_view_readiness_page(): void
    {
        $this->actingAsRole('agent', ['business_mode' => 'realestate']);

        $this->get(route('listings.readiness'))->assertForbidden();
    }

    public function test_readiness_command_succeeds(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->completeProperty();

        $this->artisan('inventory:bayut-readiness', ['--tenant' => $this->tenant->id])
            ->assertSuccessful();
    }
}