<?php

namespace Tests\Feature;

use App\Models\A2aContract;
use App\Models\LeadAgent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class A2aContractFlowTest extends TestCase
{
    private function reAdmin(): self
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public function test_create_form_from_lead_preselects_lead_scope(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['deal_type' => 'rent']);

        $response = $this->get(route('a2a.create', ['lead_id' => $lead->id]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('id="scope-lead"', $html, 'Lead-specific scope must be preset when opened from a lead.');
        $this->assertStringContainsString('value="'.$lead->id.'"', $html, 'The chosen lead must be marked as selected.');
    }

    public function test_store_creates_lead_scoped_contract_with_company_required(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['deal_type' => 'rent']);

        // Company is required — reject when missing.
        $missing = $this->post(route('a2a.store'), [
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => '',
            'share_pct' => 20,
            'funding_source' => 'from_both',
        ]);
        $missing->assertSessionHasErrors(['counterparty_company']);

        $response = $this->post(route('a2a.store'), [
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra Property Broker',
            'counterparty_email' => 'adja@vierra.ae',
            'share_pct' => 20,
            'funding_source' => 'from_both',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('a2a_contracts', [
            'tenant_id' => $this->tenant->id,
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra Property Broker',
            'status' => 'draft',
        ]);
    }

    public function test_store_demands_lead_when_lead_scoped(): void
    {
        $this->reAdmin();

        $response = $this->post(route('a2a.store'), [
            'scope_type' => 'lead',
            'lead_id' => '',
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
        ]);

        $response->assertSessionHasErrors(['lead_id']);
    }

    public function test_store_creates_property_scoped_contract_demands_property(): void
    {
        $this->reAdmin();
        $property = $this->createProperty(['intent' => 'rent']);

        $missing = $this->post(route('a2a.store'), [
            'scope_type' => 'property',
            'property_id' => '',
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
        ]);
        $missing->assertSessionHasErrors(['property_id']);

        $response = $this->post(route('a2a.store'), [
            'scope_type' => 'property',
            'property_id' => $property->id,
            'transaction_type' => 'sale',
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra Property Broker',
            'counterparty_address' => 'Al Danah, Abu Dhabi',
            'share_pct' => 15,
            'funding_source' => 'from_company',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('a2a_contracts', [
            'tenant_id' => $this->tenant->id,
            'scope_type' => 'property',
            'property_id' => $property->id,
            'transaction_type' => 'sale',
            'lead_id' => null,
        ]);
    }

    public function test_confirm_requires_signed_status(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'draft',
        ]);

        $response = $this->patch(route('a2a.confirm', $contract));

        $response->assertRedirect();
        $this->assertSame('draft', $contract->fresh()->status);
    }

    public function test_lead_scoped_confirm_auto_adds_external_agent(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra Property Broker',
            'counterparty_email' => 'adja@vierra.ae',
            'share_pct' => 20,
            'funding_source' => 'from_both',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'signed',
            'signed_at' => now(),
        ]);

        $response = $this->patch(route('a2a.confirm', $contract));

        $response->assertRedirect();

        $this->assertSame('confirmed', $contract->fresh()->status);
        $this->assertNotNull($contract->fresh()->confirmed_at);
        $this->assertSame($this->adminUser->id, $contract->fresh()->confirmed_by);

        $this->assertDatabaseHas('lead_agents', [
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'agent_id' => null,
            'external_name' => 'Adja Traore',
            'external_company' => 'Vierra Property Broker',
            'commission_pct' => 20,
            'share_funding' => 'from_both',
            'a2a_contract_id' => $contract->id,
            'status' => 'active',
        ]);
    }

    public function test_confirm_is_idempotent_for_the_same_contract(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'counterparty_email' => 'adja@vierra.ae',
            'share_pct' => 20,
            'funding_source' => 'from_agent',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'signed',
        ]);

        $this->patch(route('a2a.confirm', $contract));

        // Clearing the draft status so a second confirm means re-confirming a confirmed contract.
        $this->patch(route('a2a.confirm', $contract));

        $this->assertSame(1, LeadAgent::where('lead_id', $lead->id)->where('a2a_contract_id', $contract->id)->count());
    }

    public function test_property_scoped_confirm_does_not_create_agent_but_allows_attach(): void
    {
        $this->reAdmin();
        $property = $this->createProperty(['intent' => 'sale']);
        $lead = $this->createLead();

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
            'scope_type' => 'property',
            'property_id' => $property->id,
            'transaction_type' => 'sale',
            'status' => 'signed',
        ]);

        $this->patch(route('a2a.confirm', $contract));

        $this->assertSame('confirmed', $contract->fresh()->status);
        $this->assertDatabaseMissing('lead_agents', ['a2a_contract_id' => $contract->id]);

        // Manual attach still available for the property-scoped case.
        $response = $this->post(route('a2a.attach', $contract), ['lead_id' => $lead->id]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lead_agents', [
            'lead_id' => $lead->id,
            'a2a_contract_id' => $contract->id,
            'external_name' => 'Adja Traore',
        ]);
    }

    public function test_agent_cannot_confirm_own_contract(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'signed',
        ]);

        $agent = $this->actingAsRole('agent');
        $contract->update(['agent_id' => $agent->id]);

        $response = $this->patch(route('a2a.confirm', $contract));

        $response->assertForbidden();
        $this->assertSame('signed', $contract->fresh()->status);
    }

    public function test_agent_manager_can_confirm(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $agent = $this->createUserWithRole('agent', ['reports_to' => $this->adminUser->id]);

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agent->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'signed',
            'signed_at' => now(),
        ]);

        $this->actingAs($agent->manager);

        $response = $this->patch(route('a2a.confirm', $contract));

        $response->assertRedirect();
        $this->assertSame('confirmed', $contract->fresh()->status);
    }

    public function test_search_leads_json_returns_results(): void
    {
        $this->reAdmin();
        $lead = $this->createLead(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $response = $this->getJson(route('a2a.leads-search', ['q' => 'Grace']));

        $response->assertOk()->assertJsonStructure(['results' => [['value', 'label']]]);
        $this->assertSame((string) $lead->id, $response->json('results.0.value'));
    }

    public function test_search_properties_json_returns_results(): void
    {
        $this->reAdmin();
        $property = $this->createProperty(['community' => 'Reem Island']);

        $response = $this->getJson(route('a2a.properties-search', ['q' => 'Reem']));

        $response->assertOk()->assertJsonStructure(['results' => [['value', 'label']]]);
        $this->assertSame((string) $property->id, $response->json('results.0.value'));
    }

    public function test_contract_branding_stored_per_tenant(): void
    {
        $this->reAdmin();

        $this->put(route('settings.updateContractBranding'), [
            'contract_company_name' => 'Atlas Properties LLC',
            'contract_address' => '1 Marina Walk, Downtown Dubai',
            'contract_phone' => '+971 4 123 4567',
            'contract_website' => 'www.atlasproperties.ae',
            'contract_email' => 'hello@atlasproperties.ae',
        ])->assertRedirect();

        $options = $this->tenant->fresh()->custom_options;
        $this->assertSame('Atlas Properties LLC', $options['a2a_branding']['company_name']);
        $this->assertSame('1 Marina Walk, Downtown Dubai', $options['a2a_branding']['address']);
        $this->assertSame('www.atlasproperties.ae', $options['a2a_branding']['website']);
        $this->assertArrayNotHasKey('logo_path', $options['a2a_branding']);
    }

    public function test_contract_branding_logo_uploaded(): void
    {
        $this->reAdmin();
        Storage::fake('public');

        $this->put(route('settings.updateContractBranding'), [
            'contract_company_name' => 'Atlas Properties LLC',
            'contract_logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertRedirect();

        $options = $this->tenant->fresh()->custom_options;
        $this->assertArrayHasKey('logo_path', $options['a2a_branding']);
    }

    public function test_print_uses_tenant_branding_not_pristine_defaults(): void
    {
        $this->reAdmin();
        $lead = $this->createLead();

        $contract = A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 10,
            'funding_source' => 'from_agent',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'signed',
        ]);

        $this->put(route('settings.updateContractBranding'), [
            'contract_company_name' => 'Atlas Properties LLC',
            'contract_address' => '1 Marina Walk, Downtown Dubai',
            'contract_phone' => '+971 4 123 4567',
            'contract_website' => 'www.atlasproperties.ae',
            'contract_email' => 'hello@atlasproperties.ae',
        ]);

        $response = $this->get(route('a2a.print', $contract));
        $html = $response->getContent();

        $this->assertStringContainsString('Atlas Properties LLC', $html);
        $this->assertStringContainsString('+971 4 123 4567', $html);
        $this->assertStringNotContainsString('www.pristineproperties.ae', $html);
        $this->assertStringNotContainsString('Al Reem Plaza', $html);
    }
}
