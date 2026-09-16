<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Services\LeadReferenceService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeadReferenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenantWithAdmin();
    }

    public function test_format_composes_complete_reference(): void
    {
        $service = app(LeadReferenceService::class);

        $this->assertSame('AJ2609001', $service->format('2609', 'AJ', 1));
        $this->assertSame('AJ2610123', $service->format('2610', 'AJ', 123));
    }

    public function test_generated_reference_uses_agent_code_and_sequences_per_month(): void
    {
        $agent = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ']);

        $first = $this->createLead([
            'agent_id'   => $agent->id,
            'created_at' => Carbon::parse('2026-09-15'),
        ]);
        $second = $this->createLead([
            'agent_id'   => $agent->id,
            'created_at' => Carbon::parse('2026-09-20'),
        ]);

        $this->assertSame('AJ2609001', $first->reference);
        $this->assertSame('AJ2609002', $second->reference);
    }

    public function test_sequence_resets_for_agent_by_month(): void
    {
        $agent = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ']);

        $sept = $this->createLead(['agent_id' => $agent->id, 'created_at' => Carbon::parse('2026-09-05')]);
        $oct = $this->createLead(['agent_id' => $agent->id, 'created_at' => Carbon::parse('2026-10-05')]);

        $this->assertSame('AJ2609001', $sept->reference);
        $this->assertSame('AJ2610001', $oct->reference);
    }

    public function test_unassigned_lead_uses_fallback_agent_code(): void
    {
        $lead = $this->createLead([
            'agent_id'   => null,
            'created_at' => Carbon::parse('2026-09-15'),
        ]);

        $this->assertMatchesRegularExpression('/^NA2609\d{3}$/', (string) $lead->reference);
    }

    public function test_fallback_agent_code_respects_tenant_settings(): void
    {
        $this->tenant->update(['custom_options' => ['lead_reference' => ['fallback_agent_code' => 'OP']]]);

        $lead = $this->createLead([
            'agent_id'   => null,
            'created_at' => Carbon::parse('2026-09-15'),
        ]);

        $this->assertSame('OP2609001', $lead->reference);
    }

    public function test_leads_with_same_initial_twin_codes_embed_distinct_codes(): void
    {
        $agentA = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ']);
        $agentB = $this->createUserWithRole('agent', ['name' => 'Ahmed Jamal', 'agent_code' => 'AH']);

        $leadA = $this->createLead(['agent_id' => $agentA->id, 'created_at' => Carbon::parse('2026-09-10')]);
        $leadB = $this->createLead(['agent_id' => $agentB->id, 'created_at' => Carbon::parse('2026-09-10')]);

        $this->assertSame('AJ2609001', $leadA->reference);
        $this->assertSame('AH2609001', $leadB->reference);
    }

    public function test_existing_reference_is_kept(): void
    {
        $lead = $this->createLead([
            'reference'  => 'CUSTOM-1407',
            'created_at' => Carbon::parse('2026-09-15'),
        ]);

        $this->assertSame('CUSTOM-1407', $lead->reference);
    }

    public function test_backfill_recomputes_every_reference_with_new_formula(): void
    {
        $agent = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ']);

        $leadA = $this->createLead(['agent_id' => $agent->id, 'created_at' => Carbon::parse('2026-08-10')]);
        $leadB = $this->createLead(['agent_id' => $agent->id, 'created_at' => Carbon::parse('2026-08-11')]);
        $leadA->forceFill(['reference' => '2608-AJ00-0001'])->save();
        $leadB->forceFill(['reference' => '2608-AJ00-0002'])->save();

        $this->artisan('leads:generate-references', ['--all' => true, '--limit' => 200])
            ->assertExitCode(0);

        $this->assertSame('AJ2608001', $leadA->refresh()->reference);
        $this->assertSame('AJ2608002', $leadB->refresh()->reference);
    }

    public function test_preview_returns_well_formed_sample(): void
    {
        $sample = app(LeadReferenceService::class)->preview($this->tenant->id);

        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{4}\d{3}$/', $sample);
    }
}