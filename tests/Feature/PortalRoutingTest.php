<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Models\Property;
use App\Models\User;
use App\Services\Portals\PortalLeadService;
use Tests\TestCase;

class PortalRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate', 'distribution_method' => 'round_robin']);
    }

    protected function createIntegration(array $overrides = []): PortalIntegration
    {
        return PortalIntegration::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'portal'    => 'bayut',
            'is_active' => true,
        ], $overrides));
    }

    public function test_lead_is_routed_to_agent_matching_listing_reference(): void
    {
        $owner = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07', 'is_active' => true]);
        $other = $this->createUserWithRole('agent', ['name' => 'Bob Smith', 'agent_code' => 'BS04', 'is_active' => true]);

        $property = $this->createProperty(['bayut_listing_id' => 'AJ07-42']);
        $integration = $this->createIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id'        => 'lead-1',
            'name'      => 'Sara Ahmed',
            'phone'     => '+971501234567',
            'email'     => '',
            'reference' => 'AJ07-42',
            'url'       => 'https://bayut.com/en/property/details-42',
            'message'   => 'Interested',
        ]);

        $this->assertNotNull($lead);
        $this->assertSame($owner->id, $lead->agent_id);
        $this->assertNotSame($other->id, $lead->agent_id);
        $this->assertSame($property->id, $lead->custom_fields['listing_property_id'] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-AJ07-\d{4}$/', (string) $lead->reference);
        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $lead->id, 'property_id' => $property->id,
        ]);
    }

    public function test_lead_from_old_plain_id_reference_still_links_property(): void
    {
        $property = $this->createProperty(['bayut_listing_id' => '42']);
        $integration = $this->createIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id'        => 'lead-2',
            'name'      => 'Khalid Omar',
            'phone'     => '+971502222222',
            'email'     => '',
            'reference' => '42',
        ]);

        $this->assertNotNull($lead);
        $this->assertSame($property->id, $lead->custom_fields['listing_property_id'] ?? null);
        $this->assertMatchesRegularExpression('/^\d{4}-NA00-\d{4}$/', (string) $lead->reference);
        $this->assertNull($lead->agent_id);
    }

    public function test_unroutable_agent_code_falls_through_to_distribution(): void
    {
        $this->tenant->update(['custom_options' => [
            'portal_leads' => ['unmatched' => 'distribute', 'notify_admins' => true],
        ]]);

        $property = $this->createProperty(['bayut_listing_id' => 'ZZ99-42']);
        $integration = $this->createIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id'        => 'lead-3',
            'name'      => 'Priya Sharma',
            'phone'     => '+971503333333',
            'email'     => '',
            'reference' => 'ZZ99-42',
        ]);

        $this->assertNotNull($lead);

        // No active user has code ZZ99, so the lead flows through the tenant's
        // round-robin pool and gets assigned to an active agent.
        $this->assertNotNull($lead->agent_id);
        $this->assertSame($property->id, $lead->custom_fields['listing_property_id'] ?? null);
    }

    public function test_unmatched_lead_stays_unassigned_and_notifies_admins(): void
    {
        $property = $this->createProperty(['bayut_listing_id' => 'ZZ99-42']);
        $integration = $this->createIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id'        => 'lead-9',
            'name'      => 'Priya Sharma',
            'phone'     => '+971503333333',
            'email'     => '',
            'reference' => 'ZZ99-42',
        ]);

        $this->assertNotNull($lead);
        $this->assertNull($lead->agent_id);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type'          => \App\Notifications\PortalLeadUnclaimed::class,
        ]);
    }

    public function test_returning_client_different_listing_attaches_opportunity(): void
    {
        $this->tenant->update(['country' => 'AE']);

        $owner = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07', 'is_active' => true]);
        $integration = $this->createIntegration();

        $first = $this->createProperty(['bayut_listing_id' => 'AJ07-10']);
        $service = new PortalLeadService;

        $lead = $service->createFromPayload($integration, 'bayut', [
            'id'        => 'lead-5',
            'name'      => 'Sara Ahmed',
            'phone'     => '0501234567',
            'email'     => '',
            'reference' => 'AJ07-10',
        ]);

        $this->assertNotNull($lead);
        $this->assertSame($owner->id, $lead->agent_id);
        $firstLeadId = $lead->id;

        $second = $this->createProperty(['bayut_listing_id' => 'AJ07-42']);
        $again = $service->createFromPayload($integration, 'bayut', [
            'id'        => 'lead-6',
            'name'      => 'Sara Ahmed',
            'phone'     => '+971 50 123 4567',
            'email'     => '',
            'reference' => 'AJ07-42',
        ]);

        // One portal record for the client; the new listing is attached as a second opportunity.
        $this->assertSame($firstLeadId, $again->id);
        $this->assertSame(1, Lead::withoutGlobalScopes()->where('lead_source', 'bayut')->count());
        $this->assertDatabaseHas('lead_property', ['lead_id' => $firstLeadId, 'property_id' => $second->id]);
        $this->assertTrue($again->custom_fields['returning_opportunity'] ?? false);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type'          => \App\Notifications\ReturningClientInterest::class,
        ]);
    }

    public function test_duplicate_same_listing_within_window_is_quiet(): void
    {
        $owner = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07', 'is_active' => true]);
        $integration = $this->createIntegration();
        $this->createProperty(['bayut_listing_id' => 'AJ07-42']);
        $service = new PortalLeadService;

        $payload = [
            'id'        => 'lead-7',
            'name'      => 'Sara Ahmed',
            'phone'     => '+971501234567',
            'email'     => '',
            'reference' => 'AJ07-42',
        ];

        $first = $service->createFromPayload($integration, 'bayut', $payload);
        // A re-inquiry for the same listing shortly after (new portal lead id).
        $second = $service->createFromPayload($integration, 'bayut', array_merge($payload, ['id' => 'lead-8']));

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Lead::withoutGlobalScopes()->where('lead_source', 'bayut')->count());
        $this->assertFalse($second->custom_fields['returning_opportunity'] ?? false);
        $this->assertDatabaseCount('lead_property', 1);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_dedup_prevents_duplicate_from_same_listing_reference(): void
    {
        $owner = $this->createUserWithRole('agent', ['name' => 'Alice Johnson', 'agent_code' => 'AJ07', 'is_active' => true]);
        $integration = $this->createIntegration();

        $payload = [
            'id'        => 'lead-4',
            'name'      => 'Sara Ahmed',
            'phone'     => '+971501234567',
            'email'     => '',
            'reference' => 'AJ07-42',
        ];

        $service = new PortalLeadService;
        $first = $service->createFromPayload($integration, 'bayut', $payload);
        $second = $service->createFromPayload($integration, 'bayut', $payload);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($owner->id, $first->agent_id);
        $this->assertDatabaseCount('leads', 1);
    }
}