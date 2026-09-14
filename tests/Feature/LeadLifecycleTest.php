<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Showing;
use App\Notifications\LeadLostForReview;
use Tests\TestCase;

class LeadLifecycleTest extends TestCase
{
    protected function realEstateTenant(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    // ── Viewing / showing → pipeline sync ─────────────────────────

    public function test_scheduled_showing_advances_rental_lead_to_viewing_scheduled(): void
    {
        $this->realEstateTenant();
        $this->withCalendar();
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_requested']);
        $property = $this->createProperty(['tenant_id' => $this->tenant->id, 'rent_price' => 120000]);

        $this->post(route('showings.store'), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'showing_date' => now()->addDays(2)->format('Y-m-d'),
            'showing_time' => '10:00',
        ])->assertRedirect();

        $this->assertEquals('viewing_scheduled', $lead->fresh()->stage);
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Viewing scheduled',
        ]);
    }

    public function test_scheduled_showing_does_not_regress_an_advanced_stage(): void
    {
        $this->realEstateTenant();
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_done']);
        $property = $this->createProperty(['tenant_id' => $this->tenant->id]);

        $this->post(route('showings.store'), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'showing_date' => now()->addDays(2)->format('Y-m-d'),
            'showing_time' => '10:00',
        ])->assertRedirect();

        $this->assertEquals('viewing_done', $lead->fresh()->stage);
    }

    public function test_completed_showing_moves_rental_lead_to_viewing_done(): void
    {
        $this->realEstateTenant();
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_scheduled']);
        $property = $this->createProperty(['tenant_id' => $this->tenant->id]);

        $showing = Showing::create([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'agent_id' => $this->adminUser->id,
            'showing_date' => now()->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'scheduled',
        ]);

        $this->put(route('showings.update', $showing), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'showing_date' => now()->format('Y-m-d'),
            'showing_time' => '10:00',
            'status' => 'completed',
        ])->assertRedirect();

        $this->assertEquals('viewing_done', $lead->fresh()->stage);
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Unit viewed',
        ]);
    }

    public function test_sales_lead_showing_does_not_change_stage(): void
    {
        $this->realEstateTenant();
        $this->withCalendar();
        $lead = $this->createLead(['deal_type' => 'sale', 'stage' => 'offer_sent']);
        $property = $this->createProperty(['tenant_id' => $this->tenant->id]);

        $this->post(route('showings.store'), [
            'property_id' => $property->id,
            'lead_id' => $lead->id,
            'showing_date' => now()->addDays(1)->format('Y-m-d'),
            'showing_time' => '14:00',
        ])->assertRedirect();

        $this->assertEquals('offer_sent', $lead->fresh()->stage);
        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Viewing scheduled',
        ]);
    }

    public function test_manual_stage_change_to_viewing_logs_calendar_activity(): void
    {
        $this->realEstateTenant();
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'outreach']);

        $this->post(route('leads.activities.store', $lead), [
            'type' => 'note',
            'stage' => 'viewing_scheduled',
            'body' => 'Client confirmed Thursday slot',
        ])->assertRedirect();

        $this->assertDatabaseHas('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Viewing Scheduled',
        ]);

        $viewing = Activity::where('lead_id', $lead->id)->where('type', 'meeting')->first();
        $this->assertNotNull($viewing);
        $this->assertDatabaseHas('activities', ['id' => $viewing->id, 'logged_at' => $viewing->logged_at]);
    }

    public function test_manual_stage_change_to_non_viewing_does_not_log_extra_activity(): void
    {
        $this->realEstateTenant();
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'outreach']);

        $this->post(route('leads.activities.store', $lead), [
            'type' => 'note',
            'stage' => 'offer_sent',
            'body' => 'Offer sent to landlord',
        ])->assertRedirect();

        $this->assertDatabaseMissing('activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'subject' => 'Viewing Scheduled',
        ]);
    }

    // ── Lost / dead → management notification ─────────────────────

    public function test_lost_status_notifies_admins_for_cross_check(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'nurture']);

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'closed_lost'])->assertJson(['success' => true]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => LeadLostForReview::class,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'lead.lost_flagged',
            'model_id' => $lead->id,
        ]);
    }

    public function test_dead_status_notifies_admins(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'dead'])->assertJson(['success' => true]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => LeadLostForReview::class,
        ]);
    }

    public function test_lost_status_via_activity_form_notifies_admins(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'active_client']);

        $this->post(route('leads.activities.store', $lead), [
            'type' => 'note',
            'status' => 'closed_lost',
            'body' => 'Client decided not to proceed',
        ])->assertRedirect();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => LeadLostForReview::class,
        ]);
        $this->assertEquals('closed_lost', $lead->fresh()->status);
    }

    public function test_normal_status_change_does_not_notify(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'contacted'])->assertJson(['success' => true]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->adminUser->id,
            'type' => LeadLostForReview::class,
        ]);
    }

    public function test_lost_notification_points_to_the_lead(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'nurture', 'first_name' => 'Review', 'last_name' => 'Me']);

        $this->patch(route('leads.updateStatus', $lead), ['status' => 'closed_lost']);

        $notification = $this->adminUser->notifications()->where('type', LeadLostForReview::class)->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('"url"', json_encode($notification->data));
        $this->assertStringContainsString((string) $lead->id, json_encode($notification->data));
    }

    // ── Dashboard closed-lease metrics ────────────────────────────

    public function test_dashboard_kpi_counts_closed_leases(): void
    {
        $this->realEstateTenant();
        $lead = $this->createLead(['deal_type' => 'rent', 'status' => 'closed_won', 'updated_at' => now()]);
        $property = $this->createProperty(['tenant_id' => $this->tenant->id, 'admin_fee' => 1000]);
        $lead->properties()->attach($property->id);

        $response = $this->getJson(route('dashboard.data'));

        $response->assertOk();
        $this->assertEquals(1, $response->json('kpi.closedThisMonth'));
        $this->assertEquals(1000.0, (float) $response->json('kpi.feesThisMonth'));
    }

    public function test_dashboard_ignores_closed_sales_leads(): void
    {
        $this->realEstateTenant();
        $this->createLead(['deal_type' => 'sale', 'status' => 'closed_won', 'updated_at' => now()]);
        $this->createLead(['deal_type' => 'rent', 'status' => 'nurture', 'updated_at' => now()]);

        $response = $this->getJson(route('dashboard.data'));

        $response->assertOk();
        $this->assertEquals(0, $response->json('kpi.closedThisMonth'));
    }

    public function test_dashboard_closed_lease_requires_property_admin_fee_only_scope(): void
    {
        $this->realEstateTenant();
        $agent = $this->createUserWithRole('agent');

        $this->createLead([
            'deal_type' => 'rent',
            'status' => 'closed_won',
            'agent_id' => $agent->id,
            'updated_at' => now(),
        ]);

        // Admin sees the closed lease in the KPI regardless of which agent closed it.
        $response = $this->getJson(route('dashboard.data'));
        $this->assertEquals(1, $response->json('kpi.closedThisMonth'));
    }

    public function test_lead_page_shows_schedule_viewing_button_in_realestate_mode(): void
    {
        $this->realEstateTenant();
        $lead = $this->createLead(['deal_type' => 'rent']);

        $response = $this->get(route('leads.show', $lead));

        $response->assertOk();
        $response->assertSee('Schedule Viewing');
        $response->assertSee(route('showings.create', ['lead_id' => $lead->id]));
    }
}
