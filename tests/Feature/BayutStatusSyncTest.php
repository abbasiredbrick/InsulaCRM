<?php

namespace Tests\Feature;

use App\Models\PortalIntegration;
use App\Models\Property;
use App\Services\Portals\BayutStatusSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BayutStatusSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function createIntegration(array $overrides = []): PortalIntegration
    {
        return PortalIntegration::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'portal'    => 'bayut',
            'is_active' => true,
            'base_url'  => 'https://push.bayut.example',
            'api_token' => 'bayut-token',
        ], $overrides));
    }

    protected function listedUnit(array $overrides = []): Property
    {
        return $this->createProperty(array_merge([
            'intent'            => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no'    => 'RERA-SY-1',
            'availability'      => 'listed',
            'bayut_listing_id'  => 'AD01-77',
            'bayut_status'      => 'not_listed',
        ], $overrides));
    }

    public function test_service_marks_units_live_when_listed_remotely(): void
    {
        $this->createIntegration();
        $unit = $this->listedUnit();

        Http::fake([
            'push.bayut.example/listings' => Http::response([
                ['reference' => 'AD01-77', 'status' => 'live', 'url' => 'https://www.bayut.com/unit-77'],
            ], 200),
        ]);

        $result = (new BayutStatusSyncService(PortalIntegration::where('portal', 'bayut')->firstOrFail()))->sync();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['live']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['removed']);
        $this->assertNull($result['error']);

        $unit->refresh();
        $this->assertSame('live', $unit->bayut_status);
        $this->assertSame('live', $unit->dubizzle_status);
        $this->assertSame('AD01-77', $unit->dubizzle_listing_reference);
        $this->assertSame('https://www.bayut.com/unit-77', $unit->bayut_url);
        $this->assertNotNull($unit->bayut_listed_at);

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'model_id'  => $unit->id,
            'action'    => 'inventory.portal_status_synced_bayut',
        ]);
    }

    public function test_service_marks_removed_when_missing_from_portal(): void
    {
        $this->createIntegration();
        $unit = $this->listedUnit(['bayut_status' => 'live', 'dubizzle_status' => 'live']);

        Http::fake([
            'push.bayut.example/listings' => Http::response([], 200),
        ]);

        $result = (new BayutStatusSyncService(PortalIntegration::where('portal', 'bayut')->firstOrFail()))->sync();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['live']);
        $this->assertSame(1, $result['removed']);

        $unit->refresh();
        $this->assertSame('removed', $unit->bayut_status);
        $this->assertSame('removed', $unit->dubizzle_status);
    }

    public function test_service_marks_removed_when_status_is_not_live(): void
    {
        $this->createIntegration();
        $unit = $this->listedUnit(['bayut_status' => 'live', 'dubizzle_status' => 'live']);

        Http::fake([
            'push.bayut.example/listings' => Http::response([
                ['reference' => 'AD01-77', 'status' => 'expired'],
            ], 200),
        ]);

        $result = (new BayutStatusSyncService(PortalIntegration::where('portal', 'bayut')->firstOrFail()))->sync();

        $this->assertSame(1, $result['removed']);
        $this->assertSame('removed', $unit->fresh()->bayut_status);
    }

    public function test_service_leaves_live_units_untouched(): void
    {
        $this->createIntegration();
        $unit = $this->listedUnit(['bayut_status' => 'live', 'dubizzle_status' => 'live', 'bayut_listed_at' => '2026-08-01']);

        Http::fake([
            'push.bayut.example/listings' => Http::response([
                ['reference' => 'AD01-77', 'status' => 'live', 'url' => 'https://www.bayut.com/unit-77'],
            ], 200),
        ]);

        $result = (new BayutStatusSyncService(PortalIntegration::where('portal', 'bayut')->firstOrFail()))->sync();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['live']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['removed']);

        $unit->refresh();
        $this->assertSame('live', $unit->bayut_status);
        $this->assertSame('2026-08-01', $unit->bayut_listed_at->toDateString());
    }

    public function test_service_ignores_units_without_reference(): void
    {
        $this->createIntegration();
        $this->createProperty(['availability' => 'listed', 'bayut_status' => 'live']);

        Http::fake([
            'push.bayut.example/listings' => Http::response([
                ['reference' => 'AD01-77', 'status' => 'live'],
            ], 200),
        ]);

        $result = (new BayutStatusSyncService(PortalIntegration::where('portal', 'bayut')->firstOrFail()))->sync();

        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['removed']);
    }

    public function test_service_returns_error_when_api_fails(): void
    {
        $this->createIntegration();
        $this->listedUnit();

        Http::fake([
            'push.bayut.example/listings' => Http::response('server exploded', 500),
        ]);

        $result = (new BayutStatusSyncService(PortalIntegration::where('portal', 'bayut')->firstOrFail()))->sync();

        $this->assertNotNull($result['error']);
        $this->assertSame(0, $result['checked']);
    }

    public function test_command_syncs_statuses_and_records_last_sync(): void
    {
        $integration = $this->createIntegration();
        $this->listedUnit();

        Http::fake([
            'push.bayut.example/listings' => Http::response([
                ['reference' => 'AD01-77', 'status' => 'live'],
            ], 200),
        ]);

        $this->artisan('portals:sync-listing-status', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $integration->refresh();
        $this->assertNotNull($integration->last_synced_at);
        $this->assertNull($integration->last_error);
    }

    public function test_non_admin_cannot_refresh_status(): void
    {
        $this->actingAsRole('agent', ['business_mode' => 'realestate']);

        $this->post(route('inventory.sync-portal-status'))->assertForbidden();
    }

    public function test_admin_refresh_redirects_with_summary(): void
    {
        $this->createIntegration();
        $this->listedUnit();

        Http::fake([
            'push.bayut.example/listings' => Http::response([
                ['reference' => 'AD01-77', 'status' => 'live'],
            ], 200),
        ]);

        $this->post(route('inventory.sync-portal-status'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }
}