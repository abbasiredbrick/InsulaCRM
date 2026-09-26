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

    public function test_import_does_not_collapse_distinct_leads_sharing_a_listing_reference(): void
    {
        // Bayut log exports put the listing reference (10219-…) on every row,
        // so two different people interested in the same listing share it.
        $csv = "Name,Phone,Reference No.\n"
            ."Ali,+971501111111,10219-AAAAAA\n"
            ."Sarah,+971502222222,10219-AAAAAA\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('bayut.csv', $csv),
            'portal' => 'bayut',
        ])->assertRedirect(route('recycled.index'));

        $this->assertSame(2, RecycledLead::count());
    }

    public function test_import_drops_bayut_agent_mirror_rows_but_keeps_a_bare_agent_label(): void
    {
        $csv = implode("\n", [
            'Date,Lead Name,Lead Email,Lead Number,Enquiry From,Reference No.',
            '2026-09-17,Mira Agent,mira@example.com,971551111001,Agent Profile,10219-A1',
            '2026-09-18,Real Buyer,buyer@example.com,971551111002,Agent,10219-A2',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');

        unlink($path);

        $this->assertSame(1, $result['imported']);
        $this->assertDatabaseMissing('recycled_leads', ['phone' => '+971551111001']);
        $this->assertDatabaseHas('recycled_leads', ['phone' => '+971551111002']);
    }

    public function test_import_skips_rows_that_only_carry_a_reference(): void
    {
        // The reference in log exports is the LISTING reference (10219-…), not
        // a lead id and not a way to reach anyone — so a row with only a name
        // and a reference has no contact signal and is skipped as no-contact.
        $csv = "Name,Reference No.\n"
            ."Ali,10219-AAAAAA\n"
            ."Ali,10219-AAAAAA\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('bayut.csv', $csv),
            'portal' => 'bayut',
        ]);

        $this->assertSame(0, RecycledLead::count());
    }

    public function test_import_persists_the_category_and_backfills_it_on_reupload(): void
    {
        $csv = "Name,Phone,Email\nKarim,+971503333444,karim@example.com\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('whatsapp.csv', $csv),
            'portal' => 'bayut',
            'category' => 'whatsapp',
        ]);

        $this->assertSame('whatsapp', RecycledLead::first()->category);

        // Re-uploading without a category leaves the existing value untouched.
        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('whatsapp.csv', $csv),
            'portal' => 'bayut',
        ]);

        $this->assertSame('whatsapp', RecycledLead::first()->category);
        $this->assertSame(1, RecycledLead::count());

        // A pre-existing pool row (imported before categories existed) gets
        // backfilled on re-upload of the file with a category.
        RecycledLead::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'source' => 'csv_import',
            'portal' => 'bayut',
            'first_name' => 'Noor',
            'phone' => '+971507778888',
            'status' => 'pending',
            'recycled_at' => now(),
        ]);

        $backfill = "Name,Phone,Email\nNoor,+971507778888,noor@example.com\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('phone.csv', $backfill),
            'portal' => 'bayut',
            'category' => 'phone',
        ]);

        $this->assertSame(1, RecycledLead::where('category', 'phone')->count());
        $this->assertSame('phone', RecycledLead::where('first_name', 'Noor')->first()->category);
    }

    public function test_index_shows_total_and_filters_by_category_and_per_page(): void
    {
        RecycledLead::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'category' => 'whatsapp',
        ]);
        RecycledLead::factory()->count(1)->create([
            'tenant_id' => $this->tenant->id,
            'category' => 'email',
        ]);

        $response = $this->get(route('recycled.index'));
        $response->assertOk()->assertSee('4')->assertSee('Showing');

        $response = $this->get(route('recycled.index', ['category' => 'whatsapp']));
        $response->assertSee('WhatsApp')->assertDontSee('category=phone');

        $response = $this->get(route('recycled.index', ['per_page' => 10]));
        $response->assertOk();
    }

    public function test_reupload_backfills_lead_date_type_and_review_flag_on_existing_pool_rows(): void
    {
        // A pool row imported before the Date/Purpose columns were captured.
        RecycledLead::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'source' => 'csv_import',
            'portal' => 'bayut',
            'first_name' => 'Omar',
            'phone' => '+971552223344',
            'email' => 'omar@example.com',
            'status' => 'pending',
            'recycled_at' => now()->subDays(3),
        ]);

        $this->assertNull(RecycledLead::first()->lead_date);

        // Re-upload of the same file now carries the Date + Purpose columns.
        $enriched = "Date,Name,Phone,Email,Purpose,Price (AED)\n"
            ."2026-09-20,Omar,+971552223344,omar@example.com,rent,400000\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('bayut.csv', $enriched),
            'portal' => 'bayut',
        ])->assertRedirect(route('recycled.index'));

        $this->assertSame(1, RecycledLead::count());

        $record = RecycledLead::first();
        $this->assertSame('2026-09-20', $record->lead_date->format('Y-m-d'));
        $this->assertSame('rent', $record->original_deal_type);
        $this->assertFalse((bool) $record->needs_review);
    }

    public function test_reupload_flags_existing_pending_rows_missing_a_purpose(): void
    {
        // A pool row imported before the no-purpose review flag existed.
        RecycledLead::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->adminUser->id,
            'source' => 'csv_import',
            'portal' => 'bayut',
            'first_name' => 'Mariam',
            'phone' => '+971553334455',
            'email' => 'mariam@example.com',
            'status' => 'pending',
            'recycled_at' => now()->subDays(3),
        ]);

        $record = RecycledLead::first();
        $this->assertFalse((bool) $record->needs_review);
        $this->assertNull($record->review_reason);

        // Same row re-uploaded as a full Bayut email export (no Purpose).
        $enriched = "Date,Lead Name,Phone,Lead Email,Reference No.,Price (AED)\n"
            ."2026-09-18,Mariam,+971553334455,mariam@example.com,BAY-123,700000\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('bayut.csv', $enriched),
            'portal' => 'bayut',
        ]);

        $record->refresh();
        $this->assertSame('2026-09-18', $record->lead_date->format('Y-m-d'));
        $this->assertTrue((bool) $record->needs_review);
        $this->assertStringContainsString('suggests a sale', $record->review_reason);
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

    public function test_import_normalizes_phones_to_international_format(): void
    {
        $this->tenant->update(['country' => 'AE']);

        $csv = "Name,Phone\n"
            ."Doogie,+971501234567\n"
            ."Ed,0509876543\n"
            ."Farah,971555555555\n"
            ."Georges,\"001 971 44 123 456\"\n"
            ."Hans,+10015559988\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('fmt.csv', $csv),
            'portal' => 'bayut',
        ])->assertRedirect(route('recycled.index'));

        $pool = RecycledLead::where('tenant_id', $this->tenant->id)->orderBy('id')->get()->pluck('phone', 'first_name');

        $this->assertSame('+971501234567', $pool['Doogie']);
        $this->assertSame('+971509876543', $pool['Ed']);
        $this->assertSame('+971555555555', $pool['Farah']);
        $this->assertSame('+197144123456', $pool['Georges']);
        $this->assertSame('+10015559988', $pool['Hans']);
    }

    public function test_import_notifies_when_rows_are_dropped_for_missing_contact(): void
    {
        $csv = "Name,Phone\n"
            ."Ghl,+971501234567\n"
            ."Hadi,,\n";

        $this->post(route('recycled.import'), [
            'file' => UploadedFile::fake()->createWithContent('drop.csv', $csv),
            'portal' => 'bayut',
        ])
            ->assertRedirect(route('recycled.index'))
            ->assertSessionHas('success', fn ($message) => str_contains((string) $message, 'dropped — no phone / WhatsApp / email'));

        $this->assertSame(1, RecycledLead::where('tenant_id', $this->tenant->id)->count());
    }

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

    public function test_import_skips_report_preamble_rows_before_the_real_header(): void
    {
        $csv = implode("\n", [
            'Overview',
            'Agency Name | Pristine Properties',
            'Report Title | Email Leads',
            'SEARCH CRITERIA',
            'Date Range | 23/Sep/2025 to 22/Sep/2026',
            'Email Leads',
            'Date,Lead Name,Lead Email,Lead Number,Reference No.,Price (AED),Location,Sub Location,Message',
            '2026-09-17,Hadeel Elsir,Hadeel99@gmail.com,971559531944,10219-J70XlC,165000,Al Reem Island,Canal Residence,Interested',
            '2026-06-24,Thember Mosese,mosesethember@gmail.com,971586876959,10219-xPp32r,80000,Masdar City,The Gate,How much?',
            '2026-01-10,No Reach Person,,,,-,-,-,-,only a name no contact',
            'Unknown,Unknown,unknown,,-,-,-,-,no contact possible',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');

        unlink($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $result['skipped_no_contact']);

        $pool = RecycledLead::where('tenant_id', $this->tenant->id)->orderBy('id')->get();

        $this->assertSame('bayut', $pool[0]->portal);
        $this->assertSame('Hadeel', $pool[0]->first_name);
        $this->assertSame('Elsir', $pool[0]->last_name);
        $this->assertSame('hadeel99@gmail.com', $pool[0]->email);
        $this->assertSame('+971559531944', $pool[0]->phone);
        $this->assertNull($pool[0]->reference);
        $this->assertSame(165000.0, (float) $pool[0]->gross_price);
        $this->assertSame('Al Reem Island - Canal Residence', $pool[0]->purchased_project);
        $this->assertStringContainsString('Interested', (string) $pool[0]->notes);

        $this->assertSame('Thember', $pool[1]->first_name);
        $this->assertSame('Masdar City - The Gate', $pool[1]->purchased_project);
    }

    public function test_import_stores_the_bayut_date_column_as_lead_date(): void
    {
        $csv = implode("\n", [
            'Date,Lead Name,Lead Email,Lead Number,Reference No.,Price (AED),Location,Sub Location,Message',
            '2026-09-17,Hadeel Elsir,Hadeel99@gmail.com,971559531944,10219-J70XlC,165000,Al Reem Island,Canal Residence,Interested',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');

        unlink($path);

        $record = RecycledLead::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->assertSame('2026-09-17', $record->lead_date->toDateString());
    }

    public function test_import_stores_the_property_finder_export_columns(): void
    {
        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,whatsapp_username,lead_type,project_name,developer_name,agent_name,call_talk_time,call_wait_time,call_record_file,listing_reference,created_at,status,tags,enrichment',
            'whatsapp,Anu,+971552727681,,,listing,,,Cristine Gulayan,,,,AD-R-11313326,2024-11-30 14:47:26 +0000 UTC,replied,,',
            'call,,+971544575018,,,listing,,,Muhammad Hamza,0,0,,,2025-10-22 08:26:05 +0000 UTC,sent,,',
            'email,Sara Ahmed,,sara@example.com,,listing,,,Agent One,,,,BD-R-1,2025-04-30 10:09:05 +0000 UTC,replied,,',
            'whatsapp,Agent Page Lead,+971551111222,,,agent,,,Cristine Gulayan,,,,AP-1,2025-04-28 09:00:00 +0000 UTC,replied,,',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');

        unlink($path);

        $this->assertSame(4, $result['imported']);

        // A PF "agent" lead type is a buyer enquiring via an agent's page, so
        // it stays in the pool — unlike Bayut's mirrored agent rows.
        $this->assertDatabaseHas('recycled_leads', ['phone' => '+971551111222', 'portal' => 'property_finder']);

        $whatsapp = RecycledLead::where('phone', '+971552727681')->firstOrFail();
        $this->assertSame('Anu', $whatsapp->first_name);
        $this->assertSame('2024-11-30', $whatsapp->lead_date->toDateString());
        $this->assertSame('whatsapp', $whatsapp->category);
        $this->assertSame('property_finder', $whatsapp->portal);

        // A call row carries no sender_name — the phone is the only contact.
        $call = RecycledLead::where('phone', '+971544575018')->firstOrFail();
        $this->assertNull($call->first_name);
        $this->assertSame('phone', $call->category);

        $email = RecycledLead::where('email', 'sara@example.com')->firstOrFail();
        $this->assertSame('Sara', $email->first_name);
        $this->assertSame('Ahmed', $email->last_name);
        $this->assertSame('email', $email->category);
    }

    public function test_import_drops_property_finder_rows_tagged_from_agent(): void
    {
        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,whatsapp_username,lead_type,project_name,developer_name,agent_name,call_talk_time,call_wait_time,call_record_file,listing_reference,created_at,status,tags,enrichment',
            'whatsapp,Agent Self,+971551111999,,,listing,,,Cristine Gulayan,,,,AD-R-1,2025-04-30 14:47:26 +0000 UTC,replied,from_agent,',
            'whatsapp,Real Buyer,+971551111888,,,listing,,,Cristine Gulayan,,,,AD-R-2,2025-04-30 15:47:26 +0000 UTC,replied,,',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');

        unlink($path);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['dropped_agent']);
        $this->assertDatabaseMissing('recycled_leads', ['phone' => '+971551111999']);
        $this->assertDatabaseHas('recycled_leads', ['phone' => '+971551111888']);
    }

    public function test_import_stores_the_property_finder_whatsapp_username_and_call_recording(): void
    {
        $recording = 'https://pf-ae-documents.s3.ap-southeast-1.amazonaws.com/calls/2025/04/30/abc123.mp3?X-Amz-Expires=604800';

        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,whatsapp_username,call_record_file,listing_reference,created_at,status,tags',
            'call,Voice Only,+971551222333,,handle_abc,'.$recording.',MK-S-13688726,2025-04-30 10:00:00 +0000 UTC,sent,,',
            'whatsapp,Handle Only,,,only_handle,,,,2025-04-29 10:00:00 +0000 UTC,replied,,',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');

        unlink($path);

        $call = RecycledLead::where('phone', '+971551222333')->firstOrFail();
        $this->assertSame('handle_abc', $call->whatsapp_username);
        $this->assertSame($recording, $call->call_recording_url);

        // A username alone is a way to reach someone, so the row is kept.
        $this->assertSame(2, $result['imported']);
        $handle = RecycledLead::where('whatsapp_username', 'only_handle')->firstOrFail();
        $this->assertNull($handle->phone);
        $this->assertNull($handle->email);

        $this->get(route('recycled.show', $call))
            ->assertOk()
            ->assertSee('handle_abc')
            ->assertSee('<audio controls preload="none"', false);
    }

    public function test_import_reads_the_pristine_listing_reference_for_rent_and_sale(): void
    {
        $this->tenant->update([
            'name' => 'Pristine Properties',
            'website' => 'https://pristineproperties.ae',
        ]);

        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Rent Lead,+971551111001,,AD-R-11313326,2025-04-30 10:00:00 +0000 UTC',
            'whatsapp,Sale Lead,+971551111002,,MK-S-13688726,2025-04-30 11:00:00 +0000 UTC',
            'whatsapp,No Signal,+971551111003,,GRN28-NZ,2025-04-30 12:00:00 +0000 UTC',
            // The marker segment is the signal; the shape around it varies by
            // project, so a fixed segment count would miss all of these.
            'whatsapp,Extra Segments,+971551111004,,NF-R-K-19272871921,2025-04-30 13:00:00 +0000 UTC',
            'whatsapp,Bare Marker,+971551111005,,NF-R,2025-04-30 14:00:00 +0000 UTC',
            'whatsapp,Bare Sale,+971551111006,,NF-S,2025-04-30 15:00:00 +0000 UTC',
            'whatsapp,Building Sale,+971551111007,,LS-BR-S-KIUYTFDC,2025-04-30 16:00:00 +0000 UTC',
            'whatsapp,Size Sale,+971551111008,,NF-S-YGC55SF1.2M,2025-04-30 17:00:00 +0000 UTC',
            'whatsapp,Building Rent,+971551111009,,NF-BR-R-11313326,2025-04-30 18:00:00 +0000 UTC',
            // "BR" and "PROPERTIES" are not markers, and the AANZ/NZ agency
            // suffixes carry no transaction at all.
            'whatsapp,Agency Ref,+971551111010,,GRN28-AANZ,2025-04-30 19:00:00 +0000 UTC',
            'whatsapp,Office Ref,+971551111011,,PRISTINE-PROPERTIES-41820,2025-04-30 20:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');

        unlink($path);

        $this->assertSame('rent', RecycledLead::where('phone', '+971551111001')->firstOrFail()->original_deal_type);
        $this->assertSame('sale', RecycledLead::where('phone', '+971551111002')->firstOrFail()->original_deal_type);
        $this->assertSame('rent', RecycledLead::where('phone', '+971551111004')->firstOrFail()->original_deal_type);
        $this->assertSame('rent', RecycledLead::where('phone', '+971551111005')->firstOrFail()->original_deal_type);
        $this->assertSame('sale', RecycledLead::where('phone', '+971551111006')->firstOrFail()->original_deal_type);
        $this->assertSame('sale', RecycledLead::where('phone', '+971551111007')->firstOrFail()->original_deal_type);
        $this->assertSame('sale', RecycledLead::where('phone', '+971551111008')->firstOrFail()->original_deal_type);
        $this->assertSame('rent', RecycledLead::where('phone', '+971551111009')->firstOrFail()->original_deal_type);

        foreach (['+971551111003', '+971551111010', '+971551111011'] as $noSignal) {
            $this->assertNull(RecycledLead::where('phone', $noSignal)->firstOrFail()->original_deal_type);
        }

        // A resolved type is not up for review; only the unresolvable ones are.
        $this->assertSame('0', (string) RecycledLead::where('phone', '+971551111004')->firstOrFail()->needs_review);
        $this->assertSame('0', (string) RecycledLead::where('phone', '+971551111001')->firstOrFail()->needs_review);

        // No -R-/-S- segment means no signal, so it still needs a review.
        $unknown = RecycledLead::where('phone', '+971551111003')->firstOrFail();
        $this->assertNull($unknown->original_deal_type);
        $this->assertSame('1', (string) $unknown->needs_review);
    }

    public function test_a_later_upload_backfills_the_type_and_clears_the_stale_no_purpose_flag(): void
    {
        $this->tenant->update([
            'name' => 'Pristine Properties',
            'website' => 'https://pristineproperties.ae',
        ]);

        // The contact first appears in an old export whose references say
        // nothing about the transaction: imported untyped and flagged.
        $older = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Returning Contact,+971551111777,,GRN28-NZ,2024-05-02 10:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $older);
        $first = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $first['flagged_no_purpose']);
        $lead = RecycledLead::where('phone', '+971551111777')->firstOrFail();
        $this->assertNull($lead->original_deal_type);
        $this->assertSame('1', (string) $lead->needs_review);

        // A newer export has the same person on a rental reference. The row is
        // not duplicated; the type is filled in and the now-pointless flag goes.
        $newer = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Returning Contact,+971551111777,,NF-R-K-19272871921,2025-09-02 10:00:00 +0000 UTC',
        ]);

        file_put_contents($path, $newer);
        $second = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['duplicates']);
        $this->assertSame(1, RecycledLead::where('phone', '+971551111777')->count());

        $lead->refresh();
        $this->assertSame('rent', $lead->original_deal_type);
        $this->assertFalse((bool) $lead->needs_review);
        $this->assertNull($lead->review_reason);
    }

    public function test_a_reference_less_row_cannot_re_flag_a_contact_that_already_knows_its_type(): void
    {
        $this->tenant->update([
            'name' => 'Pristine Properties',
            'website' => 'https://pristineproperties.ae',
        ]);

        // First upload: the reference resolves the type, so no flag.
        $withReference = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Tenant,+971551111999,,MK-S-13688726,2025-09-02 10:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $withReference);
        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $lead = RecycledLead::where('phone', '+971551111999')->firstOrFail();
        $this->assertSame('sale', $lead->original_deal_type);
        $this->assertFalse((bool) $lead->needs_review);

        // An older export has the same contact with a reference that says
        // nothing. The type is already known, so this row must not re-flag it —
        // otherwise the flag depends on which file was uploaded last.
        $withoutReference = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Tenant,+971551111999,,GRN28-NZ,2024-05-02 10:00:00 +0000 UTC',
        ]);

        file_put_contents($path, $withoutReference);
        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $lead->refresh();
        $this->assertSame('sale', $lead->original_deal_type);
        $this->assertFalse((bool) $lead->needs_review);
        $this->assertNull($lead->review_reason);
        $this->assertSame(1, RecycledLead::where('phone', '+971551111999')->count());
    }

    public function test_a_contact_seen_twice_in_one_file_ends_up_typed_and_unflagged(): void
    {
        $this->tenant->update([
            'name' => 'Pristine Properties',
            'website' => 'https://pristineproperties.ae',
        ]);

        // Same contact twice: the reference-less row first, the rental second.
        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Tenant,+971551111888,,GRN28-NZ,2024-05-02 10:00:00 +0000 UTC',
            'whatsapp,Tenant,+971551111888,,NF-R-K-19272871921,2025-09-02 10:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);
        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(1, $result['flagged_no_purpose']);

        $lead = RecycledLead::where('phone', '+971551111888')->firstOrFail();
        $this->assertSame('rent', $lead->original_deal_type);
        $this->assertFalse((bool) $lead->needs_review);
        $this->assertNull($lead->review_reason);
    }

    public function test_the_no_purpose_flag_survives_when_the_row_is_flagged_for_something_else(): void
    {
        $this->tenant->update([
            'name' => 'Pristine Properties',
            'website' => 'https://pristineproperties.ae',
        ]);

        // A masked portal email is its own review reason, so the record stays
        // flagged even once a later reference resolves the transaction type.
        $masked = 'whatsapp.971501234567@id.bayut.com';

        $first = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Masked Contact,+971551111888,'.$masked.',,2024-05-02 10:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $first);
        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $lead = RecycledLead::where('phone', '+971551111888')->firstOrFail();
        $this->assertSame('1', (string) $lead->needs_review);
        $this->assertStringContainsString('Masked portal email', (string) $lead->review_reason);

        $second = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Masked Contact,+971551111888,'.$masked.',MK-S-13688726,2025-09-02 10:00:00 +0000 UTC',
        ]);

        file_put_contents($path, $second);
        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');
        unlink($path);

        $lead->refresh();
        $this->assertSame('sale', $lead->original_deal_type);
        $this->assertSame('1', (string) $lead->needs_review);
        $this->assertStringContainsString('Masked portal email', (string) $lead->review_reason);
        $this->assertStringNotContainsString('No Purpose column', (string) $lead->review_reason);
    }

    public function test_import_collapses_a_stray_trunk_zero_written_after_the_country_code(): void
    {
        // Property Finder writes "+9710521339166" where the number is really
        // "+971521339166" — there is no trunk zero in an E.164 number. The two
        // spellings must land on one pool row, not two.
        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Stray Zero,+9710521339166,,AD-R-11313326,2025-04-30 10:00:00 +0000 UTC',
            'whatsapp,Correct Number,+971521339166,,AD-R-11313327,2025-04-29 10:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');

        unlink($path);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(1, RecycledLead::where('phone', '+971521339166')->count());
        $this->assertSame(0, RecycledLead::where('phone', '+9710521339166')->count());
    }

    public function test_import_leaves_the_listing_reference_alone_for_other_tenants(): void
    {
        $csv = implode("\n", [
            'channel,sender_name,sender_phone,sender_email,listing_reference,created_at',
            'whatsapp,Some Buyer,+971551111001,,AD-R-11313326,2025-04-30 10:00:00 +0000 UTC',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'property_finder');

        unlink($path);

        $this->assertNull(RecycledLead::where('phone', '+971551111001')->firstOrFail()->original_deal_type);
    }

    public function test_import_flags_rows_without_a_purpose_column_for_review_without_guessing_the_type(): void
    {
        $csv = implode("\n", [
            'Date,Lead Name,Lead Email,Lead Number,Reference No.,Price (AED),Location,Sub Location,Message',
            '2026-09-17,Hadeel Elsir,Hadeel99@gmail.com,971559531944,10219-J70XlC,165000,Al Reem Island,Canal Residence,Interested',
            '2026-08-01,Sale Buyer,buyer@example.com,971550000001,SALE-REF,750000,Downtown,Burj Vista,Off the plan',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');

        unlink($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(2, $result['flagged_no_purpose']);

        $lease = RecycledLead::where('tenant_id', $this->tenant->id)->where('phone', '+971559531944')->firstOrFail();
        $this->assertNull($lease->original_deal_type);
        $this->assertSame('1', (string) $lease->needs_review);
        $this->assertStringContainsString('No Purpose column', (string) $lease->review_reason);
        $this->assertStringContainsString('suggests a lease', (string) $lease->review_reason);

        $sale = RecycledLead::where('tenant_id', $this->tenant->id)->where('email', 'buyer@example.com')->firstOrFail();
        $this->assertNull($sale->original_deal_type);
        $this->assertSame('1', (string) $sale->needs_review);
        $this->assertStringContainsString('suggests a sale', (string) $sale->review_reason);
    }

    public function test_import_with_a_purpose_column_does_not_flag_for_review(): void
    {
        $csv = "Name,Phone,Email,Deal Type\nMona,+971501234567,mona@example.com,rent\n";

        $result = null;
        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        try {
            $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');
        } finally {
            unlink($path);
        }

        $this->assertSame(0, $result['flagged_no_purpose']);

        $record = RecycledLead::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('rent', $record->original_deal_type);
        $this->assertNull($record->review_reason);
    }

    public function test_index_filters_by_lead_date_range(): void
    {
        RecycledLead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Early',
            'lead_date' => now()->subDays(40),
        ]);
        RecycledLead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'OnTheEdge',
            'lead_date' => now()->subDays(3),
        ]);
        RecycledLead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Recent',
            'lead_date' => now(),
        ]);

        $from = now()->subDays(10)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $response = $this->get(route('recycled.index', ['date_from' => $from, 'date_to' => $to]));

        $response->assertOk()
            ->assertSee('Recent')
            ->assertSee('OnTheEdge')
            ->assertDontSee('Early');
    }

    public function test_import_persists_the_email_review_flag_for_masked_addresses(): void
    {
        $csv = "Name,Phone,Email\nRae,+971501234567,rae@privaterelay.apple.com\n";

        $path = tempnam(sys_get_temp_dir(), 'rcl').'.csv';
        file_put_contents($path, $csv);

        try {
            $result = app(RecycledLeadsImportService::class)->import($path, 'csv', $this->tenant->id, $this->adminUser->id, 'bayut');
        } finally {
            unlink($path);
        }

        $this->assertSame(1, $result['flagged_review']);

        $record = RecycledLead::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('1', (string) $record->needs_review);
        $this->assertStringContainsString('private relay', (string) $record->review_reason);
        $this->assertSame('rae@privaterelay.apple.com', $record->email);
    }

    public function test_bulk_assign_assigns_selected_rows_to_an_agent(): void
    {
        $first = RecycledLead::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Abe']);
        $second = RecycledLead::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ben']);

        $this->post(route('recycled.bulkAssign'), [
            'ids' => [$first->id, $second->id],
            'agent_id' => $this->adminUser->id,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame($this->adminUser->id, $first->fresh()->assignee_id);
        $this->assertSame($this->adminUser->id, $second->fresh()->assignee_id);
    }
}
