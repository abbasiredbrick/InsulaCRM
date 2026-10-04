<?php

namespace Tests\Feature;

use App\Models\CalendarEventLink;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Showing;
use App\Models\User;
use App\Models\UserCloudConnection;
use App\Services\Cloud\CloudBaseProvider;
use App\Services\Cloud\CloudCalendarService;
use App\Services\Cloud\CloudProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CalendarSyncFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function connectCalendar(User $user): UserCloudConnection
    {
        return UserCloudConnection::create([
            'user_id' => $user->id,
            'tenant_id' => $this->tenant->id,
            'provider' => 'google',
            'scope' => 'calendar',
            'provider_account_email' => $user->email,
            'access_token' => 'tok-'.$user->id,
            'refresh_token' => 'rt-'.$user->id,
        ]);
    }

    /**
     * Swap the container's CloudCalendarService for one whose provider writes
     * always fail, the way an expired refresh token does in production.
     */
    private function breakCalendarProvider(): void
    {
        $provider = Mockery::mock(CloudBaseProvider::class);
        $provider->shouldReceive('createCalendarEvent')
            ->andThrow(new \RuntimeException('Provider token request failed: invalid_grant'));
        $provider->shouldReceive('updateCalendarEvent')->andReturn(null);
        $provider->shouldReceive('deleteCalendarEvent')->andReturn(null);

        $factory = Mockery::mock(CloudProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($provider);

        $this->app->instance(CloudCalendarService::class, new CloudCalendarService($factory));
    }

    /**
     * A provider that records how many events it was asked to create, so a
     * regression can assert an event was actually pushed (not withdrawn).
     */
    private function workingProviderFactory(?CloudCalendarService &$service = null): CloudCalendarService
    {
        $provider = Mockery::mock(CloudBaseProvider::class);
        $provider->shouldReceive('createCalendarEvent')->andReturn('evt-'.uniqid());
        $provider->shouldReceive('updateCalendarEvent')->andReturn(null);
        $provider->shouldReceive('deleteCalendarEvent')->andReturn(null);

        $factory = Mockery::mock(CloudProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($provider);

        return $service = new CloudCalendarService($factory);
    }

    private function makeShowing(Lead $lead, Property $property, int $agentId): Showing
    {
        return Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $agentId,
            'created_by' => $this->adminUser->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);
    }

    public function test_scheduling_a_viewing_warns_when_the_calendar_write_fails(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($viewingAgent);
        $this->connectCalendar($mainAgent);

        $lead = $this->createLead(['agent_id' => $mainAgent->id]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $this->breakCalendarProvider();

        $response = $this->post(route('showings.store'), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $viewingAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
        ]);

        // The viewing is still saved — scheduling is never blocked by the calendar.
        $showing = Showing::where('lead_id', $lead->id)->first();
        $this->assertNotNull($showing);
        $response->assertRedirect(route('showings.show', $showing));

        $response->assertSessionHas('warning');
        $warning = session('warning');
        $this->assertStringContainsString($viewingAgent->name, $warning);
        $this->assertStringContainsString($mainAgent->name, $warning);

        $this->assertSame(0, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
    }

    public function test_scheduling_a_viewing_shows_no_warning_when_the_sync_succeeds(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($viewingAgent);
        $this->connectCalendar($mainAgent);

        $lead = $this->createLead(['agent_id' => $mainAgent->id]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $this->breakCalendarProvider();

        // No connections at all: nothing to write, and nothing broken either.
        $viewingAgent->calendarConnections()->delete();
        $mainAgent->calendarConnections()->delete();

        $response = $this->post(route('showings.store'), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $viewingAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
        ]);

        $response->assertSessionMissing('warning');
        $response->assertSessionHas('success', 'Viewing scheduled successfully.');
    }

    /**
     * Regression: a viewing created without an explicit status used to be read
     * back with status = null (the column default only lands in the database,
     * not in the in-memory model), so shouldRemove() treated the brand new
     * viewing as cancelled and sync() silently withdrew the event instead of
     * creating it. Nothing was logged, which is how BD2610002's viewing ended
     * up with no calendar entry anywhere.
     */
    public function test_a_newly_created_viewing_is_pushed_not_withdrawn(): void
    {
        $agent = $this->createUserWithRole('agent');
        $this->connectCalendar($agent);

        $lead = $this->createLead(['agent_id' => $agent->id]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $this->app->instance(CloudCalendarService::class, $this->workingProviderFactory());

        $this->post(route('showings.store'), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $agent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
        ])->assertSessionHasNoErrors();

        $showing = Showing::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame('scheduled', $showing->status);
        $this->assertSame(
            1,
            CalendarEventLink::where('eventable_type', Showing::class)
                ->where('eventable_id', $showing->id)->count(),
            'A newly scheduled viewing must get a calendar event.'
        );
    }

    public function test_a_newly_created_meeting_is_pushed_not_withdrawn(): void
    {
        $agent = $this->createUserWithRole('agent');
        $this->connectCalendar($agent);

        $lead = $this->createLead(['agent_id' => $agent->id]);

        $this->app->instance(CloudCalendarService::class, $this->workingProviderFactory());

        $this->post(route('leads.meetings.store', $lead), [
            'title' => 'Unit viewing',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();

        $meeting = \App\Models\Meeting::where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame('scheduled', $meeting->status);
        $this->assertSame(
            1,
            CalendarEventLink::where('eventable_type', \App\Models\Meeting::class)
                ->where('eventable_id', $meeting->id)->count(),
            'A newly scheduled meeting must get a calendar event.'
        );
    }

    public function test_reconcile_command_repairs_a_showing_with_no_event_links(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($viewingAgent);
        $this->connectCalendar($mainAgent);

        $lead = $this->createLead(['agent_id' => $mainAgent->id]);
        $showing = $this->makeShowing($lead, $this->createProperty(['lead_id' => $lead->id]), $viewingAgent->id);

        // Reproduce the production state: the viewing saved, nothing pushed.
        $this->assertSame(0, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());

        $this->artisan('calendar:reconcile-events --dry-run')
            ->expectsOutputToContain('would re-sync')
            ->assertSuccessful();

        $this->assertSame(0, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());

        // With a working provider the same run repairs it.
        $provider = Mockery::mock(CloudBaseProvider::class);
        $provider->shouldReceive('createCalendarEvent')->andReturn('evt-repaired');
        $provider->shouldReceive('updateCalendarEvent')->andReturn(null);
        $provider->shouldReceive('deleteCalendarEvent')->andReturn(null);

        $factory = Mockery::mock(CloudProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($provider);
        $this->app->instance(CloudCalendarService::class, new CloudCalendarService($factory));

        $this->artisan('calendar:reconcile-events')
            ->expectsOutputToContain('repaired')
            ->assertSuccessful();

        $this->assertSame(2, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
    }

    public function test_reconcile_command_leaves_cancelled_showings_alone(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($viewingAgent);

        $lead = $this->createLead(['agent_id' => $viewingAgent->id]);
        $showing = $this->makeShowing($lead, $this->createProperty(['lead_id' => $lead->id]), $viewingAgent->id);
        $showing->update(['status' => 'cancelled']);

        $this->artisan('calendar:reconcile-events')
            ->expectsOutputToContain('Scanned 0')
            ->assertSuccessful();

        $this->assertSame(0, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
    }
}
