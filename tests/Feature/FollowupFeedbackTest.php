<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Notifications\ScheduleFeedbackNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FollowupFeedbackTest extends TestCase
{
    private function reAdmin(array $overrides = []): self
    {
        return $this->actingAsAdmin(array_merge(['business_mode' => 'realestate'], $overrides));
    }

    private function viewingFor(Lead $lead, ?int $propertyId = null): Showing
    {
        return Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $propertyId ?? $this->createProperty()->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => '2026-04-15',
            'showing_time' => '14:00',
            'status' => 'completed',
        ]);
    }

    // ── Offer Requested: the checkbox that opens the deal ──────────────────

    public function test_offer_requested_checkbox_promotes_the_lead_and_creates_the_deal(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $showing = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Loved the view, wants to make an offer.',
            'offer_requested' => 1,
        ])->assertRedirect();

        $this->assertSame('offer_requested', $lead->fresh()->stage);
        $this->assertSame('offer_requested', $showing->fresh()->outcome);

        $deal = $lead->fresh()->deals()->first();
        $this->assertNotNull($deal);
        $this->assertSame('offer_requested', $deal->stage);
        $this->assertSame($showing->property_id, $deal->property_id);
    }

    public function test_feedback_without_the_checkbox_does_not_open_a_deal(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $showing = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Too small, asked to see another unit.',
        ])->assertRedirect();

        $this->assertSame('viewing_done', $lead->fresh()->stage);
        $this->assertSame(0, Deal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    /**
     * A later viewing of a different unit must never drag a lead backwards, and
     * must never open a second deal.
     */
    public function test_a_second_viewing_never_regresses_the_stage_or_duplicates_the_deal(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $first = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $first), [
            'feedback' => 'Wants to offer on this one.',
            'offer_requested' => 1,
        ])->assertRedirect();

        $second = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $second), [
            'feedback' => 'Liked the second unit too.',
        ])->assertRedirect();

        $this->assertSame('offer_requested', $lead->fresh()->stage);
        $this->assertSame(1, Deal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_re_saving_feedback_does_not_erase_the_offer_requested_outcome(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $showing = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'First pass.',
            'offer_requested' => 1,
        ])->assertRedirect();

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Correcting the notes.',
        ])->assertRedirect();

        $this->assertSame('offer_requested', $showing->fresh()->outcome);
    }

    public function test_offer_requested_is_forward_only_from_a_leasehold_in_negotiation(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'negotiating']);
        $showing = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Still negotiating on the first unit.',
            'offer_requested' => 1,
        ])->assertRedirect();

        $this->assertSame('negotiating', $lead->fresh()->stage);
    }

    public function test_a_sale_lead_is_not_promoted_by_a_viewing(): void
    {
        $this->reAdmin();

        $lead = $this->createLead(['deal_type' => 'sale', 'stage' => 'new_lead']);
        $showing = $this->viewingFor($lead);

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Asked about an offer.',
            'offer_requested' => 1,
        ])->assertRedirect();

        $this->assertSame('new_lead', $lead->fresh()->stage);
        $this->assertSame(0, Deal::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    public function test_the_offer_checkbox_is_offered_for_viewings_only(): void
    {
        $this->reAdmin();

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->createLead(['deal_type' => 'rent'])->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Call the landlord',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $this->post(route('followups.task.feedback', $task), [
            'feedback' => 'Landlord confirmed the terms.',
            'offer_requested' => 1,
        ])->assertRedirect();

        // A task is not a unit viewing, so the flag must be ignored outright.
        $this->assertSame(0, Deal::withoutGlobalScopes()->where('lead_id', $task->lead_id)->count());
    }

    public function test_schedules_hub_shows_tabs_for_all_three(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['deal_type' => 'rent']);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Call the landlord',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response = $this->get(route('schedules.index'));

        $response->assertOk();
        $response->assertSee('Viewings');
        $response->assertSee('Tasks');
        $response->assertSee('Meetings');
        $response->assertSee('Call the landlord');
    }

    public function test_viewing_feedback_is_logged_on_lead_activity(): void
    {
        $this->reAdmin();
        $property = $this->createProperty();
        $lead = $this->createLead(['deal_type' => 'rent']);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => '2026-04-15',
            'showing_time' => '14:00',
            'status' => 'completed',
        ]);

        $response = $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Client loved the garden.',
            'status' => 'completed',
        ]);

        $response->assertRedirect();
        $this->assertEquals('Client loved the garden.', $showing->fresh()->feedback);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'viewing',
            'subject' => 'Viewing feedback',
            'body' => 'Client loved the garden.',
        ]);
    }

    public function test_task_feedback_is_logged_on_lead_and_task_activity(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Follow up',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response = $this->post(route('followups.task.feedback', $task), [
            'feedback' => 'Client is comparing options.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'body' => 'Client is comparing options.',
        ]);
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'task',
            'subject' => 'Task feedback',
            'body' => 'Client is comparing options.',
        ]);
    }

    public function test_meeting_feedback_is_logged_on_lead_activity(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $meeting = Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'title' => 'Kickoff call',
            'scheduled_at' => now()->addDay(),
            'status' => 'completed',
        ]);

        $response = $this->post(route('followups.meeting.feedback', $meeting), [
            'feedback' => 'Agreed to view the unit on Saturday.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('Agreed to view the unit on Saturday.', $meeting->fresh()->feedback);
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Meeting feedback',
            'body' => 'Agreed to view the unit on Saturday.',
        ]);
    }

    public function test_inline_viewing_creation_from_lead_page(): void
    {
        $this->reAdmin()->withCalendar();
        $property = $this->createProperty();
        $lead = $this->createLead(['deal_type' => 'rent']);

        $response = $this->post(route('leads.showings.store', $lead), [
            'property_id' => $property->id,
            'showing_date' => '2026-04-15',
            'showing_time' => '10:00',
            'duration_minutes' => 30,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('showings', [
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'property_id' => $property->id,
        ]);
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'viewing',
            'subject' => 'Viewing scheduled',
        ]);
    }

    public function test_quick_log_uses_cards_without_dropdown_and_meeting(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['deal_type' => 'rent']);

        $response = $this->get(route('leads.show', $lead));

        $response->assertOk();
        $response->assertSee('activity-type-input');
        $response->assertSee('quick-log-btn');
        $response->assertDontSee('activity-type-select', false);
        $response->assertDontSee('Log Meeting');
    }

    public function test_quick_log_posts_the_selected_card_type(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $response = $this->post(route('leads.activities.store', $lead), [
            'type' => 'whatsapp',
            'subject' => 'Documents sent',
            'body' => 'Sent the unit photos.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'whatsapp',
            'subject' => 'Documents sent',
        ]);

        $this->assertSame(1, Activity::where('lead_id', $lead->id)->where('type', 'whatsapp')->count());
    }

    public function test_schedule_feedback_notifies_assigned_agent_and_managers(): void
    {
        $this->reAdmin();
        Notification::fake([ScheduleFeedbackNotification::class]);

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $assignedAgent = $this->createUserWithRole('agent');
        $assignedAgent->update(['reports_to' => $manager->id]);

        $lead = $this->createLead(['deal_type' => 'rent']);
        $property = $this->createProperty();

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $assignedAgent->id,
            'showing_date' => '2026-04-15',
            'showing_time' => '14:00',
            'status' => 'completed',
        ]);

        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Client wants to sign next week.',
        ])->assertRedirect();

        Notification::assertSentTo($assignedAgent, ScheduleFeedbackNotification::class);
        Notification::assertSentTo($manager, ScheduleFeedbackNotification::class);
        Notification::assertNotSentTo($this->adminUser, ScheduleFeedbackNotification::class);
    }

    public function test_schedule_feedback_does_not_notify_the_logger(): void
    {
        $this->reAdmin();
        Notification::fake([ScheduleFeedbackNotification::class]);

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);

        $lead = $this->createLead(['deal_type' => 'rent', 'agent_id' => $manager->id]);
        $property = $this->createProperty();

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $manager->id,
            'showing_date' => '2026-04-15',
            'showing_time' => '14:00',
            'status' => 'completed',
        ]);

        $this->actingAs($manager);
        $this->post(route('followups.viewing.feedback', $showing), [
            'feedback' => 'Client is comparing options.',
        ])->assertRedirect();

        Notification::assertNotSentTo($manager, ScheduleFeedbackNotification::class);
        Notification::assertSentTo($this->adminUser, ScheduleFeedbackNotification::class);
    }

    public function test_schedule_feedback_respects_tenant_preference(): void
    {
        $this->reAdmin(['notification_preferences' => ['schedule_feedback' => false]]);
        Notification::fake([ScheduleFeedbackNotification::class]);

        $manager = $this->createUserWithRole('agent');
        $manager->update(['reports_to' => $this->adminUser->id]);
        $assignedAgent = $this->createUserWithRole('agent');
        $assignedAgent->update(['reports_to' => $manager->id]);

        $lead = $this->createLead(['deal_type' => 'rent']);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $assignedAgent->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Send documents',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $this->post(route('followups.task.feedback', $task), [
            'feedback' => 'Documents sent.',
        ])->assertRedirect();

        Notification::assertNothingSent();
    }
}
