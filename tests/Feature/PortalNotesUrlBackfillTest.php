<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The backfill that moves portal enquiry URLs out of leads.notes and into
 * custom_fields, so one link renders once.
 */
class PortalNotesUrlBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function runMigration(string $direction = 'up'): void
    {
        $migration = require database_path('migrations/2026_09_30_000004_backfill_portal_lead_notes_urls.php');
        $migration->{$direction}();
    }

    public function test_it_promotes_a_bayut_url_and_keeps_the_message(): void
    {
        $url = 'https://www.bayut.com/property/details-16475863.html';

        // Bayut puts the client's message AND the URL in notes, with nothing
        // in custom_fields. Stripping naively would lose both.
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Naila',
            'last_name' => 'Farman',
            'lead_source' => 'bayut',
            'notes' => "Hi, I am interested in your property on Bayut. Link: {$url} Reference no.: 10219-GFPUOM",
        ]);

        $this->runMigration();

        $lead->refresh();

        $this->assertSame($url, $lead->custom_fields['listing_url']);
        $this->assertStringNotContainsString('bayut.com', $lead->notes);
        $this->assertStringContainsString('I am interested in your property', $lead->notes);
        $this->assertStringContainsString('10219-GFPUOM', $lead->notes, 'the reference number must survive');
    }

    public function test_it_clears_propertyfinder_notes_that_are_only_the_url(): void
    {
        $url = 'https://www.propertyfinder.ae/leads/v1/lead/message/abc/def';

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Aisha',
            'last_name' => 'Alsuwaidi',
            'lead_source' => 'property_finder',
            'notes' => $url,
            'custom_fields' => json_encode([
                'portal' => 'propertyfinder',
                'listing_url' => $url,
                'contact_link' => $url,
            ]),
        ]);

        $this->runMigration();

        $this->assertNull($lead->fresh()->notes);
        $this->assertSame(
            $url,
            $lead->fresh()->custom_fields['listing_url'],
            'the link must not be lost - it just moves out of notes'
        );
    }

    public function test_it_strips_a_bare_listing_link_line(): void
    {
        $url = 'https://www.bayut.com/property/details-16475863.html';

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Test',
            'last_name' => 'Lead',
            'lead_source' => 'bayut',
            'notes' => "Listing Link: {$url}",
        ]);

        $this->runMigration();

        $lead->refresh();

        $this->assertNull($lead->notes);
        $this->assertSame($url, $lead->custom_fields['listing_url']);
    }

    public function test_it_leaves_notes_without_urls_untouched(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Test',
            'last_name' => 'Lead',
            'lead_source' => 'manual',
            'notes' => 'Wants a 2 bed in Marina, budget 1.4M. Call after 5pm.',
        ]);

        $before = $lead->notes;
        $this->runMigration();

        $this->assertSame($before, $lead->fresh()->notes);
        $this->assertEmpty($lead->fresh()->custom_fields);
    }

    public function test_it_is_reversible(): void
    {
        $url = 'https://www.bayut.com/property/details-1.html';
        $original = "Interested: {$url}";

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Test',
            'last_name' => 'Lead',
            'lead_source' => 'bayut',
            'notes' => $original,
        ]);

        $this->runMigration();
        $this->assertNotSame($original, $lead->fresh()->notes);

        $this->runMigration('down');

        $this->assertSame($original, $lead->fresh()->notes);
        $this->assertFalse(
            DB::getSchemaBuilder()->hasTable('lead_notes_url_backfill'),
            'the snapshot table is dropped after a rollback'
        );
    }
}
