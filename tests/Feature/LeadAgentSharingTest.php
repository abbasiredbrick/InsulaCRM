<?php

namespace Tests\Feature;

use App\Models\LeadAgent;
use Tests\TestCase;

class LeadAgentSharingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    protected function createLeadOwnedBy($agent)
    {
        return $this->createLead(['agent_id' => $agent->id]);
    }

    public function test_owner_can_add_internal_co_agent_and_they_gain_access(): void
    {
        $owner = $this->createUserWithRole('agent');
        $coAgent = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        $this->actingAs($owner);

        $this->post(route('leads.coAgents.store', $lead), [
            'agent_id' => $coAgent->id,
            'commission_pct' => 20,
            'share_funding' => LeadAgent::FUNDING_FROM_AGENT,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue($lead->hasCoAgent($coAgent));

        $share = $lead->activeLeadAgents()->where('agent_id', $coAgent->id)->first();
        $this->assertSame('20.00', (string) $share->commission_pct);
        $this->assertSame(LeadAgent::FUNDING_FROM_AGENT, $share->share_funding);

        // The co-agent can now view the lead and sees it on their index.
        $this->actingAs($coAgent);

        $this->get(route('leads.show', $lead))->assertOk()->assertSee($coAgent->name);
        $this->get(route('leads.index'))->assertSee($lead->full_name);
        $this->get(route('leads.kanban'))->assertOk();
    }

    public function test_co_agent_can_log_activity_and_task_but_not_delete_lead(): void
    {
        $owner = $this->createUserWithRole('agent');
        $coAgent = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        $lead->leadAgents()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $coAgent->id,
            'status' => LeadAgent::STATUS_ACTIVE,
        ]);

        $this->actingAs($coAgent);

        $this->post(route('leads.activities.store', $lead), [
            'subject' => 'Follow-up call',
            'body' => 'Spoke with the client.',
            'type' => 'call',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'agent_id' => $coAgent->id,
        ]);

        $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Send brochure',
            'due_date' => now()->addDay()->format('Y-m-d'),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'lead_id' => $lead->id,
        ]);

        $this->delete(route('leads.destroy', $lead))->assertForbidden();
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_external_collaborators_cannot_be_added_manually(): void
    {
        $owner = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        $this->actingAs($owner);

        // External collaborators are created exclusively through A2A contracts;
        // manually sharing a lead is limited to internal colleagues.
        $this->post(route('leads.coAgents.store', $lead), [
            'external_name' => 'Freelancer One',
            'external_email' => 'freelancer@example.com',
            'external_company' => 'A2A Partners',
        ])->assertSessionHasErrors('agent_id');

        $this->assertSame(0, $lead->activeLeadAgents()->count());
    }

    public function test_cannot_add_owner_or_duplicate_co_agent(): void
    {
        $owner = $this->createUserWithRole('agent');
        $coAgent = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        $this->actingAs($owner);

        // Owner cannot be added as their own co-agent.
        $this->post(route('leads.coAgents.store', $lead), ['agent_id' => $owner->id])
            ->assertSessionHasErrors('co_agent');

        // First add succeeds...
        $this->post(route('leads.coAgents.store', $lead), ['agent_id' => $coAgent->id])
            ->assertSessionHasNoErrors();

        // ...and a duplicate is rejected.
        $this->post(route('leads.coAgents.store', $lead), ['agent_id' => $coAgent->id])
            ->assertSessionHasErrors('co_agent');
    }

    public function test_removing_co_agent_revokes_access_but_keeps_history(): void
    {
        $owner = $this->createUserWithRole('agent');
        $coAgent = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        $share = $lead->leadAgents()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $coAgent->id,
            'status' => LeadAgent::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        $this->delete(route('leads.coAgents.destroy', [$lead, $share]))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(LeadAgent::STATUS_REMOVED, $share->fresh()->status);
        $this->assertFalse($lead->fresh()->hasCoAgent($coAgent));

        $this->actingAs($coAgent);
        $this->get(route('leads.show', $lead))->assertForbidden();
    }

    public function test_unrelated_agent_cannot_view_or_share_lead(): void
    {
        $owner = $this->createUserWithRole('agent');
        $stranger = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        $this->actingAs($stranger);

        $this->get(route('leads.show', $lead))->assertForbidden();
        $this->get(route('leads.index'))->assertDontSee($lead->full_name);
        $this->post(route('leads.coAgents.store', $lead), ['agent_id' => $stranger->id])->assertForbidden();
    }

    public function test_manager_sees_leads_shared_with_their_team_and_admin_can_share(): void
    {
        $owner = $this->createUserWithRole('agent');
        $coAgent = $this->createUserWithRole('agent');
        $lead = $this->createLeadOwnedBy($owner);

        // Admin can add a co-agent on any lead.
        $this->actingAs($this->adminUser);

        $this->post(route('leads.coAgents.store', $lead), ['agent_id' => $coAgent->id])
            ->assertSessionHasNoErrors();

        // A manager whose team includes the co-agent (but not the owner) sees
        // the lead because one of their reports is participating in it.
        $manager = $this->createUserWithRole('agent');
        $coAgent->update(['reports_to' => $manager->id]);

        $this->actingAs($manager);
        $this->assertTrue($manager->isManager());
        $this->get(route('leads.index'))->assertSee($lead->full_name);
    }
}