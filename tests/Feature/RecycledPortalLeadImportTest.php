<?php

namespace Tests\Feature;

use App\Jobs\ProcessRecycledPortalLeadImport;
use App\Models\PortalIntegration;
use App\Models\RecycledLead;
use App\Models\RecycledPortalImportRun;
use App\Services\RecycledPortalLeadImportService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecycledPortalLeadImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin([
            'business_mode' => 'realestate',
            'country' => 'AE',
            'timezone' => 'Asia/Dubai',
        ]);
    }

    public function test_only_cold_call_agents_and_admins_can_open_the_portal_pull_screen(): void
    {
        $this->createBayutIntegration();

        $this->actingAsRole('agent');
        $this->get(route('recycled.portal-import.create'))->assertForbidden();
        $this->post(route('recycled.portal-import.store'), [
            'portal' => 'bayut',
            'date_from' => now()->subDays(10)->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertForbidden();

        $this->actingAsRole('cold_call_agent');
        $this->get(route('recycled.portal-import.create'))
            ->assertOk()
            ->assertSee('Pull Previous Leads from Portal API')
            ->assertSee('Bayut')
            ->assertSee('Dubizzle')
            ->assertSee('up to 180 days per export', false)
            ->assertSee(route('recycled.create'), false);

        $this->actingAs($this->adminUser);
        Queue::fake();
        $this->post(route('recycled.portal-import.store'), [
            'portal' => 'bayut',
            'date_from' => now()->subDays(10)->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertRedirect();
    }

    public function test_bayut_preview_writes_nothing_then_confirmed_import_links_active_leads_and_is_idempotent(): void
    {
        $this->createBayutIntegration();
        $active = $this->createLead(['phone' => '0501234567']);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://www.bayut.com/api-v7/stats/website-client-leads')) {
                return Http::response([
                    $this->bayutRecord('whatsapp|active', 'Sara Ahmed', '+971501234567', '2026-04-02 10:00:00'),
                    $this->bayutRecord('whatsapp|new', 'Noor Khan', '+971509876543', '2026-04-03 11:00:00'),
                    $this->bayutRecord('whatsapp|before', 'Too Early', '+971507777777', '2025-12-31 23:59:59'),
                    $this->bayutRecord('whatsapp|after', 'Too Late', '+971506666666', '2026-07-01 00:00:00'),
                ]);
            }

            return Http::response([], 404);
        });

        $run = $this->createBayutRun();
        $service = app(RecycledPortalLeadImportService::class);

        $result = $service->processNext($run);
        $run->refresh();

        $this->assertFalse($result['dispatch_next']);
        $this->assertSame('ready', $run->status, json_encode($run->errors));
        $this->assertSame(4, $run->observed_count);
        $this->assertSame(2, $run->out_of_range_count);
        $this->assertSame(2, $run->contactable_count);
        $this->assertSame(1, $run->imported_count);
        $this->assertSame(1, $run->already_active_count);
        $this->assertDatabaseCount('recycled_leads', 0);
        $this->assertDatabaseCount('recycled_lead_source_events', 0);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.bayut.com/api-v7/stats/website-client-leads')
            && $request['timestamp'] === '2026-01-01 00:00:00'
            && $request['type'] === 'whatsapp'
            && $request['target'] === 'listing'
            && (int) $request['is_trulead'] === 1
            && $request->hasHeader('Authorization', 'Bearer pull-token-1234567890'));

        $this->post(route('recycled.portal-import.confirm', $run))->assertRedirect();
        $run->refresh();

        $this->assertSame('import', $run->mode);
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(ProcessRecycledPortalLeadImport::class, 2);

        $result = $service->processNext($run);
        $run->refresh();

        $this->assertFalse($result['dispatch_next']);
        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->imported_count);
        $this->assertSame(1, $run->already_active_count);
        $this->assertDatabaseCount('recycled_leads', 2);
        $this->assertDatabaseCount('recycled_lead_source_events', 2);
        $this->assertDatabaseCount('leads', 1);

        $linked = RecycledLead::where('status', 'already_active')->firstOrFail();
        $this->assertSame($active->id, $linked->linked_lead_id);
        $this->assertNull($linked->assignee_id);

        $added = RecycledLead::where('phone', '+971509876543')->firstOrFail();
        $this->assertSame('api_import', $added->source);
        $this->assertSame('bayut', $added->portal);
        $this->assertSame('whatsapp', $added->category);
        $this->assertSame('971509876543', $added->normalized_phone);
        $this->assertSame('pending', $added->status);
        $this->assertTrue((bool) $added->needs_review);

        $secondRun = $this->createBayutRun();
        $service->processNext($secondRun);
        $this->post(route('recycled.portal-import.confirm', $secondRun));
        $service->processNext($secondRun->fresh());

        $this->assertDatabaseCount('recycled_leads', 2);
        $this->assertDatabaseCount('recycled_lead_source_events', 2);
        $this->assertSame(2, $secondRun->fresh()->duplicate_count);
        $this->assertSame(0, $secondRun->fresh()->imported_count);
    }

    public function test_propertyfinder_pull_uses_production_auth_paginates_and_filters_the_end_date_locally(): void
    {
        $this->createPropertyFinderIntegration();
        $dateFrom = now('Asia/Dubai')->subDays(89)->toDateString();

        Queue::fake();
        $this->post(route('recycled.portal-import.store'), [
            'portal' => 'property_finder',
            'date_from' => now('Asia/Dubai')->subDays(90)->toDateString(),
            'date_to' => now('Asia/Dubai')->toDateString(),
        ])->assertSessionHasErrors('date_from');
        $this->assertDatabaseCount('recycled_portal_import_runs', 0);

        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt',
                'tokenType' => 'Bearer',
                'expiresIn' => 1800,
            ]),
            'https://atlas.propertyfinder.com/v1/leads*' => Http::sequence()
                ->push([
                    'data' => [$this->propertyFinderRecord('pf-1', 'Rania Khalil', 'whatsapp', '+971501239999', now('Asia/Dubai')->toDateString().' 10:00:00')],
                    'pagination' => ['totalPages' => 2],
                ])
                ->push([
                    'data' => [$this->propertyFinderRecord('pf-2', 'Old Lead', 'email', null, '2020-01-01T10:00:00Z', 'old@example.com')],
                    'pagination' => ['totalPages' => 2],
                ]),
        ]);

        $run = $this->createPropertyFinderRun(['date_from' => $dateFrom]);
        $service = app(RecycledPortalLeadImportService::class);

        $first = $service->processNext($run);
        $second = $service->processNext($run->fresh());

        $this->assertTrue($first['dispatch_next'], json_encode($run->fresh()->errors));
        $this->assertFalse($second['dispatch_next'], json_encode($run->fresh()->errors));

        $run->refresh();
        $this->assertSame('ready', $run->status);
        $this->assertSame(2, $run->observed_count);
        $this->assertSame(1, $run->out_of_range_count);
        $this->assertSame(1, $run->imported_count);
        $this->assertDatabaseCount('recycled_leads', 0);

        Http::assertSent(fn ($request) => $request->url() === 'https://atlas.propertyfinder.com/v1/auth/token'
            && $request['apiKey'] === 'pf-production-key'
            && $request['apiSecret'] === 'pf-production-secret');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/leads')
            && str_contains($request->url(), 'page=1')
            && str_contains($request->url(), 'createdAtFrom='));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/leads')
            && str_contains($request->url(), 'page=2'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sandbox.atlas.propertyfinder.com'));
    }

    public function test_propertyfinder_production_credentials_are_required_even_when_sandbox_is_configured(): void
    {
        PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'use_sandbox' => true,
            'sandbox_api_token' => 'sandbox-key',
            'sandbox_api_secret' => 'sandbox-secret',
        ]);

        $this->get(route('recycled.portal-import.create'))
            ->assertOk()
            ->assertDontSee('>Property Finder</option>', false);

        Queue::fake();
        $this->post(route('recycled.portal-import.store'), [
            'portal' => 'property_finder',
            'date_from' => now('Asia/Dubai')->subDays(10)->toDateString(),
            'date_to' => now('Asia/Dubai')->toDateString(),
        ])->assertSessionHasErrors('portal');

        $this->assertDatabaseCount('recycled_portal_import_runs', 0);
    }

    public function test_tenant_scoped_run_cannot_be_viewed_or_confirmed_by_another_tenant(): void
    {
        $this->createBayutIntegration();
        $run = $this->createBayutRun();
        $otherAdmin = $this->createTenantWithAdmin([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'business_mode' => 'realestate',
            'country' => 'AE',
            'timezone' => 'Asia/Dubai',
        ]);

        $this->actingAs($otherAdmin)
            ->get(route('recycled.portal-import.show', $run))
            ->assertNotFound();

        $this->post(route('recycled.portal-import.confirm', $run))->assertNotFound();
        $this->post(route('recycled.portal-import.cancel', $run))->assertNotFound();

        $this->assertSame('queued', $run->fresh()->status);
    }

    public function test_queued_pull_can_be_cancelled_without_calling_the_portal(): void
    {
        $this->createBayutIntegration();
        $run = $this->createBayutRun();
        Http::fake();

        $this->post(route('recycled.portal-import.cancel', $run))
            ->assertRedirect()
            ->assertSessionHas('success');

        $result = app(RecycledPortalLeadImportService::class)->processNext($run->fresh());

        $this->assertFalse($result['dispatch_next']);
        $this->assertSame('cancelled', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_authentication_failure_fails_the_run_without_importing_records(): void
    {
        $this->createBayutIntegration();
        Http::fake([
            'https://www.bayut.com/api-v7/stats/website-client-leads' => Http::response(['detail' => 'Unauthorized'], 401),
        ]);

        $run = $this->createBayutRun();
        $result = app(RecycledPortalLeadImportService::class)->processNext($run);
        $run->refresh();

        $this->assertFalse($result['dispatch_next']);
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, $run->error_count);
        $this->assertStringContainsString('HTTP 401', implode(' ', $run->errors));
        $this->assertDatabaseCount('recycled_leads', 0);
        $this->assertDatabaseCount('recycled_lead_source_events', 0);
    }

    public function test_transient_portal_failure_retries_without_advancing_the_cursor(): void
    {
        $this->createBayutIntegration();
        Http::fake([
            'https://www.bayut.com/api-v7/stats/website-client-leads*' => Http::response(['detail' => 'Temporarily unavailable'], 503),
        ]);

        $run = $this->createBayutRun();

        $exception = null;

        try {
            app(RecycledPortalLeadImportService::class)->processNext($run);
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('HTTP 503', $exception->getMessage());

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame([], $run->cursor);
        $this->assertSame(0, $run->error_count);
        $this->assertDatabaseCount('recycled_leads', 0);
    }

    public function test_malformed_successful_portal_response_fails_without_advancing_the_cursor(): void
    {
        $this->createBayutIntegration();
        Http::fake([
            'https://www.bayut.com/api-v7/stats/website-client-leads*' => Http::response(['message' => 'Unexpected envelope']),
        ]);

        $run = $this->createBayutRun();
        app(RecycledPortalLeadImportService::class)->processNext($run);
        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('invalid response', implode(' ', $run->errors));
        $this->assertSame([], $run->cursor);
        $this->assertDatabaseCount('recycled_leads', 0);
    }

    protected function createBayutIntegration(): PortalIntegration
    {
        return PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
            'leads_api_token' => 'pull-token-1234567890',
        ]);
    }

    protected function createPropertyFinderIntegration(): PortalIntegration
    {
        return PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'use_sandbox' => true,
            'api_token' => 'pf-production-key',
            'api_secret' => 'pf-production-secret',
            'sandbox_api_token' => 'sandbox-key',
            'sandbox_api_secret' => 'sandbox-secret',
        ]);
    }

    protected function createBayutRun(array $overrides = []): RecycledPortalImportRun
    {
        return $this->createRun(array_replace([
            'portal' => 'bayut',
            'date_from' => '2026-01-01',
            'date_to' => '2026-06-30',
            'types' => ['whatsapp'],
            'targets' => ['listing'],
        ], $overrides));
    }

    protected function createPropertyFinderRun(array $overrides = []): RecycledPortalImportRun
    {
        return $this->createRun(array_replace([
            'portal' => 'property_finder',
            'date_from' => now('Asia/Dubai')->subDays(89)->toDateString(),
            'date_to' => now('Asia/Dubai')->toDateString(),
        ], $overrides));
    }

    protected function createRun(array $payload): RecycledPortalImportRun
    {
        Queue::fake();
        $response = $this->post(route('recycled.portal-import.store'), $payload);
        $run = RecycledPortalImportRun::latest('id')->firstOrFail();
        $response->assertRedirect(route('recycled.portal-import.show', $run));
        Queue::assertPushed(ProcessRecycledPortalLeadImport::class, 1);

        return $run;
    }

    protected function bayutRecord(string $id, string $name, string $phone, string $date): array
    {
        return [
            'lead_id' => $id,
            'source' => 'bayut',
            'lead_target' => 'listing',
            'date_time' => $date,
            'listing_details' => [
                'listing_id' => 12345,
                'listing_reference' => 'bayut-reference-12345',
                'current_type' => 'Apartment',
            ],
            'inquirer_details' => [
                'name' => $name,
                'cell' => $phone,
                'email' => '',
                'message' => 'Please contact me.',
            ],
        ];
    }

    protected function propertyFinderRecord(
        string $id,
        string $name,
        string $channel,
        ?string $phone,
        string $date,
        ?string $email = null,
    ): array {
        $contacts = $phone === null
            ? [['type' => 'email', 'value' => $email]]
            : [['type' => 'phone', 'value' => $phone]];

        return [
            'id' => $id,
            'entityType' => 'listing',
            'channel' => $channel,
            'status' => 'sent',
            'sender' => ['name' => $name, 'contacts' => $contacts],
            'listing' => ['id' => 'L-1', 'reference' => 'PF-1'],
            'createdAt' => $date,
        ];
    }
}
