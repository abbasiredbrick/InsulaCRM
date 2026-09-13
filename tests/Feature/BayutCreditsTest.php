<?php

namespace Tests\Feature;

use App\Models\PortalCreditsLedger;
use App\Models\PortalIntegration;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BayutCreditsTest extends TestCase
{
    protected function wallet(array $overrides = []): void
    {
        $this->tenant->update(['custom_options' => array_merge($this->tenant->custom_options ?? [], [
            'portal_wallet' => array_merge(['balance' => 0, 'auto_sync' => false, 'endpoint' => null], $overrides),
        ])]);
    }

    protected function portalReadyProperty(array $overrides = []): \App\Models\Property
    {
        return $this->createProperty(array_merge([
            'intent'            => 'rent',
            'property_category' => 'apartment',
            'rera_permit_no'    => 'RERA-CR-1',
            'availability'      => 'ready_to_list',
            'rent_price'        => 95000,
        ], $overrides));
    }

    public function test_cost_matrix_default_and_category_weighting(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->tenant->update(['custom_options' => array_merge($this->tenant->custom_options ?? [], [
            'portal_cost_matrix' => ['default' => 1, 'villa' => 3],
        ])]);

        $svc = app(\App\Services\Portals\BayutCreditsService::class);
        $villa = $this->portalReadyProperty(['property_category' => 'villa']);
        $apartment = $this->portalReadyProperty(['property_category' => 'apartment']);

        $this->assertSame(3, $svc->costFor($this->tenant, $villa));
        $this->assertSame(1, $svc->costFor($this->tenant, $apartment));
        $this->assertSame(1, $svc->costFor($this->tenant, $this->portalReadyProperty(['property_category' => 'townhouse'])));
    }

    public function test_adjust_sets_balance_and_writes_ledger(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        app(\App\Services\Portals\BayutCreditsService::class)->adjust($this->tenant, 100, 'New package');

        $this->assertSame(100, (int) $this->tenant->portalWallet()['balance']);
        $this->assertDatabaseHas('portal_credits_ledger', [
            'tenant_id' => $this->tenant->id,
            'type'      => 'adjust',
            'amount'    => 100,
            'reason'    => 'New package',
        ]);
    }

    public function test_consume_debits_wallet_and_writes_publish_ledger(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->wallet(['balance' => 100]);
        $property = $this->portalReadyProperty();

        app(\App\Services\Portals\BayutCreditsService::class)->consume($this->tenant, $property);

        $this->assertSame(99, (int) $this->tenant->portalWallet()['balance']);
        $this->assertDatabaseHas('portal_credits_ledger', [
            'tenant_id'   => $this->tenant->id,
            'property_id' => $property->id,
            'type'        => 'publish',
            'amount'      => -1,
        ]);
    }

    public function test_auto_sync_balance_reads_from_endpoint(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        PortalIntegration::create([
            'tenant_id'   => $this->tenant->id,
            'portal'      => 'bayut',
            'api_token'   => 'tok',
            'is_active'   => true,
        ]);

        Http::fake([
            'https://portal.example/credits' => Http::response(['balance' => 250], 200),
        ]);

        $this->wallet(['auto_sync' => true, 'endpoint' => 'https://portal.example/credits']);

        $svc = app(\App\Services\Portals\BayutCreditsService::class);

        $this->assertSame(250, $svc->balance($this->tenant));

        $svc->consume($this->tenant, $this->portalReadyProperty());

        // Remote balance is authoritative: manual mirror is untouched.
        $this->assertSame(0, (int) $this->tenant->portalWallet()['balance']);
        $this->assertDatabaseHas('portal_credits_ledger', ['tenant_id' => $this->tenant->id, 'type' => 'publish']);
    }

    public function test_settings_balance_update(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->put(route('settings.updatePortalCreditsSettings'), [
            'action'  => 'balance',
            'balance' => 75,
            'reason'  => 'Renewed package',
        ])->assertRedirect();

        $this->tenant->refresh();
        $this->assertSame(75, (int) $this->tenant->portalWallet()['balance']);
        $this->assertDatabaseHas('portal_credits_ledger', ['tenant_id' => $this->tenant->id, 'type' => 'adjust', 'amount' => 75]);
    }

    public function test_settings_cost_matrix_update(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->put(route('settings.updatePortalCreditsSettings'), [
            'action'   => 'cost_matrix',
            'default'  => '1',
            'category' => ['villa' => '3', 'office' => '2'],
        ])->assertRedirect();

        $this->tenant->refresh();
        $this->assertSame(1, (int) $this->tenant->portalCostMatrix()['default']);
        $this->assertSame(3, (int) $this->tenant->portalCostMatrix()['villa']);
        $this->assertSame(2, (int) $this->tenant->portalCostMatrix()['office']);
    }

    public function test_push_to_bayut_requires_confirmation(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->createIntegrationWithBase();

        $property = $this->portalReadyProperty();

        $this->post(route('inventory.push', [$property, 'bayut']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('not_listed', $property->fresh()->bayut_status);
    }

    public function test_push_to_bayut_blocked_when_insufficient_credits(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->createIntegrationWithBase();
        $this->wallet(['balance' => 0]);

        $property = $this->portalReadyProperty();

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('not_listed', $property->fresh()->bayut_status);
    }

    public function test_push_to_bayut_success_consumes_credit(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->createIntegrationWithBase();
        $this->wallet(['balance' => 100]);

        Http::fake([
            'https://push.bayut.example/listings' => Http::response(['reference' => 'BN-991', 'url' => 'https://www.bayut.com/bn-991'], 200),
        ]);

        $property = $this->portalReadyProperty();

        $this->post(route('inventory.push', [$property, 'bayut']), ['confirmed' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('live', $property->fresh()->bayut_status);
        $this->tenant->refresh();
        $this->assertSame(99, (int) $this->tenant->portalWallet()['balance']);
        $this->assertDatabaseHas('portal_credits_ledger', [
            'tenant_id'   => $this->tenant->id,
            'property_id' => $property->id,
            'type'        => 'publish',
        ]);
    }

    protected function createIntegrationWithBase(): void
    {
        PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal'    => 'bayut',
            'base_url'  => 'https://push.bayut.example',
            'api_token' => 'bayut-token',
            'is_active' => true,
        ]);
    }
}