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

        $this->assertSame('2609-AJ07-0001', $service->format('2609', 'AJ07', 1));
        $this->assertSame('2610-AJ07-0123', $service->format('2610', 'AJ07', 123));
    }

    public function test_generated_reference_uses_agent_code_and_sequences_per_month(): void
    {
        $agent = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07']);

        $first = $this->createLead([
            'agent_id'   => $agent->id,
            'created_at' => Carbon::parse('2026-09-15'),
        ]);
        $second = $this->createLead([
            'agent_id'   => $agent->id,
            'created_at' => Carbon::parse('2026-09-20'),
        ]);

        $this->assertSame('2609-AJ07-0001', $first->reference);
        $this->assertSame('2609-AJ07-0002', $second->reference);
    }

    public function test_sequence_resets_for_agent_by_month(): void
    {
        $agent = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07']);

        $sept = $this->createLead(['agent_id' => $agent->id, 'created_at' => Carbon::parse('2026-09-05')]);
        $oct = $this->createLead(['agent_id' => $agent->id, 'created_at' => Carbon::parse('2026-10-05')]);

        $this->assertSame('2609-AJ07-0001', $sept->reference);
        $this->assertSame('2610-AJ07-0001', $oct->reference);
    }

    public function test_unassigned_lead_uses_fallback_agent_code(): void
    {
        $lead = $this->createLead([
            'agent_id'   => null,
            'created_at' => Carbon::parse('2026-09-15'),
        ]);

        $this->assertMatchesRegularExpression('/^2609-NA00-\d{4}$/', (string) $lead->reference);
    }

    public function test_fallback_agent_code_respects_tenant_settings(): void
    {
        $this->tenant->update(['custom_options' => ['lead_reference' => ['fallback_agent_code' => 'OP99']]]);

        $lead = $this->createLead([
            'agent_id'   => null,
            'created_at' => Carbon::parse('2026-09-15'),
        ]);

        $this->assertSame('2609-OP99-0001', $lead->reference);
    }

    public function test_existing_reference_is_kept(): void
    {
        $lead = $this->createLead([
            'reference'  => 'CUSTOM-1407',
            'created_at' => Carbon::parse('2026-09-15'),
        ]);

        $this->assertSame('CUSTOM-1407', $lead->reference);
    }

    public function test_preview_returns_well_formed_sample(): void
    {
        $sample = app(LeadReferenceService::class)->preview($this->tenant->id);

        $this->assertMatchesRegularExpression('/^\d{4}-[A-Z]{2}\d{2}-\d{4}$/', $sample);
    }
}