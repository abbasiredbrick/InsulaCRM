<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use App\Services\AgentCodeService;
use Tests\TestCase;

class AgentCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenantWithAdmin();
    }

    public function test_generates_code_from_first_and_last_name(): void
    {
        $this->assertSame('AJ00', app(AgentCodeService::class)->generate('Alice Johnson'));
        $this->assertSame('MA00', app(AgentCodeService::class)->generate('Mohammed Al Farsi'));
        $this->assertSame('DX00', app(AgentCodeService::class)->generate('Dana'));
    }

    public function test_codes_are_unique_for_same_initials(): void
    {
        $service = app(AgentCodeService::class);

        $a = $this->createUserWithRole('agent', ['name' => 'Alice Johnson']);
        $b = $this->createUserWithRole('agent', ['name' => 'Ahmed Jamal']);

        $this->assertSame('AJ00', $a->agent_code);
        $this->assertSame('AJ01', $b->agent_code);
        $this->assertSame('AJ02', $service->generate('Another Joseph'));
    }

    public function test_auto_generates_code_on_user_creation(): void
    {
        $user = $this->createUserWithRole('agent', ['name' => 'Alice Johnson']);

        $this->assertSame('AJ00', $user->agent_code);
    }

    public function test_code_for_property_uses_assigned_agent_code(): void
    {
        $agent = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07']);

        $property = $this->createProperty(['assigned_agent_id' => $agent->id]);

        $this->assertSame('AJ07', app(AgentCodeService::class)->codeForProperty($property));
    }

    public function test_code_for_property_falls_back_to_tenant_default(): void
    {
        $property = $this->createProperty(['assigned_agent_id' => null]);

        $this->assertSame('NA00', app(AgentCodeService::class)->codeForProperty($property));

        $this->tenant->update(['custom_options' => ['lead_reference' => ['fallback_agent_code' => 'OP99']]]);

        $this->assertSame('OP99', app(AgentCodeService::class)->codeForProperty($property->refresh()));
    }
}