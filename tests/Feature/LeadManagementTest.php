<?php

namespace Tests\Feature;

use App\Models\Lead;
use Tests\TestCase;

class LeadManagementTest extends TestCase
{
    public function test_admin_can_view_leads_index(): void
    {
        $this->actingAsAdmin();
        Lead::factory()->count(3)->create(['tenant_id' => $this->tenant->id, 'agent_id' => $this->adminUser->id]);

        $response = $this->get('/leads');
        $response->assertStatus(200);
    }

    public function test_admin_can_view_create_form(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/leads/create');
        $response->assertStatus(200);
    }

    public function test_admin_can_create_lead(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/leads', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'email' => 'john@example.com',
            'lead_source' => 'website',
            'status' => 'new',
            'temperature' => 'warm',
            'agent_id' => $this->adminUser->id,
        ]);

        $lead = \App\Models\Lead::where('first_name', 'John')->where('last_name', 'Doe')->first();
        $response->assertRedirect("/leads/{$lead->id}");
        $this->assertDatabaseHas('leads', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_admin_can_view_lead_detail(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $response = $this->get("/leads/{$lead->id}");
        $response->assertStatus(200);
    }

    public function test_admin_can_edit_lead(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['first_name' => 'Old']);

        $response = $this->put("/leads/{$lead->id}", [
            'first_name' => 'New',
            'last_name' => $lead->last_name,
            'agent_id' => $this->adminUser->id,
            'lead_source' => $lead->lead_source,
            'status' => $lead->status,
            'temperature' => $lead->temperature,
        ]);

        $response->assertRedirect();
        $this->assertEquals('New', $lead->fresh()->first_name);
    }

    public function test_admin_can_delete_lead(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead();

        $response = $this->delete("/leads/{$lead->id}");

        $response->assertRedirect('/leads');
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
    }

    public function test_admin_can_update_lead_status_via_ajax(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $response = $this->patch("/leads/{$lead->id}/status", [
            'status' => 'contacting',
        ]);

        $response->assertJson(['success' => true]);
        $this->assertEquals('contacting', $lead->fresh()->status);
    }

    public function test_lead_index_filters_by_status(): void
    {
        $this->actingAsAdmin();
        $this->createLead(['status' => 'new']);
        $this->createLead(['status' => 'dead']);

        $response = $this->get('/leads?status=new');
        $response->assertStatus(200);
    }

    public function test_lead_index_filters_by_search(): void
    {
        $this->actingAsAdmin();
        $this->createLead(['first_name' => 'UniqueTestName']);

        $response = $this->get('/leads?search=UniqueTestName');
        $response->assertStatus(200);
    }

    public function test_agent_only_sees_own_leads(): void
    {
        $this->createTenantWithAdmin();
        $agent = $this->actingAsRole('agent');

        $myLead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agent->id,
        ]);

        $otherLead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
        ]);

        $response = $this->get('/leads');
        $response->assertStatus(200);
        $response->assertSee($myLead->first_name);
        $response->assertDontSee($otherLead->first_name);
    }

    public function test_agent_cannot_access_other_agents_lead(): void
    {
        $this->createTenantWithAdmin();
        $this->actingAsRole('agent');

        $otherLead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
        ]);

        $response = $this->get("/leads/{$otherLead->id}");
        $response->assertStatus(403);
    }

    public function test_lead_creation_requires_first_name(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/leads', [
            'last_name' => 'Doe',
            'lead_source' => 'website',
            'status' => 'new',
            'temperature' => 'warm',
        ]);

        $response->assertSessionHasErrors('first_name');
    }

    public function test_lead_creation_sets_tenant_id(): void
    {
        $this->actingAsAdmin();

        $this->post('/leads', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'lead_source' => 'website',
            'status' => 'new',
            'temperature' => 'warm',
            'agent_id' => $this->adminUser->id,
        ]);

        $lead = Lead::first();
        $this->assertEquals($this->tenant->id, $lead->tenant_id);
    }

    public function test_activity_logging_can_update_lead_status_and_temperature(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead(['status' => 'inquiry', 'temperature' => 'cold']);

        $response = $this->post("/leads/{$lead->id}/activities", [
            'type' => 'note',
            'subject' => 'Intro call',
            'body' => 'Booked a viewing.',
            'status' => 'active_client',
            'temperature' => 'hot',
        ]);

        $response->assertRedirect("/leads/{$lead->id}");
        $this->assertDatabaseHas('activities', [
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'type' => 'note',
        ]);
        $lead->refresh();
        $this->assertSame('active_client', $lead->status);
        $this->assertSame('hot', $lead->temperature);
    }

    public function test_activity_logging_without_status_keeps_lead_unchanged(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead(['status' => 'inquiry', 'temperature' => 'cold']);

        $this->post("/leads/{$lead->id}/activities", [
            'type' => 'note',
            'subject' => 'Log only',
            'body' => 'No changes.',
        ]);

        $lead->refresh();
        $this->assertSame('inquiry', $lead->status);
        $this->assertSame('cold', $lead->temperature);
    }

    public function test_activity_logging_rejects_invalid_status(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead();

        $response = $this->post("/leads/{$lead->id}/activities", [
            'type' => 'note',
            'status' => 'not_a_real_status_123',
        ]);

        $response->assertSessionHasErrors('status');
    }

    public function test_whatsapp_activity_can_be_logged(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        // Pick a timezone where the local hour is currently within the 8am-9pm
        // contact window so the DNC timezone restriction does not block the insert.
        $zones = ['Asia/Dubai', 'Europe/London', 'Asia/Kolkata', 'Australia/Sydney', 'America/New_York'];
        $tz = false;
        foreach ($zones as $zone) {
            if (\Carbon\Carbon::now($zone)->hour >= 8 && \Carbon\Carbon::now($zone)->hour < 21) {
                $tz = $zone;
                break;
            }
        }
        $lead = $this->createLead(['timezone' => $tz]);

        $response = $this->post("/leads/{$lead->id}/activities", [
            'type' => 'whatsapp',
            'subject' => 'Follow up',
            'body' => 'Sent the brochure via WhatsApp.',
        ]);

        $response->assertRedirect("/leads/{$lead->id}");
        $this->assertDatabaseHas('activities', [
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'type' => 'whatsapp',
            'subject' => 'Follow up',
        ]);
    }

    public function test_activity_logging_can_advance_lead_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'outreach']);

        $this->post("/leads/{$lead->id}/activities", [
            'type' => 'meeting',
            'subject' => 'Site visit',
            'stage' => 'viewing_done',
        ]);

        $lead->refresh();
        $this->assertSame('viewing_done', $lead->stage);
        $this->assertNotNull($lead->stage_changed_at);
    }

    public function test_activity_logging_without_stage_keeps_lead_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'offer_sent']);

        $this->post("/leads/{$lead->id}/activities", [
            'type' => 'note',
            'subject' => 'Log only',
            'body' => 'No stage change.',
        ]);

        $lead->refresh();
        $this->assertSame('offer_sent', $lead->stage);
    }

    public function test_sales_lead_uses_sales_pipeline_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead(['deal_type' => 'sale']);

        $this->post("/leads/{$lead->id}/activities", [
            'type' => 'note',
            'subject' => 'SPA completed',
            'stage' => 'spa_signed',
        ]);

        $lead->refresh();
        $this->assertSame('spa_signed', $lead->stage);
    }

    public function test_activity_logging_rejects_invalid_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead();

        $response = $this->post("/leads/{$lead->id}/activities", [
            'type' => 'note',
            'stage' => 'not_a_real_stage_123',
        ]);

        $response->assertSessionHasErrors('stage');
    }

    public function test_admin_can_set_deal_type_when_creating_lead(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $response = $this->post('/leads', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '555-0199',
            'email' => 'sara@example.com',
            'lead_source' => 'website',
            'status' => 'new',
            'temperature' => 'warm',
            'agent_id' => $this->adminUser->id,
            'deal_type' => 'sale',
            'stage' => 'offer_sent',
        ]);

        $lead = \App\Models\Lead::where('first_name', 'Sara')->first();
        $response->assertRedirect("/leads/{$lead->id}");
        $this->assertSame('sale', $lead->deal_type);
        $this->assertSame('offer_sent', $lead->stage);
        $this->assertNotNull($lead->stage_changed_at);
    }

    public function test_changing_deal_type_resets_incompatible_stage(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $lead = $this->createLead(['deal_type' => 'rent', 'stage' => 'viewing_done']);

        $this->put("/leads/{$lead->id}", [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'agent_id' => $this->adminUser->id,
            'lead_source' => 'website',
            'status' => 'new',
            'temperature' => 'warm',
            'deal_type' => 'sale',
        ]);

        $lead->refresh();
        $this->assertSame('sale', $lead->deal_type);
        $this->assertNull($lead->stage);
    }
}
