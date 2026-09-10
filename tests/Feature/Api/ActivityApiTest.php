<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use Tests\TestCase;

class ActivityApiTest extends TestCase
{
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenantWithAdmin([
            'business_mode' => 'realestate',
            'api_key' => 'test-api-key-for-activities',
            'api_enabled' => true,
        ]);
        $this->headers = ['X-API-Key' => 'test-api-key-for-activities'];
    }

    public function test_store_activity_via_api(): void
    {
        $lead = $this->createLead();

        $response = $this->postJson('/api/v1/activities', [
            'lead_id' => $lead->id,
            'type' => 'whatsapp',
            'subject' => 'Sent brochure',
            'body' => 'WhatsApp follow-up message sent.',
        ], $this->headers);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('activities', [
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'type' => 'whatsapp',
        ]);
    }

    public function test_store_activity_via_api_updates_lead_status_and_temperature(): void
    {
        $lead = $this->createLead(['status' => 'inquiry', 'temperature' => 'cold']);

        $response = $this->postJson('/api/v1/activities', [
            'lead_id' => $lead->id,
            'type' => 'call',
            'subject' => 'Site visit',
            'status' => 'active_client',
            'temperature' => 'hot',
        ], $this->headers);

        $response->assertStatus(201);
        $lead->refresh();
        $this->assertSame('active_client', $lead->status);
        $this->assertSame('hot', $lead->temperature);
    }

    public function test_store_activity_via_api_rejects_invalid_status(): void
    {
        $lead = $this->createLead();

        $response = $this->postJson('/api/v1/activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
            'status' => 'bogus_status',
        ], $this->headers);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Validation failed.']);
        $this->assertArrayHasKey('status', $response->json('details'));
    }
}