<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadReassigned;
use App\Notifications\TeamLeadActivity;
use App\Services\TeamNotifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Hierarchy ────────────────────────────────────────────────────

    public function test_hierarchy_methods_traverse_reporting_chain(): void
    {
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $agentA  = $this->createUserWithRole('agent');
        $agentB  = $this->createUserWithRole('agent');

        $manager->update(['reports_to' => $this->adminUser->id]);
        $agentA->update(['reports_to' => $manager->id]);
        $agentB->update(['reports_to' => $manager->id]);

        $this->assertTrue($manager->fresh()->isManager());
        $this->assertFalse($agentA->fresh()->isManager());

        $ids = $manager->fresh()->teamUserIds();
        $this->assertContains($agentA->id, $ids);
        $this->assertContains($agentB->id, $ids);
        $this->assertCount(2, $ids);

        $this->assertTrue($manager->fresh()->managesUser($agentA));
        $this->assertTrue($manager->fresh()->managesUser($agentB));
        $this->assertTrue($this->adminUser->fresh()->managesUser($agentA));
        $this->assertTrue($this->adminUser->fresh()->managesUser($agentB));
        $this->assertFalse($agentA->fresh()->managesUser($manager));

        $chain = $agentA->fresh()->managerChain();
        $chainIds = array_map(fn ($u) => $u->id, $chain);
        $this->assertSame([$manager->id, $this->adminUser->id], $chainIds);

        $this->assertTrue($manager->fresh()->managesLead(
            Lead::create([
                'first_name' => 'Test', 'last_name' => 'Lead',
                'tenant_id' => $this->tenant->id, 'agent_id' => $agentA->id, 'status' => 'new',
            ])
        ));
    }

    // ── Team page access ────────────────────────────────────────────

    public function test_team_page_accessible_to_admin_and_manager_but_not_to_agent(): void
    {
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $agent = $this->createUserWithRole('agent');
        $agent->update(['reports_to' => $manager->id]);

        $this->actingAs($this->adminUser);
        $this->get(route('team.index'))->assertOk()->assertSee($manager->name);

        $this->actingAs($manager);
        $this->get(route('team.index'))->assertOk()->assertSee($agent->name);

        $this->actingAs($agent);
        $this->get(route('team.index'))->assertForbidden();
    }

    public function test_team_page_shows_member_metrics(): void
    {
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $agent = $this->createUserWithRole('agent');
        $agent->update(['reports_to' => $manager->id]);

        $this->createLead(['agent_id' => $agent->id, 'status' => 'contacted']);

        $this->actingAs($manager);
        $this->get(route('team.index'))
            ->assertOk()
            ->assertSee($agent->name)
            ->assertSeeText('1')
            ->assertSee('Open leads');
    }

    // ── set-manager ─────────────────────────────────────────────────

    public function test_set_manager_updates_reports_to(): void
    {
        $this->actingAsAdmin();

        $agentA = $this->createUserWithRole('agent');
        $agentB = $this->createUserWithRole('agent');

        $this->postJson(route('team.setManager'), [
            'user_id' => $agentB->id,
            'reports_to' => $agentA->id,
        ])->assertRedirect();

        $this->assertSame($agentA->id, $agentB->fresh()->reports_to);
        $this->assertDatabaseHas('audit_log', ['action' => 'team.reports_to_changed', 'model_id' => $agentB->id]);
    }

    public function test_set_manager_prevents_loops(): void
    {
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $agent = $this->createUserWithRole('agent');

        $manager->update(['reports_to' => $this->adminUser->id]);
        $agent->update(['reports_to' => $manager->id]);

        $this->postJson(route('team.setManager'), [
            'user_id' => $manager->id,
            'reports_to' => $agent->id,
        ])->assertUnprocessable();

        $this->postJson(route('team.setManager'), [
            'user_id' => $agent->id,
            'reports_to' => $agent->id,
        ])->assertUnprocessable();

        $this->assertSame($this->adminUser->id, $manager->fresh()->reports_to);
    }

    public function test_non_admin_cannot_change_manager(): void
    {
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $agent = $this->createUserWithRole('agent');
        $agent->update(['reports_to' => $manager->id]);

        $this->actingAs($manager);
        $this->postJson(route('team.setManager'), [
            'user_id' => $agent->id,
            'reports_to' => $manager->id,
        ])->assertForbidden();
    }

    // ── Lead policy: manager can view/manage team leads ─────────────

    public function test_manager_can_view_team_lead_but_stranger_agent_cannot(): void
    {
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $agent = $this->createUserWithRole('agent');
        $agent->update(['reports_to' => $manager->id]);
        $outsider = $this->createUserWithRole('agent');

        $lead = $this->createLead(['agent_id' => $agent->id]);

        $this->actingAs($manager);
        $this->get(route('leads.show', $lead))->assertOk();

        $this->actingAs($outsider);
        $this->get(route('leads.show', $lead))->assertForbidden();
    }

    public function test_plain_agent_cannot_reassign_a_lead(): void
    {
        $this->actingAsAdmin();

        $agent = $this->createUserWithRole('agent');
        $target = $this->createUserWithRole('agent');
        $lead = $this->createLead(['agent_id' => $agent->id]);

        $this->actingAs($agent);
        $this->postJson(route('leads.reassign', $lead), ['agent_id' => $target->id])->assertForbidden();
    }

    // ── Activity hours restriction removed ───────────────────────────

    public function test_activity_can_be_logged_outside_working_hours(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead();
        $this->actingAs($lead->agent);

        // Simulate 23:30 in the tenant timezone (America/New_York = UTC-4) → 03:30 UTC
        Carbon::setTestNow(now()->setTime(3, 30));

        $this->postJson(route('leads.activities.store', $lead), [
            'type' => 'note',
            'subject' => 'Test after-hours',
            'body' => 'Logged late at night',
        ])->assertRedirect();

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'subject' => 'Test after-hours',
        ]);
    }

    public function test_activity_still_blocked_when_lead_is_on_dnc(): void
    {
        $this->actingAsAdmin();

        $lead = $this->createLead(['do_not_contact' => true]);
        $this->actingAs($lead->agent);

        $this->postJson(route('leads.activities.store', $lead), [
            'type' => 'call',
            'body' => 'Blocked',
        ])->assertRedirect();
        $this->assertDatabaseMissing('activities', ['lead_id' => $lead->id, 'body' => 'Blocked']);
    }

    // ── Reassignment flow ───────────────────────────────────────────

    public function test_reassign_moves_lead_and_records_reason_and_notifies(): void
    {
        $this->actingAsAdmin();

        Notification::fake([LeadReassigned::class]);

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $from = $this->createUserWithRole('agent');
        $to   = $this->createUserWithRole('agent');
        $from->update(['reports_to' => $manager->id]);
        $to->update(['reports_to' => $manager->id]);

        $lead = $this->createLead(['agent_id' => $from->id]);

        $this->actingAs($manager);
        $this->postJson(route('leads.reassign', $lead), [
            'agent_id' => $to->id,
            'reason' => 'Not performing',
        ])->assertRedirect();

        $this->assertSame($to->id, $lead->fresh()->agent_id);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'lead.reassigned',
            'model_id' => $lead->id,
        ]);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type'    => 'note',
            'subject' => 'Reassigned to ' . $to->name,
            'body'    => 'Not performing',
        ]);

        Notification::assertSentTo($from, LeadReassigned::class);
        Notification::assertSentTo($to, LeadReassigned::class);
        // Managers in the new agent's chain (the admin) should be notified.
        Notification::assertSentTo($this->adminUser, LeadReassigned::class);
        // The acting manager should not self-notify.
        Notification::assertNotSentTo($manager, LeadReassigned::class);
    }

    public function test_reassign_by_owner_without_reason_still_works(): void
    {
        // The manager owns the lead and reassigns it to a teammate.
        $this->actingAsAdmin();

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $target = $this->createUserWithRole('agent');
        $target->update(['reports_to' => $manager->id]);

        $lead = $this->createLead(['agent_id' => $manager->id]);

        $this->actingAs($manager);
        $this->postJson(route('leads.reassign', $lead), [
            'agent_id' => $target->id,
        ])->assertRedirect();

        $this->assertSame($target->id, $lead->fresh()->agent_id);
        $this->assertDatabaseMissing('activities', [
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);
    }

    // ── Notifications dispatched on activity.logged hook ─────────────

    public function test_activity_on_team_lead_notifies_managers(): void
    {
        $this->actingAsAdmin();
        Notification::fake([TeamLeadActivity::class]);

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $agent = $this->createUserWithRole('agent');
        $agent->update(['reports_to' => $manager->id]);

        $lead = $this->createLead(['agent_id' => $agent->id]);

        $this->actingAs($agent);
        $this->postJson(route('leads.activities.store', $lead), [
            'type' => 'call',
            'subject' => 'Follow-up',
            'body' => 'Called the lead',
        ])->assertRedirect();

        Notification::assertSentTo($manager, TeamLeadActivity::class);
        Notification::assertNotSentTo($agent, TeamLeadActivity::class);
    }
}
