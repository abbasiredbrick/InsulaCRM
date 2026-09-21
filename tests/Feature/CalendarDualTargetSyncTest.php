<?php

namespace Tests\Feature;

use App\Models\CalendarEventLink;
use App\Models\Lead;
use App\Models\Showing;
use App\Models\User;
use App\Models\UserCloudConnection;
use App\Services\Cloud\CloudBaseProvider;
use App\Services\Cloud\CloudCalendarService;
use App\Services\Cloud\CloudProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarDualTargetSyncTest extends TestCase
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

    private function makeServiceMockery(): CloudCalendarService
    {
        $provider = \Mockery::mock(CloudBaseProvider::class);
        $provider->shouldReceive('createCalendarEvent')->andReturn('evt-'.Str::random(12));
        $provider->shouldReceive('updateCalendarEvent')->andReturn(null);
        $provider->shouldReceive('deleteCalendarEvent')->andReturn(null);

        $factory = \Mockery::mock(CloudProviderFactory::class);
        $factory->shouldReceive('make')->andReturn($provider);

        return new CloudCalendarService($factory);
    }

    public function test_event_created_for_assigned_and_lead_owning_agents(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($viewingAgent);
        $this->connectCalendar($mainAgent);

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $mainAgent->id,
        ]);
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

        $service = $this->makeServiceMockery();
        $service->sync($showing, $this->adminUser);

        $this->assertDatabaseHas('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $viewingAgent->id,
            'provider' => 'google',
        ]);
        $this->assertDatabaseHas('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $mainAgent->id,
            'provider' => 'google',
        ]);
        $this->assertSame(2, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
    }

    public function test_users_without_connection_get_no_event_link(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($mainAgent);

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $mainAgent->id,
        ]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $viewingAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $service = $this->makeServiceMockery();
        $service->sync($showing, $this->adminUser);

        $this->assertDatabaseHas('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $mainAgent->id,
        ]);
        $this->assertDatabaseMissing('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $viewingAgent->id,
        ]);
    }

    public function test_same_agent_pushes_single_event_link_only(): void
    {
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($mainAgent);

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $mainAgent->id,
        ]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $mainAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $service = $this->makeServiceMockery();
        $service->sync($showing, $this->adminUser);

        $this->assertSame(1, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
        $this->assertDatabaseHas('calendar_event_links', [
            'eventable_type' => Showing::class,
            'eventable_id' => $showing->id,
            'user_id' => $mainAgent->id,
        ]);
    }

    public function test_sync_updates_existing_link_when_provider_matches(): void
    {
        $mainAgent = $this->createUserWithRole('agent');
        $connection = $this->connectCalendar($mainAgent);

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $mainAgent->id,
        ]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $mainAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $service = $this->makeServiceMockery();
        $service->sync($showing, $this->adminUser);

        $link = CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->where('user_id', $mainAgent->id)->first();
        $originalEventId = $link->event_id;

        $service->sync($showing, $this->adminUser);

        $this->assertSame(1, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
        $this->assertSame($originalEventId, $link->fresh()->event_id);
        $this->assertSame($connection->provider, $link->fresh()->provider);
    }

    public function test_remove_event_deletes_all_links_and_external_event(): void
    {
        $viewingAgent = $this->createUserWithRole('agent');
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($viewingAgent);
        $this->connectCalendar($mainAgent);

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $mainAgent->id,
        ]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $viewingAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $service = $this->makeServiceMockery();
        $service->sync($showing, $this->adminUser);
        $this->assertSame(2, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());

        $service->removeEvent($showing);

        $this->assertSame(0, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
    }

    public function test_cancelled_showing_removes_links_on_sync(): void
    {
        $mainAgent = $this->createUserWithRole('agent');
        $this->connectCalendar($mainAgent);

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $mainAgent->id,
        ]);
        $property = $this->createProperty(['lead_id' => $lead->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $mainAgent->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $service = $this->makeServiceMockery();
        $service->sync($showing, $this->adminUser);
        $this->assertSame(1, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());

        $showing->update(['status' => 'cancelled']);
        $service->sync($showing, $this->adminUser);

        $this->assertSame(0, CalendarEventLink::where('eventable_type', Showing::class)->where('eventable_id', $showing->id)->count());
    }
}
