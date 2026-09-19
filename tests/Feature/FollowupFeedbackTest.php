<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use Tests\TestCase;

class FollowupFeedbackTest extends TestCase
{
    private function reAdmin(): self
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_followups_page_shows_tabs_for_all_three(): void
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

        $response = $this->get(route('leads.followups.index', $lead));

        $response->assertOk();
        $response->assertSee('Viewings');
        $response->assertSee('Tasks');
        $response->assertSee('Meetings');
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
            'type' => 'meeting',
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
}