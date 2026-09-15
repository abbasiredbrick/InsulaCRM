<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\OpenHouse;
use App\Models\Showing;
use App\Models\Task;
use App\Notifications\CalendarReminderNotification;
use App\Services\Cloud\CloudCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CalendarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function reAdmin(array $tenantOverrides = []): self
    {
        return $this->actingAsAdmin($tenantOverrides);
    }

    public function test_scheduling_showings_is_allowed_without_any_calendar_connection(): void
    {
        $this->reAdmin(['business_mode' => 'realestate']);
        $property = $this->createProperty();
        $lead = $this->createLead();

        $response = $this->post('/showings', [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '14:00',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('showings', [
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_meeting_can_be_created_without_any_calendar_connection(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $response = $this->post(route('leads.meetings.store', $lead), [
            'title' => 'Progress call',
            'scheduled_at' => now()->addDays(1)->format('Y-m-d H:i'),
            'duration_minutes' => 30,
            'reminder_minutes' => 60,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'agent_id' => $this->adminUser->id,
            'title' => 'Progress call',
            'reminder_minutes' => 60,
        ]);
    }

    public function test_task_can_be_created_without_any_calendar_connection(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $response = $this->post(route('leads.tasks.store', $lead), [
            'title' => 'Follow up',
            'due_date' => now()->addDays(2)->format('Y-m-d'),
            'reminder_minutes' => 1440,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tasks', [
            'agent_id' => $this->adminUser->id,
            'title' => 'Follow up',
            'reminder_minutes' => 1440,
        ]);
    }

    public function test_sync_is_skipped_when_integration_is_disabled(): void
    {
        $this->reAdmin(['calendar_sync_enabled' => false, 'business_mode' => 'realestate']);
        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty()->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
        ]);

        $service = app(CloudCalendarService::class);
        $this->assertFalse($service->integrationEnabled($showing, $this->adminUser));
        $this->assertFalse($service->shouldSyncExternally($showing, $this->adminUser));

        $service->sync($showing, $this->adminUser);

        $this->assertNull($showing->fresh()->calendar_event_id);
    }

    public function test_scheduling_is_always_allowed(): void
    {
        $this->reAdmin();

        $this->assertTrue(app(CloudCalendarService::class)->schedulerAllowed($this->adminUser));
    }

    public function test_reminder_command_sends_notification_for_upcoming_showing(): void
    {
        Notification::fake();
        $this->reAdmin(['business_mode' => 'realestate']);
        $startsAt = now()->addMinutes(10);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty()->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => $startsAt->format('Y-m-d'),
            'showing_time' => $startsAt->format('H:i'),
            'status' => 'scheduled',
            'reminder_minutes' => 30,
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, CalendarReminderNotification::class);
        $this->assertNotNull($showing->fresh()->reminder_sent_at);
    }

    public function test_reminder_uses_tenant_default_when_event_has_no_override(): void
    {
        Notification::fake();
        $this->reAdmin(['business_mode' => 'realestate', 'calendar_reminder_default_minutes' => 30]);
        $startsAt = now()->addMinutes(10);

        Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty()->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => $startsAt->format('Y-m-d'),
            'showing_time' => $startsAt->format('H:i'),
            'status' => 'scheduled',
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, CalendarReminderNotification::class);
    }

    public function test_reminder_skips_events_managed_externally(): void
    {
        Notification::fake();
        $this->reAdmin(['business_mode' => 'realestate']);
        $startsAt = now()->addMinutes(10);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty()->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => $startsAt->format('Y-m-d'),
            'showing_time' => $startsAt->format('H:i'),
            'status' => 'scheduled',
            'reminder_minutes' => 30,
            'calendar_provider' => 'google',
            'calendar_event_id' => 'evt_1',
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNotNull($showing->fresh()->reminder_sent_at);
    }

    public function test_reminder_respects_tenant_notification_toggle(): void
    {
        Notification::fake();
        $this->reAdmin(['business_mode' => 'realestate', 'notification_preferences' => ['calendar_reminders' => false]]);
        $startsAt = now()->addMinutes(10);

        Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty()->id,
            'lead_id' => $this->createLead()->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => $startsAt->format('Y-m-d'),
            'showing_time' => $startsAt->format('H:i'),
            'status' => 'scheduled',
            'reminder_minutes' => 30,
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminder_command_sends_notification_for_meeting(): void
    {
        Notification::fake();
        $this->reAdmin();
        $lead = $this->createLead();

        Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'title' => 'Showcase',
            'scheduled_at' => now()->addMinutes(10),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'reminder_minutes' => 30,
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, CalendarReminderNotification::class);
    }

    public function test_reminder_command_sends_notification_for_open_house(): void
    {
        Notification::fake();
        $this->reAdmin(['business_mode' => 'realestate']);

        OpenHouse::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $this->createProperty()->id,
            'agent_id' => $this->adminUser->id,
            'event_date' => now()->addDays(1)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '15:00',
            'status' => 'scheduled',
            'reminder_minutes' => 10080,
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, CalendarReminderNotification::class);
    }

    public function test_reminder_command_sends_notification_for_task_created_tomorrow(): void
    {
        Notification::fake();
        $this->reAdmin();
        $lead = $this->createLead();

        Task::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'title' => 'Call tomorrow',
            'due_date' => now()->addDays(1)->format('Y-m-d'),
            'is_completed' => false,
            'reminder_minutes' => 10080,
        ]);

        $this->artisan('calendar:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, CalendarReminderNotification::class);
    }

    public function test_admin_can_toggle_calendar_integration_in_settings(): void
    {
        $this->reAdmin();

        $response = $this->put('/settings/calendar-integration', [
            'calendar_sync_enabled' => '0',
            'calendar_reminder_default_minutes' => '60',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'calendar_sync_enabled' => false,
            'calendar_reminder_default_minutes' => 60,
        ]);
    }

    public function test_reminder_default_settings_surface_on_the_schedule_forms(): void
    {
        $this->reAdmin(['business_mode' => 'realestate', 'calendar_reminder_default_minutes' => 60]);

        $response = $this->get('/showings/create');
        $response->assertOk();
        $response->assertSee('reminder_minutes');
    }
}
