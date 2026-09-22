<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\RecycledLead;
use App\Models\Task;
use App\Services\LeadSearchService;
use App\Services\RecycledLeadRegenerationService;
use App\Services\RecycledLeadsImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecycledLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    // ── Access & routing ─────────────────────────────────────────

    public function test_cold_call_agent_and_admin_can_open_the_recycled_pool(): void
    {
        $this->actingAsRole('agent');
        $this->get(route('recycled.index'))->assertStatus(403);

        $this->actingAsRole('cold_call_agent');
        $this->get(route('recycled.index'))->assertStatus(200)->assertSee('Recycle Bank');

        $this->actingAs($this->adminUser);
        $this->get(route('recycled.index'))->assertStatus(200);
    }

    public function test_wholesale_tenant_cannot_access_the_recycled_pool(): void
    {
        $this->actingAsAdmin(['business_mode' => 'wholesale', 'slug' => 'test-company-wholesale']);

        $this->get(route('recycled.index'))->assertStatus(404);
    }

    // ── CSV import ───────────────────────────────────────────────

    public function test_import_previous_portal_leads_builds_the_pool(): void
    {
        $csv = "Name,Phone,Email,Deal Type,Project,Handover Date\n"
            ."John Doe,+971501234567,john@example.com,buy,Al Reem Island,2027-03-01\n"
            ."Jane Roe,+971509876543,jane@example.com,rent,Tilal Al Ghaf,\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('bayut.csv', $csv),
            'portal' => 'bayut',
        ])->assertRedirect(route('recycled.index'))->assertSessionHas('success');

        $pool = RecycledLead::where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(2, $pool);

        $john = $pool->firstWhere('first_name', 'John');
        $this->assertSame('csv_import', $john->source);
        $this->assertSame('bayut', $john->portal);
        $this->assertSame('sale', $john->original_deal_type);
        $this->assertSame('Al Reem Island', $john->purchased_project);
        $this->assertSame('2027-03-01', $john->expected_handover_date->format('Y-m-d'));
        $this->assertSame('pending', $john->status);

        $this->assertSame('rent', $pool->firstWhere('first_name', 'Jane')->original_deal_type);
    }

    public function test_import_flags_and_links_existing_active_leads_without_duplicating(): void
    {
        $this->createLead([
            'first_name' => 'Existing',
            'phone' => '+971501234567',
            'email' => 'existing@example.com',
        ]);

        $csv = "Name,Phone,Email\nAli,+971501234567,ali@example.com\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('dubizzle.csv', $csv),
            'portal' => 'dubizzle',
        ])->assertRedirect(route('recycled.index'));

        $lead = Lead::where('phone', '+971501234567')->first();

        $record = RecycledLead::first();
        $this->assertNotNull($record);
        $this->assertSame('already_active', $record->status);
        $this->assertSame($lead->id, $record->linked_lead_id);
        $this->assertNull($record->assignee_id);
    }

    public function test_import_skips_rows_already_in_the_pool(): void
    {
        $csv = "Name,Phone\nAhmed,+971551112233\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('pf.csv', $csv),
            'portal' => 'property_finder',
        ]);

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('pf.csv', $csv),
            'portal' => 'property_finder',
        ]);

        $this->assertSame(1, RecycledLead::count());
    }

    // ── Auto recycle (60-day rule) ───────────────────────────────

    public function test_recycle_command_moves_60day_lost_lead_into_the_pool(): void
    {
        $lead = $this->createLead(['status' => 'closed_lost', 'do_not_contact' => false]);
        DB::table('leads')->where('id', $lead->id)->update([
            'status_changed_at' => now()->subDays(61),
            'updated_at' => now()->subDays(61),
        ]);

        $this->artisan('recycle:leads')->assertSuccessful();

        $this->assertDatabaseHas('recycled_leads', [
            'tenant_id' => $this->tenant->id,
            'source' => 'auto_recycle',
            'original_lead_id' => $lead->id,
            'status' => 'pending',
        ]);

        $this->assertNotNull($lead->fresh()->recycled_at);
    }

    public function test_recycle_command_skips_recent_and_dnc_leads(): void
    {
        $fresh = $this->createLead(['status' => 'nurture', 'do_not_contact' => false]);
        $dnc = $this->createLead(['status' => 'dead', 'do_not_contact' => true]);
        DB::table('leads')->where('id', $dnc->id)->update(['status_changed_at' => now()->subDays(90)]);

        $this->artisan('recycle:leads')->assertSuccessful();

        $this->assertSame(0, RecycledLead::where('tenant_id', $this->tenant->id)->count());
        $this->assertNull($fresh->fresh()->recycled_at);
        $this->assertNull($dnc->fresh()->recycled_at);
    }

    public function test_recycle_command_does_not_touch_closed_won_or_still_active_statuses(): void
    {
        $this->createLead(['status' => 'closed_won']);
        $this->createLead(['status' => 'active_client']);

        DB::table('leads')->update(['status_changed_at' => now()->subDays(90)]);

        $this->artisan('recycle:leads')->assertSuccessful();

        $this->assertSame(0, RecycledLead::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_recycled_leads_are_hidden_from_the_live_lead_scope(): void
    {
        $active = $this->createLead();
        $recycled = $this->createLead();

        DB::table('leads')->where('id', $recycled->id)->update([
            'status' => 'dead',
            'recycled_at' => now(),
            'status_changed_at' => now()->subDays(61),
        ]);

        $visible = app(LeadSearchService::class)->scopedLeads($this->adminUser)->pluck('id');

        $this->assertContains($active->id, $visible);
        $this->assertNotContains($recycled->id, $visible);
    }

    // ── Regeneration ─────────────────────────────────────────────

    public function test_regeneration_revives_the_original_recycled_lead(): void
    {
        $lead = $this->createLead(['status' => 'closed_lost', 'do_not_contact' => false]);
        DB::table('leads')->where('id', $lead->id)->update([
            'status_changed_at' => now()->subDays(61),
            'updated_at' => now()->subDays(61),
        ]);

        $this->artisan('recycle:leads');
        $pool = RecycledLead::where('tenant_id', $this->tenant->id)->where('original_lead_id', $lead->id)->firstOrFail();

        $regenerated = app(RecycledLeadRegenerationService::class)->regenerate($pool, 'renter_to_buyer', $this->adminUser->id);

        $this->assertSame($lead->id, $regenerated->id);
        $fresh = $lead->fresh();
        $this->assertSame('new', $fresh->status);
        $this->assertSame('warm', $fresh->temperature);
        $this->assertSame('sale', $fresh->deal_type);
        $this->assertNull($fresh->recycled_at);
        $this->assertSame($lead->id, $pool->fresh()->regenerated_lead_id);
        $this->assertSame('regenerated', $pool->fresh()->status);
        $this->assertSame('renter_to_buyer', $pool->fresh()->regeneration_intent);
    }

    public function test_regeneration_creates_fresh_lead_from_imported_record_with_portal_source(): void
    {
        $pool = RecycledLead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'dubizzle',
            'first_name' => 'Sara',
            'phone' => '+971551234567',
        ]);

        $lead = app(RecycledLeadRegenerationService::class)->regenerate($pool, 'investment_opportunities', $this->adminUser->id);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'lead_source' => 'dubizzle']);
        $this->assertSame('Sara', $lead->first_name);
        $this->assertSame('warm', $lead->temperature);
        $this->assertSame('sale', $lead->deal_type);
        $this->assertSame('new', $lead->status);
        $this->assertSame($lead->id, $pool->fresh()->regenerated_lead_id);
        $this->assertSame('regenerated', $pool->fresh()->status);
        $this->assertSame(1, Lead::count());
    }

    public function test_regeneration_with_handover_intent_schedules_a_handover_task(): void
    {
        $pool = RecycledLead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'original_deal_type' => 'sale',
            'purchased_project' => 'Rose Tower',
            'unit_no' => '1204',
            'expected_handover_date' => \Illuminate\Support\Carbon::parse('2027-03-01'),
        ]);

        $lead = app(RecycledLeadRegenerationService::class)->regenerate($pool, 'handover_tracking', $this->adminUser->id);

        $task = Task::where('tenant_id', $this->tenant->id)->where('lead_id', $lead->id)->first();

        $this->assertNotNull($task);
        $this->assertStringContainsString('Handover check-in', $task->title);
        $this->assertStringContainsString('Rose Tower', $task->title);
        $this->assertSame('2027-02-22', $task->due_date->format('Y-m-d'));
    }

    public function test_regeneration_blocks_when_contact_requested_do_not_contact(): void
    {
        $pool = RecycledLead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'do_not_contact',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('asked not to be contacted');

        app(RecycledLeadRegenerationService::class)->regenerate($pool, 'rental_relocation', $this->adminUser->id);
    }

    // ── Working the pool (HTTP) ──────────────────────────────────

    public function test_logging_an_outcome_updates_status_and_appends_notes(): void
    {
        $pool = RecycledLead::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->get(route('recycled.show', $pool))->assertOk()->assertSee('Regenerate to active lead');

        $this->post(route('recycled.status', $pool), [
            'status' => 'call_back',
            'agent_id' => $this->adminUser->id,
            'call_notes' => 'Wants a 2BR in Marina under 120k.',
            'next_call_at' => now()->addDays(3)->format('Y-m-d'),
        ])->assertRedirect();

        $fresh = $pool->fresh();
        $this->assertSame('call_back', $fresh->status);
        $this->assertStringContainsString('Marina', $fresh->call_notes);
        $this->assertNotNull($fresh->last_contacted_at);
        $this->assertSame($this->adminUser->id, $fresh->assignee_id);
    }

    public function test_run_recycle_button_recycles_overdue_leads_for_this_tenant(): void
    {
        $lead = $this->createLead(['status' => 'dead', 'do_not_contact' => false]);
        DB::table('leads')->where('id', $lead->id)->update([
            'status_changed_at' => now()->subDays(70),
            'updated_at' => now()->subDays(70),
        ]);

        $this->post(route('recycled.runRecycle'))
            ->assertRedirect(route('recycled.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, RecycledLead::where('tenant_id', $this->tenant->id)->where('original_lead_id', $lead->id)->count());
    }

    // ── Service used by the HTTP import endpoint ─────────────────

    public function test_import_service_reports_counts(): void
    {
        $csv = "Name,Phone,Email\nTest,+971520000000,test@example.com\n\nBlank,,\n";
        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');

        unlink($path);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['duplicates']);
        $this->assertSame(0, $result['already_active']);
        $this->assertSame(1, RecycledLead::where('tenant_id', $this->tenant->id)->count());
    }
}
