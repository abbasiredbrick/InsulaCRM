<?php

namespace Tests\Feature;

use App\Models\PortalIntegration;
use App\Notifications\LeadAssigned;
use App\Services\AssignmentHistoryService;
use App\Services\Portals\PortalLeadService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnitLeadRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate', 'distribution_method' => 'round_robin']);
    }

    /**
     * A listing created manually on Property Finder carries no agent code, so
     * the reference recorded on the inventory unit is the only signal there is.
     */
    protected function pfIntegration(): PortalIntegration
    {
        return PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
        ]);
    }

    protected function pull(array $overrides = [])
    {
        return (new PortalLeadService)->createFromPayload($this->pfIntegration(), 'propertyfinder', array_merge([
            'name' => 'Sara Ahmed',
            'phone' => '+971501234567',
            'email' => '',
            'url' => 'https://www.propertyfinder.ae/en/property/details/111.html',
            'message' => 'Interested',
        ], $overrides));
    }

    protected function bayutIntegration(): PortalIntegration
    {
        return PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
        ]);
    }

    public function test_a_recorded_listing_reference_assigns_the_lead_to_the_unit_owner(): void
    {
        $owner = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => true,
        ]);

        $property = $this->createProperty([
            'propertyfinder_listing_reference' => 'BD2610002',
            'assign_leads_to_owner' => true,
            'assigned_agent_id' => $owner->id,
        ]);

        $lead = $this->pull(['id' => 'pf-1', 'reference' => 'BD2610002']);

        $this->assertNotNull($lead);
        $this->assertSame($owner->id, $lead->agent_id, 'the unit owner must take the lead');

        // The inventory unit is linked to the lead.
        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $lead->id, 'property_id' => $property->id,
        ]);

        // ...and the owner, not an admin, is who gets told about it.
        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $owner->id)
            ->where('type', LeadAssigned::class)
            ->count());

        // Assignment history reads audit rows carrying agent_id in new_values.
        $history = app(AssignmentHistoryService::class)->getHistory($lead);
        $this->assertTrue(
            $history->contains(fn ($entry) => $entry->new_agent === $owner->name),
            'the unit-owner assignment must be visible in the lead history'
        );
    }

    public function test_the_unit_owner_beats_the_agent_code_carried_by_the_reference(): void
    {
        $agentCodeAgent = $this->createUserWithRole('agent', [
            'name' => 'Alice Johnson', 'agent_code' => 'AJ',
            'is_active' => true, 'receives_leads' => true,
        ]);
        $owner = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => true,
        ]);

        $this->createProperty([
            'bayut_listing_id' => 'AJ-42',
            'assign_leads_to_owner' => true,
            'assigned_agent_id' => $owner->id,
        ]);

        $integration = $this->bayutIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id' => 'pf-2',
            'name' => 'Khalid Omar',
            'phone' => '+971502222222',
            'email' => '',
            'reference' => 'AJ-42',
            'message' => 'Interested',
        ]);

        $this->assertSame($owner->id, $lead->agent_id, 'the unit owner overrides the portal agent code');
        $this->assertNotSame($agentCodeAgent->id, $lead->agent_id);

        // The overridden agent is never emailed a lead they no longer hold.
        $this->assertSame(0, DB::table('notifications')
            ->where('notifiable_id', $agentCodeAgent->id)
            ->where('type', LeadAssigned::class)
            ->count());
    }

    public function test_the_unit_owner_keeps_the_lead_out_of_distribution(): void
    {
        $owner = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => true,
        ]);
        $pool = $this->createUserWithRole('agent', [
            'name' => 'Pool Agent', 'agent_code' => 'PA',
            'is_active' => true, 'receives_leads' => true,
        ]);

        $this->tenant->update([
            'custom_options' => array_merge(
                $this->tenant->custom_options ?? [],
                ['portal_leads' => ['unmatched' => 'distribute', 'notify_admins' => true]]
            ),
        ]);

        $this->createProperty([
            'propertyfinder_listing_reference' => 'BD2610003',
            'assign_leads_to_owner' => true,
            'assigned_agent_id' => $owner->id,
        ]);

        $lead = $this->pull(['id' => 'pf-3', 'reference' => 'BD2610003']);

        $this->assertSame($owner->id, $lead->agent_id, 'a matched unit owner stops distribution entirely');

        // Distribution would have told some other agent too. The owner winning
        // up front means exactly one hand-off happened, and it was to them.
        $notified = DB::table('notifications')
            ->where('type', LeadAssigned::class)
            ->pluck('notifiable_id');

        $this->assertCount(1, $notified, 'distribution must never run once the unit owner matches');
        $this->assertSame($owner->id, $notified->first());
        $this->assertSame(0, DB::table('notifications')
            ->where('notifiable_id', $pool->id)
            ->where('type', LeadAssigned::class)
            ->count());
    }

    /**
     * Control: proves the distribution pool is live in this fixture, so a passing
     * "the owner stopped distribution" assertion means something.
     */
    public function test_an_unmatched_lead_falls_into_the_distribution_pool(): void
    {
        $owner = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => true,
        ]);
        $pool = $this->createUserWithRole('agent', [
            'name' => 'Pool Agent', 'agent_code' => 'PA',
            'is_active' => true, 'receives_leads' => true,
        ]);

        $this->tenant->update([
            'custom_options' => array_merge(
                $this->tenant->custom_options ?? [],
                ['portal_leads' => ['unmatched' => 'distribute', 'notify_admins' => true]]
            ),
        ]);

        // No reference recorded on any unit: nothing can match.
        $lead = $this->pull(['id' => 'pf-control', 'reference' => 'NOREF-999']);

        $this->assertNotNull($lead->agent_id, 'an unmatched lead must be distributed');
        $this->assertNotSame($owner->id, $lead->agent_id);

        // Whoever round-robin picks, they are the one who was told about it.
        $notified = DB::table('notifications')
            ->where('type', LeadAssigned::class)
            ->pluck('notifiable_id');
        $this->assertTrue(
            $notified->contains($lead->agent_id),
            'the pool must notify whoever it hands the lead to'
        );
    }

    public function test_an_owner_out_of_rotation_falls_back_to_the_portal_route(): void
    {
        $agentCodeAgent = $this->createUserWithRole('agent', [
            'name' => 'Alice Johnson', 'agent_code' => 'AJ',
            'is_active' => true, 'receives_leads' => true,
        ]);
        $owner = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => false,
        ]);

        $property = $this->createProperty([
            'bayut_listing_id' => 'AJ-42',
            'assign_leads_to_owner' => true,
            'assigned_agent_id' => $owner->id,
        ]);

        $integration = $this->bayutIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id' => 'pf-4',
            'name' => 'Layla Said',
            'phone' => '+971503333333',
            'email' => '',
            'reference' => 'AJ-42',
            'message' => 'Interested',
        ]);

        $this->assertSame($agentCodeAgent->id, $lead->agent_id);
        $this->assertNotSame($owner->id, $lead->agent_id);

        // Refusing the lead must not stop the reference from linking the unit.
        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $lead->id, 'property_id' => $property->id,
        ]);
    }

    public function test_a_unit_that_has_not_opted_in_keeps_the_default_route(): void
    {
        $agentCodeAgent = $this->createUserWithRole('agent', [
            'name' => 'Alice Johnson', 'agent_code' => 'AJ',
            'is_active' => true, 'receives_leads' => true,
        ]);
        $owner = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => true,
        ]);

        $property = $this->createProperty([
            'bayut_listing_id' => 'AJ-42',
            'assign_leads_to_owner' => false,
            'assigned_agent_id' => $owner->id,
        ]);

        $integration = $this->bayutIntegration();

        $lead = (new PortalLeadService)->createFromPayload($integration, 'bayut', [
            'id' => 'pf-5',
            'name' => 'Omar Faris',
            'phone' => '+971504444444',
            'email' => '',
            'reference' => 'AJ-42',
            'message' => 'Interested',
        ]);

        $this->assertSame($agentCodeAgent->id, $lead->agent_id);
        $this->assertNotSame($owner->id, $lead->agent_id);

        // A unit that opts out still gets linked — opting out only refuses the
        // lead, it never hides the unit from it.
        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $lead->id, 'property_id' => $property->id,
        ]);
    }

    /**
     * The link is unconditional. Nothing about owner settings, rotation or
     * whether anyone can take the lead may make it disappear.
     */
    public function test_a_matched_reference_links_the_unit_even_when_no_one_takes_the_lead(): void
    {
        $other = $this->createUserWithRole('agent', [
            'name' => 'Unit Owner', 'agent_code' => 'UO',
            'is_active' => true, 'receives_leads' => false,
        ]);

        $property = $this->createProperty([
            'propertyfinder_listing_reference' => 'BD2610009',
            'assign_leads_to_owner' => false,
            'assigned_agent_id' => $other->id,
        ]);

        $lead = $this->pull(['id' => 'pf-link-only', 'reference' => 'BD2610009']);

        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $lead->id, 'property_id' => $property->id,
        ]);

        // And it is the same unit the receipt recorded on the lead itself.
        $this->assertSame($property->id, $lead->fresh()->custom_fields['listing_property_id'] ?? null);
        $this->assertNotSame($other->id, $lead->agent_id, 'this fixture must refuse the lead');
    }
}
