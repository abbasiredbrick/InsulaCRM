<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Notifications\TaskActivityNotification;
use App\Notifications\TaskAssigned;
use Tests\TestCase;

class TaskAssignmentTest extends TestCase
{

    public function test_admin_can_assign_a_task_to_another_agent(): void
    {
        $this->actingAsAdmin();
        $assignee = $this->createUserWithRole('agent');
        $lead = $this->createLead();

        $response = $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Send contract to client',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'due_time' => '10:00',
            'assigned_to' => $assignee->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Send contract to client')->first();

        $this->assertNotNull($task);
        $this->assertSame($assignee->id, $task->agent_id, 'Task should be assigned to the chosen agent.');
        $this->assertSame($this->adminUser->id, $task->created_by, 'The creator should become the task owner.');
        $this->assertSame('10:00', $task->due_time);

        $this->assertDatabaseHas('task_activities', [
            'tenant_id' => $this->tenant->id,
            'task_id' => $task->id,
            'agent_id' => $this->adminUser->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $assignee->id,
            'type' => TaskAssigned::class,
        ]);
    }

    public function test_self_assigned_task_does_not_notify_the_creator(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $response = $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Call lead back',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $this->adminUser->id,
        ]);

        $response->assertSessionHasNoErrors();

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Call lead back')->first();

        $this->assertNotNull($task);
        $this->assertSame($this->adminUser->id, $task->agent_id);
        $this->assertSame($this->adminUser->id, $task->created_by);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => TaskAssigned::class,
        ]);
    }

    public function test_assignee_logging_activity_notifies_the_owner(): void
    {
        $this->actingAsAdmin();
        $assignee = $this->createUserWithRole('agent');
        $lead = $this->createLead(['agent_id' => $assignee->id]);

        $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Negotiate price',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $assignee->id,
        ]);

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Negotiate price')->firstOrFail();

        $this->actingAs($assignee);

        $response = $this->post(route('tasks.activity', $task), [
            'body' => 'Client countered at 950k.',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('task_activities', [
            'tenant_id' => $this->tenant->id,
            'task_id' => $task->id,
            'agent_id' => $assignee->id,
            'body' => 'Client countered at 950k.',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => TaskActivityNotification::class,
        ]);
    }

    public function test_assignee_completing_a_task_notifies_the_owner(): void
    {
        $this->actingAsAdmin();
        $assignee = $this->createUserWithRole('agent');
        $lead = $this->createLead(['agent_id' => $assignee->id]);

        $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Collect signed contract',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $assignee->id,
        ]);

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Collect signed contract')->firstOrFail();

        $this->actingAs($assignee);

        $response = $this->patchJson(route('tasks.toggle', $task));

        $response->assertJson(['success' => true, 'is_completed' => true]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => TaskActivityNotification::class,
        ]);
    }

    public function test_owner_can_complete_a_task_assigned_to_someone_else(): void
    {
        $this->actingAsAdmin();
        $assignee = $this->createUserWithRole('agent');
        $lead = $this->createLead();

        $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Prepare visit list',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $assignee->id,
        ]);

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Prepare visit list')->firstOrFail();

        $response = $this->patchJson(route('tasks.toggle', $task));

        $response->assertJson(['success' => true, 'is_completed' => true]);
    }

    public function test_unrelated_agent_cannot_toggle_or_log_on_a_task(): void
    {
        $this->actingAsAdmin();
        $assignee = $this->createUserWithRole('agent');
        $lead = $this->createLead();

        $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Follow up with bank',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $assignee->id,
        ]);

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Follow up with bank')->firstOrFail();

        $outsider = $this->createUserWithRole('agent');
        $this->actingAs($outsider);

        $this->patch(route('tasks.toggle', $task))->assertForbidden();
        $this->post(route('tasks.activity', $task), ['body' => 'nope'])->assertForbidden();
    }

    public function test_reassigning_a_task_notifies_the_new_assignee(): void
    {
        $this->actingAsAdmin();
        $first = $this->createUserWithRole('agent');
        $second = $this->createUserWithRole('agent');
        $lead = $this->createLead();

        $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Confirm viewing',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $first->id,
        ]);

        $task = Task::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)
            ->where('title', 'Confirm viewing')->firstOrFail();

        $response = $this->put(route('tasks.update', $task), [
            'title' => 'Confirm viewing',
            'due_date' => now()->addDay()->format('Y-m-d'),
            'assigned_to' => $second->id,
        ]);

        $response->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame($second->id, $task->agent_id);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $second->id,
            'type' => TaskAssigned::class,
        ]);
    }
}