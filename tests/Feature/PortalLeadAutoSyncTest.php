<?php

namespace Tests\Feature;

use App\Jobs\SyncOverduePortalLeads;
use App\Models\PortalIntegration;
use App\Services\Portals\PortalLeadSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automatic portal lead pulling.
 *
 * The scheduled command is the primary trigger but needs a working cron, so the
 * behaviour that actually matters is the shared service: the throttle, the
 * lock, and the cursor rules. These cover the service and the in-app catch-up
 * job directly.
 */
class PortalLeadAutoSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate']);

        // Every portal host these tests reach has to be faked explicitly. A
        // stray request should fail the test loudly rather than quietly hitting
        // production Property Finder / Bayut.
        Http::preventStrayRequests();
    }

    protected function createIntegration(array $overrides = []): PortalIntegration
    {
        return PortalIntegration::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'pf-key',
            'api_secret' => 'pf-secret',
        ], $overrides));
    }

    /**
     * A single-page Property Finder leads response carrying one enquiry.
     */
    protected function fakeLeadsApi(string $leadId, string $name, string $phone): void
    {
        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt',
                'tokenType' => 'Bearer',
                'expiresIn' => 1800,
            ], 200),
            'https://atlas.propertyfinder.com/v1/leads*' => Http::response([
                'data' => [[
                    'id' => $leadId,
                    'channel' => 'whatsapp',
                    'status' => 'sent',
                    'sender' => [
                        'name' => $name,
                        'contacts' => [['type' => 'phone', 'value' => $phone]],
                    ],
                    'createdAt' => now()->toIso8601String(),
                ]],
                'pagination' => ['page' => 1, 'perPage' => 50, 'total' => 1, 'totalPages' => 1],
            ], 200),
        ]);
    }

    public function test_catch_up_pulls_leads_when_the_cursor_has_never_advanced(): void
    {
        $integration = $this->createIntegration();
        $this->fakeLeadsApi('pf-catch-up-1', 'Layla Haddad', '+971 50 111 0001');

        (new SyncOverduePortalLeads)->handle(app(PortalLeadSyncService::class));

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Layla',
            'lead_source' => 'property_finder',
        ]);

        $this->assertNotNull($integration->fresh()->leads_last_synced_at);
    }

    public function test_catch_up_picks_up_again_once_the_cursor_goes_stale(): void
    {
        $integration = $this->createIntegration([
            'leads_last_synced_at' => now()->subHours(3),
        ]);

        $this->fakeLeadsApi('pf-catch-up-2', 'Sami Nader', '+971 50 111 0002');

        (new SyncOverduePortalLeads)->handle(app(PortalLeadSyncService::class));

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Sami',
        ]);

        $this->assertTrue(
            $integration->fresh()->leads_last_synced_at->gt(now()->subMinutes(1))
        );
    }

    public function test_catch_up_does_not_pull_again_inside_the_throttle_window(): void
    {
        $this->createIntegration(['leads_last_synced_at' => now()]);
        $this->fakeLeadsApi('pf-catch-up-3', 'Should Not Arrive', '+971 50 111 0003');

        (new SyncOverduePortalLeads)->handle(app(PortalLeadSyncService::class));

        Http::assertNothingSent();
        $this->assertDatabaseMissing('leads', ['first_name' => 'Should']);
    }

    public function test_catch_up_skips_a_disabled_integration(): void
    {
        $this->createIntegration([
            'is_active' => false,
            'leads_last_synced_at' => now()->subDays(2),
        ]);

        $this->fakeLeadsApi('pf-catch-up-4', 'Disabled Portal', '+971 50 111 0004');

        (new SyncOverduePortalLeads)->handle(app(PortalLeadSyncService::class));

        Http::assertNothingSent();
    }

    public function test_catch_up_attempts_bayut_with_its_own_leads_token(): void
    {
        // Bayut authenticates with leads_api_token, not the api_token pair the
        // Property Finder integration uses. Getting that filter wrong skips a
        // whole portal with no error anywhere.
        PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
            'base_url' => 'https://www.bayut.com/api-v7',
            'leads_api_token' => 'bayut-leads-token',
        ]);

        // The Bayut pull walks both portal hosts, so both must be faked.
        Http::fake([
            'www.bayut.com/*' => Http::response(['result' => ['items' => []]], 200),
            'dubizzle.com/*' => Http::response(['result' => ['items' => []]], 200),
        ]);

        (new SyncOverduePortalLeads)->handle(app(PortalLeadSyncService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'bayut.com'));
    }

    public function test_catch_up_ignores_a_bayut_integration_without_a_leads_token(): void
    {
        PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
            'base_url' => 'https://www.bayut.com/api-v7',
        ]);

        Http::fake([
            'www.bayut.com/*' => Http::response([], 200),
            'dubizzle.com/*' => Http::response([], 200),
        ]);

        (new SyncOverduePortalLeads)->handle(app(PortalLeadSyncService::class));

        Http::assertNothingSent();
    }

    public function test_bayut_and_propertyfinder_use_different_lead_credentials(): void
    {
        $sync = app(PortalLeadSyncService::class);

        $propertyFinder = $this->createIntegration(['api_token' => 'pf-key', 'api_secret' => 'pf-secret']);
        $bayut = PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
        ]);

        // Neither portal can pull before its own credentials land.
        $this->assertFalse($sync->canPull($bayut));

        // Property Finder needs both halves of the key pair; Bayut needs only
        // its separate leads token.
        $bayut->update(['leads_api_token' => 'bayut-token']);
        $this->assertTrue($sync->canPull($propertyFinder));
        $this->assertTrue($sync->canPull($bayut->fresh()));

        $halfKeyed = $this->createIntegration(['portal' => 'other', 'api_token' => 'k', 'api_secret' => null]);
        $this->assertFalse($sync->canPull($halfKeyed));

        $this->assertEqualsCanonicalizing(
            [$propertyFinder->id, $bayut->id],
            $sync->pullable()->pluck('id')->all()
        );
    }

    public function test_pull_advances_the_cursor_on_a_clean_run(): void
    {
        $integration = $this->createIntegration(['leads_last_synced_at' => now()->subDay()]);
        $this->fakeLeadsApi('pf-cursor-1', 'Amir Fadel', '+971 50 111 0005');

        $result = app(PortalLeadSyncService::class)->pull($integration, force: true);

        $this->assertNull($result['error']);
        $this->assertSame(1, $result['created']);
        $this->assertFalse($result['skipped']);
        $this->assertNull($integration->fresh()->leads_last_error);
    }

    public function test_pull_holds_the_cursor_when_the_api_errors(): void
    {
        $syncedAt = now()->subDay();

        $integration = $this->createIntegration(['leads_last_synced_at' => $syncedAt]);

        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt',
                'expiresIn' => 1800,
            ], 200),
            'https://atlas.propertyfinder.com/v1/leads*' => Http::response(['error' => 'boom'], 500),
        ]);

        $result = app(PortalLeadSyncService::class)->pull($integration, force: true);

        $this->assertNotNull($result['error']);

        // Un-advanced on purpose: a failed run re-reads the same window rather
        // than stepping over the leads it never fetched.
        $this->assertSame(
            $syncedAt->toDateTimeString(),
            $integration->fresh()->leads_last_synced_at->toDateTimeString()
        );
        $this->assertNotNull($integration->fresh()->leads_last_error);
    }

    public function test_pull_is_skipped_while_another_pull_holds_the_lock(): void
    {
        $integration = $this->createIntegration();
        $this->fakeLeadsApi('pf-lock-1', 'Locked Out', '+971 50 111 0006');

        // Stand in for a scheduled pull already in flight on this integration.
        Cache::lock('portal-leads-pull:'.$integration->id, 300)->get();

        $result = app(PortalLeadSyncService::class)->pull($integration, force: true);

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
    }

    public function test_pull_on_a_disabled_integration_is_skipped_not_attempted(): void
    {
        $integration = $this->createIntegration(['is_active' => false]);
        $this->fakeLeadsApi('pf-disabled-1', 'Should Not Sync', '+971 50 111 0007');

        $result = app(PortalLeadSyncService::class)->pull($integration, force: true);

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
    }

    public function test_scheduled_command_pulls_even_when_the_cursor_is_fresh(): void
    {
        // The schedule is the primary trigger, so it must not be gated by the
        // overdue threshold the catch-up uses.
        $integration = $this->createIntegration(['leads_last_synced_at' => now()]);
        $this->fakeLeadsApi('pf-command-1', 'Command Pull', '+971 50 111 0008');

        $this->artisan('portals:pull-propertyfinder-leads')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/leads'));
        $this->assertDatabaseHas('leads', ['first_name' => 'Command']);
    }

    public function test_scheduled_command_leaves_a_disabled_integration_alone(): void
    {
        $this->createIntegration(['is_active' => false]);
        $this->fakeLeadsApi('pf-command-2', 'Disabled Command', '+971 50 111 0009');

        $this->artisan('portals:pull-propertyfinder-leads')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_staleness_is_reported_per_integration(): void
    {
        $fresh = $this->createIntegration(['leads_last_synced_at' => now()]);
        $stale = $this->createIntegration([
            'portal' => 'bayut',
            'api_token' => null,
            'api_secret' => null,
            'leads_api_token' => 'bayut-token',
            'leads_last_synced_at' => now()->subDay(),
        ]);
        $never = $this->createIntegration([
            'portal' => 'other',
            'api_token' => 'k',
            'api_secret' => 's',
            'leads_last_synced_at' => null,
        ]);

        $staleness = app(PortalLeadSyncService::class)->staleness();

        $this->assertSame('ok', $staleness[$fresh->id]['state']);
        $this->assertSame('stale', $staleness[$stale->id]['state']);
        $this->assertSame(PortalLeadSyncService::NEVER, $staleness[$never->id]['state']);
    }

    public function test_settings_page_warns_when_the_pull_has_gone_stale(): void
    {
        $this->createIntegration(['leads_last_synced_at' => now()->subDay()]);

        $this->get(route('portal-integrations.index'))
            ->assertOk()
            ->assertSee('This is overdue');
    }

    public function test_settings_page_does_not_warn_on_a_healthy_pull(): void
    {
        $this->createIntegration(['leads_last_synced_at' => now()]);

        $this->get(route('portal-integrations.index'))
            ->assertOk()
            ->assertDontSee('This is overdue');
    }

    public function test_the_pull_is_due_after_the_three_minute_sync_window(): void
    {
        $service = app(PortalLeadSyncService::class);

        $due = $this->createIntegration(['leads_last_synced_at' => now()->subMinutes(4)]);
        $fresh = $this->createIntegration(['portal' => 'bayut', 'api_token' => null, 'api_secret' => null, 'leads_api_token' => 'bayut-token', 'leads_last_synced_at' => now()->subMinutes(2)]);

        $this->assertTrue($service->isDue($due));
        $this->assertFalse($service->isDue($fresh));
    }

    public function test_settings_staleness_uses_the_display_window_not_the_sync_window(): void
    {
        // Ten minutes ago the pull is already due again under the 3-minute
        // sync window, but the settings screen must not call a working
        // integration "stale" until the far looser display window passes.
        $integration = $this->createIntegration(['leads_last_synced_at' => now()->subMinutes(10)]);

        $service = app(PortalLeadSyncService::class);

        $this->assertTrue($service->isDue($integration));
        $this->assertSame('ok', $service->staleness()[$integration->id]['state']);
    }

    public function test_the_catch_up_middleware_throttle_is_three_minutes(): void
    {
        // The middleware never fires inside the suite (runningUnitTests guard),
        // so pin the window as a constant: a tighter catch-up is only useful if
        // it is still aligned with OVERDUE_AFTER_MINUTES.
        $reflected = new \ReflectionClass(\App\Http\Middleware\CatchUpPortalLeads::class);

        $this->assertSame(180, $reflected->getConstant('THROTTLE_SECONDS'));
    }

    public function test_settings_page_prompts_a_first_pull_for_a_configured_integration(): void
    {
        $this->createIntegration(['leads_last_synced_at' => null]);

        $this->get(route('portal-integrations.index'))
            ->assertOk()
            ->assertSee('No leads have been pulled yet');
    }

    public function test_the_catch_up_middleware_stays_dispatched_lazily_not_inline(): void
    {
        // Regression guard on CatchUpPortalLeads::shouldCheck(). Laravel's test
        // harness runs terminating callbacks, so if the middleware ever starts
        // dispatching during the suite, every feature test that touches the web
        // group would attempt real Property Finder HTTP. The catch-up's
        // behaviour is covered above by driving the service and the job
        // directly; this only pins the guard.
        $this->createIntegration();
        $this->fakeLeadsApi('pf-middleware-1', 'Middleware Guard', '+971 50 111 0011');

        $this->get(route('portal-integrations.index'))->assertOk();

        $this->assertNull(Cache::get('portal-leads-catch-up:due'));
        $this->assertDatabaseMissing('leads', ['first_name' => 'Middleware']);
        Http::assertNothingSent();
    }
}
