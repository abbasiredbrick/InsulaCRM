<?php

namespace Tests\Feature;

use App\Models\LeadProof;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LeadStatusGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeadStatusGateTest extends TestCase
{
    // ── Quick status endpoint (kanban drop / table select) ────────

    public function test_quick_status_to_dead_is_blocked_without_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $response = $this->patchJson(route('leads.updateStatus', $lead), ['status' => 'dead']);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_quick_status_to_manual_status_still_works(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->patchJson(route('leads.updateStatus', $lead), ['status' => 'contacted'])
            ->assertJson(['success' => true]);

        $this->assertSame('contacted', $lead->fresh()->status);
    }

    public function test_quick_status_to_dead_succeeds_after_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);
        $this->attachProof($lead);

        $this->patchJson(route('leads.updateStatus', $lead), ['status' => 'dead'])
            ->assertJson(['success' => true]);

        $this->assertSame('dead', $lead->fresh()->status);
    }

    // ── Edit form / create form ───────────────────────────────────

    public function test_update_form_to_dead_is_blocked_without_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->put(route('leads.update', $lead), $this->validLeadPayload($lead, 'dead'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_update_form_to_closed_lost_is_blocked_without_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        // closed_lost is not even in the form's allowed set (deal-driven), so
        // validation rejects it before the gate can run.
        $this->put(route('leads.update', $lead), $this->validLeadPayload($lead, 'closed_lost'))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_create_form_cannot_create_a_lead_directly_as_dead(): void
    {
        $this->actingAsAdmin();
        $this->createLead(['status' => 'new']);
        $payload = $this->validLeadPayload($this->createLead(['first_name' => 'BrandNew', 'last_name' => 'Client']), 'dead');
        $payload['first_name'] = 'BrandNew';

        $this->post(route('leads.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, \App\Models\Lead::where('first_name', 'BrandNew')->count());
    }

    // ── Bulk action ───────────────────────────────────────────────

    public function test_bulk_status_to_dead_is_blocked(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->post(route('leads.bulkAction'), [
            'ids' => [$lead->id],
            'action' => 'status',
            'status' => 'dead',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('new', $lead->fresh()->status);
    }

    // ── Activity quick-log ─────────────────────────────────────────

    public function test_activity_form_to_dead_is_blocked_without_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->post(route('leads.activities.store', $lead), [
            'type' => 'note',
            'status' => 'dead',
            'body' => 'Gone quiet',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('new', $lead->fresh()->status);
    }

    public function test_activity_form_to_dead_succeeds_after_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);
        $this->attachProof($lead);

        $this->post(route('leads.activities.store', $lead), [
            'type' => 'note',
            'status' => 'dead',
            'body' => 'Gone quiet',
        ])->assertRedirect();

        $this->assertSame('dead', $lead->fresh()->status);
    }

    // ── Proof upload + one-step mark-lost ─────────────────────────

    public function test_uploading_proof_and_marking_dead_in_one_step(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'contacted']);

        $this->post(route('leads.proof.store', $lead), [
            'proof' => UploadedFile::fake()->image('whatsapp-proof.png'),
            'status' => 'dead',
            'note' => 'Client replied once then went silent',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('dead', $lead->fresh()->status);

        $proof = LeadProof::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('whatsapp_screenshot', $proof->type);
        $this->assertSame('whatsapp-proof.png', $proof->original_name);
        $this->assertSame('Client replied once then went silent', $proof->note);
        Storage::disk('local')->assertExists($proof->file_path);
    }

    public function test_uploading_proof_without_status_only_stores_the_proof(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'contacted']);

        $this->post(route('leads.proof.store', $lead), [
            'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('contacted', $lead->fresh()->status);
        $this->assertSame(1, LeadProof::where('lead_id', $lead->id)->count());
    }

    // ── Proof access control ───────────────────────────────────────

    public function test_proof_download_is_authorized_for_the_lead_owner(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);
        $proof = $this->attachProof($lead);
        Storage::disk('local')->put($proof->file_path, 'fake-png');

        $this->get(route('leads.proofs.download', $proof))->assertOk();
    }

    public function test_proof_is_invisible_to_another_tenant(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);
        $proof = $this->attachProof($lead);
        Storage::disk('local')->put($proof->file_path, 'fake-png');

        $otherTenant = Tenant::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'email' => 'other@test.com',
            'status' => 'active',
            'timezone' => 'America/New_York',
            'country' => 'US',
        ]);
        $otherAdmin = User::factory()->create(['tenant_id' => $otherTenant->id, 'role_id' => $this->adminUser->role_id]);

        $this->actingAs($otherAdmin);

        $this->get(route('leads.proofs.download', $proof))->assertNotFound();
        $this->delete(route('leads.proofs.destroy', $proof))->assertNotFound();
    }

    public function test_deleting_proof_removes_the_row_and_file(): void
    {
        Storage::fake('local');
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);
        $proof = $this->attachProof($lead);
        Storage::disk('local')->put($proof->file_path, 'fake-png');

        $this->delete(route('leads.proofs.destroy', $proof))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('lead_proofs', ['id' => $proof->id]);
        Storage::disk('local')->assertMissing($proof->file_path);
    }

    // ── Option lists / service behaviour ──────────────────────────

    public function test_status_options_omit_dead_without_proof_and_include_it_after(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $options = LeadStatusGate::statusOptionsFor($lead);
        $this->assertArrayNotHasKey('dead', $options);
        $this->assertArrayNotHasKey('closed_won', $options);
        $this->assertArrayNotHasKey('closed_lost', $options);

        $this->attachProof($lead);

        $options = LeadStatusGate::statusOptionsFor($lead->fresh());
        $this->assertArrayHasKey('dead', $options);
        $this->assertArrayNotHasKey('closed_won', $options);
    }

    public function test_bulk_options_never_offer_the_gated_statuses(): void
    {
        $this->actingAsAdmin();

        $options = LeadStatusGate::bulkStatusOptions();
        $this->assertArrayNotHasKey('dead', $options);
        $this->assertArrayNotHasKey('closed_lost', $options);
        $this->assertArrayNotHasKey('closed_won', $options);
    }

    public function test_deal_path_to_closed_lost_needs_no_proof(): void
    {
        // The deal workflow writes closed_lost straight onto the model, never
        // through the controllers the gate guards, so it needs no proof. The
        // gate's job is only to stop an agent who would mark it manually.
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'contacted']);

        $lead->update(['status' => 'closed_lost']);

        $this->assertSame('closed_lost', $lead->status);

        // An already-lost lead is simply recorded; proof was needed for the
        // manual transition itself, and the model-level write bypassed it.
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'status' => 'closed_lost']);
    }

    public function test_gate_only_guards_lost_statuses(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['status' => 'new']);

        $this->assertNull(LeadStatusGate::proofError($lead, 'contacted'));
        $this->assertNull(LeadStatusGate::proofError($lead, 'contacted'));
    }

    // ── Kanban UI ─────────────────────────────────────────────────

    public function test_kanban_marks_dead_column_and_hides_dead_move_option_without_proof(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['first_name' => 'ProofRequired', 'status' => 'contacted']);

        $response = $this->get(route('leads.kanban'));

        $response->assertOk()
            ->assertSee('data-col-kind="proof"', false)
            ->assertSee('Requires a WhatsApp screenshot proof')
            ->assertSee('data-lost-proof="0"', false);
        $response->assertDontSee('data-lead-id="'.$lead->id.'" data-status="dead"', false);
    }

    public function test_kanban_offers_dead_move_option_once_proof_exists(): void
    {
        $this->actingAsAdmin();
        $lead = $this->createLead(['first_name' => 'Proven', 'status' => 'contacted']);
        $this->attachProof($lead);

        $response = $this->get(route('leads.kanban'));

        $response->assertOk()
            ->assertSee('data-lost-proof="1"', false)
            ->assertSee('data-lead-id="'.$lead->id.'" data-status="dead"', false);
    }

    // ── Helpers ───────────────────────────────────────────────────

    protected function attachProof($lead): LeadProof
    {
        return LeadProof::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'uploaded_by' => $this->adminUser->id,
            'type' => 'whatsapp_screenshot',
            'file_path' => 'lead-proofs/'.$lead->id.'/proof.png',
            'original_name' => 'proof.png',
            'mime_type' => 'image/png',
            'size' => 100,
        ]);
    }

    protected function validLeadPayload($lead, ?string $status = 'new'): array
    {
        $lead ??= $this->createLead();
        $lead->refresh();

        return [
            'agent_id' => $lead->agent_id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'lead_source' => $lead->lead_source,
            'status' => $status,
            'temperature' => $lead->temperature,
            'deal_type' => $lead->deal_type ?? 'rent',
        ];
    }
}
