<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Notifications\TaskAssigned;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SchedulesHubTest extends TestCase
{
    private function reAdmin(array $overrides = []): self
    {
        return $this->actingAsAdmin(array_merge(['business_mode' => 'realestate'], $overrides));
    }

    public function test_hub_lists_viewings_meetings_and_tasks(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Kickoff call',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Send documents',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $response = $this->get(route('schedules.index'));

        $response->assertOk();
        $response->assertSee('Scheduling Hub');
        $response->assertSee('Kickoff call');
        $response->assertSee('Send documents');
        $response->assertSee('Ada Lovelace');
    }

    public function test_hub_uses_live_filter_search_ui(): void
    {
        $this->reAdmin();

        $response = $this->get(route('schedules.index'));

        $response->assertOk();
        $response->assertSee('data-live-filter', false);
        $response->assertSee('data-live-results', false);
        $response->assertSee('id="sched-tabs"', false);
        $response->assertSee('id="sched-list"', false);
        $response->assertDontSee('Filter');

        $filtered = $this->get(route('schedules.index', ['status' => 'scheduled']));
        $filtered->assertOk();
        $filtered->assertSee('Reset');
    }

    public function test_hub_filters_by_lead(): void
    {
        $this->reAdmin();
        $leadA = $this->createLead(['first_name' => 'Grace', 'last_name' => 'Hopper']);
        $leadB = $this->createLead(['first_name' => 'Barbara', 'last_name' => 'Liskov']);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $leadA->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Lead A task',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $leadB->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Lead B task',
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $response = $this->get(route('schedules.index', ['lead' => $leadA->id]));

        $response->assertOk();
        $response->assertSee('Grace Hopper');
        $response->assertSee('Lead A task');
        $response->assertDontSee('Lead B task');
    }

    public function test_status_filter_applies_across_all_tabs(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Completed task',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'completed',
        ]);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Scheduled task',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $response = $this->get(route('schedules.index', ['status' => 'completed']));

        $response->assertOk();
        $response->assertSee('Completed task');
        $response->assertDontSee('Scheduled task');
    }

    public function test_hub_create_meeting_logs_activity_and_redirects(): void
    {
        $this->reAdmin()->withCalendar();
        $lead = $this->createLead(['deal_type' => 'rent']);

        $response = $this->post(route('schedules.meetings.store'), [
            'lead_id' => $lead->id,
            'title' => 'Contract sync',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
            'duration_minutes' => 30,
            'notes' => 'Bring the contract',
        ]);

        $response->assertRedirect(route('schedules.index'));

        $this->assertDatabaseHas('meetings', [
            'lead_id' => $lead->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Contract sync',
        ]);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Meeting scheduled',
            'subject_type' => Meeting::class,
        ]);
    }

    public function test_hub_create_task_assigns_and_notifies(): void
    {
        $this->reAdmin()->withCalendar();
        Notification::fake([TaskAssigned::class]);

        $agent = $this->createUserWithRole('agent');
        $lead = $this->createLead();

        $response = $this->post(route('schedules.tasks.store'), [
            'lead_id' => $lead->id,
            'title' => 'Call the buyer',
            'due_date' => now()->addDays(3)->format('Y-m-d'),
            'due_time' => '11:00',
            'assigned_to' => $agent->id,
        ]);

        $response->assertRedirect(route('schedules.index'));

        $task = Task::where('title', 'Call the buyer')->where('lead_id', $lead->id)->first();
        $this->assertNotNull($task);
        $this->assertSame($agent->id, $task->agent_id);
        $this->assertSame('scheduled', $task->status);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'task',
            'subject' => 'Task created',
            'subject_type' => Task::class,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'body' => 'Task created and assigned to '.$agent->name.'.',
        ]);

        Notification::assertSentTo($agent, TaskAssigned::class);
    }

    public function test_hub_quick_status_update_for_viewing(): void
    {
        $this->reAdmin()->withCalendar();
        $lead = $this->createLead(['deal_type' => 'rent']);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $response = $this->patch(route('schedules.showing.status', $showing), [
            'status' => 'completed',
            'outcome' => 'interested',
        ]);

        $response->assertRedirect();

        $this->assertSame('completed', $showing->fresh()->status);
        $this->assertSame('interested', $showing->fresh()->outcome);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'viewing',
            'subject' => 'Unit viewed',
            'subject_type' => Showing::class,
        ]);
    }

    public function test_hub_quick_status_update_for_task(): void
    {
        $this->reAdmin()->withCalendar();
        $lead = $this->createLead();

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Finish reports',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $response = $this->patch(route('schedules.task.status', $task), [
            'status' => 'completed',
        ]);

        $response->assertRedirect();
        $this->assertSame('completed', $task->fresh()->status);

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'task',
            'subject' => 'Task Completed',
            'subject_type' => Task::class,
        ]);
    }

    public function test_hub_delete_meeting_via_post_route(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $meeting = Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Sync',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        $response = $this->post(route('schedules.meeting.delete', $meeting));

        $response->assertRedirect(route('leads.show', $lead));
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_agent_hub_scoping_shows_own_and_assigned_only(): void
    {
        $this->reAdmin(['business_mode' => 'realestate']);
        $agent = $this->createUserWithRole('listing_agent');

        $ownLead = $this->createLead(['first_name' => 'Agent', 'last_name' => 'Own']);
        $ownLead->update(['agent_id' => $agent->id]);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $ownLead->id,
            'agent_id' => $agent->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Own lead task',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $otherLead = $this->createLead(['first_name' => 'Admin', 'last_name' => 'Only']);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $otherLead->id,
            'agent_id' => $agent->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Assigned to agent',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $otherLead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Admin only task',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($agent);

        $response = $this->get(route('schedules.index'));

        $response->assertOk();
        $response->assertSee('Agent Own');
        $response->assertSee('Assigned to agent');
        $response->assertDontSee('Admin only task');
    }

    public function test_readonly_lead_search_within_scope(): void
    {
        $this->reAdmin(['business_mode' => 'realestate']);
        $this->createLead(['first_name' => 'Alan', 'last_name' => 'Turing', 'phone' => '+1234567890']);

        $response = $this->get(route('schedules.leads.search', ['q' => 'Alan']));

        $response->assertOk();
        $this->assertSame('Alan Turing · +1234567890', $response->json('results.0.label'));
    }

    public function test_edit_payload_returns_meeting_and_task_data(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $meeting = Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Room tour',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'created_by' => $this->adminUser->id,
            'title' => 'Prep lease',
            'due_date' => now()->addDay()->toDateString(),
            'status' => 'scheduled',
        ]);

        $meetingJson = $this->getJson(route('schedules.edit', ['type' => 'meeting', 'id' => $meeting->id]));
        $meetingJson->assertOk()->assertJsonPath('entity.title', 'Room tour');

        $taskJson = $this->getJson(route('schedules.edit', ['type' => 'task', 'id' => $task->id]));
        $taskJson->assertOk()->assertJsonPath('entity.title', 'Prep lease');
    }

    public function test_sync_writes_event_link_for_viewing_involved_users(): void
    {
        $this->reAdmin(['business_mode' => 'realestate']);
        $provider = \Mockery::mock(\App\Services\Cloud\CloudBaseProvider::class);
        $provider->shouldReceive('createCalendarEvent')->andReturn('evt-'.rand(1000, 9999));
        $provider->shouldReceive('updateCalendarEvent')->andReturn(null);
        $provider->shouldReceive('deleteCalendarEvent')->andReturn(null);

        $factory = \Mockery::mock(\App\Services\Cloud\CloudProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($provider);

        $this->app->instance(\App\Services\Cloud\CloudCalendarService::class, new \App\Services\Cloud\CloudCalendarService($factory));

        $viewingAgent = $this->createUserWithRole('agent');
        $this->adminUser->calendarConnections()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'google',
            'scope' => 'calendar',
            'provider_account_email' => $this->adminUser->email,
            'access_token' => 'tok-admin',
            'refresh_token' => 'rt-admin',
        ]);
        $viewingAgent->calendarConnections()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'google',
            'scope' => 'calendar',
            'provider_account_email' => $viewingAgent->email,
            'access_token' => 'tok-agent',
            'refresh_token' => 'rt-agent',
        ]);

        $lead = $this->createLead(['agent_id' => $viewingAgent->id]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $viewingAgent->id,
            'created_by' => $this->adminUser->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $response = $this->patch(route('schedules.showing.status', $showing), [
            'status' => 'scheduled',
        ]);
        $response->assertRedirect();

        $this->assertDatabaseHas('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $viewingAgent->id,
        ]);
        $this->assertDatabaseHas('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $this->adminUser->id,
        ]);
    }
}
