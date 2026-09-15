<?php

namespace Tests\Feature;

use App\Models\Meeting;
use Tests\TestCase;

class CalendarEventFeedTest extends TestCase
{
    private function reAdmin(): self
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_meeting_can_be_scheduled_with_a_calendar(): void
    {
        $this->reAdmin()->withCalendar();
        $lead = $this->createLead();

        $this->post(route('leads.meetings.store', $lead), [
            'title' => 'Site visit',
            'scheduled_at' => now()->addDays(1)->format('Y-m-d H:i'),
            'duration_minutes' => 45,
            'notes' => 'Bring keys',
        ])->assertRedirect();

        $this->assertDatabaseHas('meetings', [
            'lead_id' => $lead->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_calendar_events_contain_meetings_but_not_activity_log(): void
    {
        $this->reAdmin()->withCalendar();
        $lead = $this->createLead();

        $lead->activities()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'type' => 'meeting',
            'subject' => 'Called client',
            'logged_at' => now(),
        ]);

        Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'title' => 'Walkthrough',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i'),
            'status' => 'scheduled',
        ]);

        $start = now()->format('Y-m-d');
        $end = now()->addWeek()->format('Y-m-d');

        $response = $this->getJson(route('calendar.events', ['start' => $start, 'end' => $end]));
        $response->assertOk()
            ->assertJsonFragment(['title' => 'Meeting: Walkthrough'])
            ->assertJsonMissing(['title' => 'Meeting: Called client'])
            ->assertJsonMissing(['title' => 'Call: Called client']);
    }

    public function test_completed_meeting_is_removed_from_calendar_events(): void
    {
        $this->reAdmin()->withCalendar();
        $lead = $this->createLead();

        Meeting::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'title' => 'Walkthrough',
            'scheduled_at' => now()->addDays(1)->format('Y-m-d H:i'),
            'status' => 'scheduled',
        ]);

        $response = $this->getJson(route('calendar.events', [
            'start' => now()->format('Y-m-d'),
            'end' => now()->addWeek()->format('Y-m-d'),
        ]));
        $response->assertOk()->assertJsonFragment(['title' => 'Meeting: Walkthrough']);
    }
}
