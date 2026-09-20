<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Showing;
use App\Models\User;
use App\Models\UserCloudConnection;
use App\Services\Cloud\CloudBaseProvider;
use App\Services\Cloud\CloudCalendarService;
use App\Services\Cloud\CloudProviderFactory;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_assigned_agent_event_created_and_main_agent_event_also_created(): void
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

        $fresh = $showing->fresh();
        $this->assertNotNull($fresh->calendar_provider);
        $this->assertNotNull($fresh->calendar_event_id);
        $this->assertSame('google', $fresh->main_calendar_provider);
        $this->assertNotNull($fresh->main_calendar_event_id);
    }

    public function test_assigned_agent_without_connection_declines_external_event(): void
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

        $fresh = $showing->fresh();
        $this->assertNull($fresh->calendar_provider);
        $this->assertNull($fresh->calendar_event_id);
        $this->assertNull($fresh->main_calendar_provider);
        $this->assertNull($fresh->main_calendar_event_id);
    }

    public function test_same_agent_pushes_single_event_only(): void
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

        $fresh = $showing->fresh();
        $this->assertNotNull($fresh->calendar_provider);
        $this->assertNotNull($fresh->calendar_event_id);
        $this->assertNull($fresh->main_calendar_provider);
        $this->assertNull($fresh->main_calendar_event_id);
    }

    public function test_remove_event_clears_both_slots(): void
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
            'calendar_provider' => 'google',
            'calendar_event_id' => 'event-assigned',
            'main_calendar_provider' => 'google',
        ]);

        $service = $this->makeServiceMockery();
        $service->removeEvent($showing);

        $fresh = $showing->fresh();
        $this->assertNull($fresh->calendar_provider);
        $this->assertNull($fresh->calendar_event_id);
        $this->assertNull($fresh->main_calendar_provider);
        $this->assertNull($fresh->main_calendar_event_id);
    }
}