<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MarketContact;
use App\Models\MarketImport;
use App\Models\Property;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MarketImportTest extends TestCase
{
    private function actingAsRealEstateAdmin(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function uploadCsv(string $content, string $type = 'landlords', string $name = 'September list'): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('market.csv', $content);

        return $this->post(route('market.import'), [
            'name' => $name,
            'type' => $type,
            'file' => $file,
        ]);
    }

    public function test_landlord_import_creates_pending_contacts_and_import_batch(): void
    {
        $this->actingAsRealEstateAdmin();

        $csv = 'Name,Phone,Email,Building,Community,Unit No,Bedrooms,Rent,Status
John Smith,+971501234567,john@example.com,Marina Tower,Dubai Marina,1204,2,120000,Available
Aisha Khan,+971509998888,aisha@example.com,Blue Bay,Dubai Marina,905,1,95000,Available
';

        $this->uploadCsv($csv)
            ->assertRedirect(route('market.index'))
            ->assertSessionHas('success');

        $this->assertSame(2, MarketContact::where('tenant_id', $this->tenant->id)->count());
        $import = MarketImport::where('tenant_id', $this->tenant->id)->first();

        $this->assertNotNull($import);
        $this->assertSame(2, $import->imported_rows);
        $this->assertSame('landlords', $import->type);

        $john = MarketContact::where('tenant_id', $this->tenant->id)->where('email', 'john@example.com')->first();
        $this->assertNotNull($john);
        $this->assertSame('landlord', $john->type);
        $this->assertSame('John', $john->first_name);
        $this->assertSame('Smith', $john->last_name);
        $this->assertSame('1204', $john->unit_no);
        $this->assertSame('Marina Tower', $john->building);
        $this->assertSame('Dubai Marina', $john->community);
        $this->assertSame(2, $john->bedrooms);
        $this->assertSame('120000.00', $john->rent_price);
        $this->assertSame('pending', $john->status);
    }

    public function test_investor_import_maps_name_and_budget(): void
    {
        $this->actingAsRealEstateAdmin();

        $csv = 'Name,Budget,Preferred Type,Requirements
Sarah Lee,1200000,Apartment,Looking for a 2BR in Marina
Ahmed Ali,2500000,Villa,North-facing villa with garden
';

        $this->uploadCsv($csv, 'investors', 'Investor leads')->assertRedirect(route('market.index'));

        $sarah = MarketContact::where('tenant_id', $this->tenant->id)->where('first_name', 'Sarah')->first();
        $this->assertNotNull($sarah);
        $this->assertSame('investor', $sarah->type);
        $this->assertSame('Lee', $sarah->last_name);
        $this->assertSame('1200000.00', $sarah->budget);
        $this->assertSame('Apartment', $sarah->preferred_type);
        $this->assertSame('Looking for a 2BR in Marina', $sarah->requirements);
    }

    public function test_agent_can_log_cold_call_outcome(): void
    {
        $this->actingAsRealEstateAdmin();
        $contact = MarketContact::factory()->landlord()->create(['tenant_id' => $this->tenant->id]);

        $this->post(route('market.status', $contact), [
            'status' => 'reached',
            'agent_id' => $this->adminUser->id,
            'call_notes' => 'Interested, wants to list the unit this month.',
        ])->assertRedirect(route('market.show', $contact));

        $contact->refresh();
        $this->assertSame('reached', $contact->status);
        $this->assertSame($this->adminUser->id, $contact->called_by);
        $this->assertNotNull($contact->last_called_at);
        $this->assertStringContainsString('this month', $contact->call_notes);
    }

    public function test_successful_landlord_call_converts_to_inventory_with_owner_lead(): void
    {
        $this->actingAsRealEstateAdmin();
        $contact = MarketContact::factory()->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Rashid',
            'last_name' => 'Al Mansoori',
            'phone' => '+971501122334',
            'email' => 'rashid@example.com',
            'unit_no' => '301',
            'building' => 'Al Thuraya Tower',
            'community' => 'Dubai Media City',
            'rent_price' => '140000',
        ]);

        $this->post(route('market.convert-property', $contact), ['agent_id' => $this->adminUser->id])
            ->assertRedirect();

        $property = Property::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($property);
        $this->assertSame('rent', $property->intent);
        $this->assertSame('ready_to_list', $property->availability);
        $this->assertSame($this->adminUser->id, $property->assigned_agent_id);
        $this->assertSame('Rashid Al Mansoori', $property->owner_name);
        $this->assertSame('+971501122334', $property->owner_phone);
        $this->assertSame('rashid@example.com', $property->owner_email);
        $this->assertSame('Al Thuraya Tower', $property->sub_community);
        $this->assertSame('301', $property->unit_no);

        $ownerLead = Lead::where('tenant_id', $this->tenant->id)->where('contact_type', 'seller_lead')->first();
        $this->assertNotNull($ownerLead);
        $this->assertSame($property->lead_id, $ownerLead->id);
        $this->assertTrue($property->leads()->where('lead_id', $ownerLead->id)->exists());
        $this->assertSame('seller_lead', $ownerLead->contact_type);
        $this->assertSame('cold_call', $ownerLead->lead_source);

        $contact->refresh();
        $this->assertSame('converted', $contact->status);
        $this->assertSame('property', $contact->converted_type);
        $this->assertSame($property->id, $contact->converted_id);
    }

    public function test_same_unit_from_second_landlord_updates_instead_of_duplicating(): void
    {
        $this->actingAsRealEstateAdmin();
        $first = MarketContact::factory()->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'unit_no' => '501',
            'building' => 'Sidra Tower',
            'community' => 'Palm Jumeirah',
        ]);
        $this->post(route('market.convert-property', $first), ['agent_id' => $this->adminUser->id]);

        $second = MarketContact::factory()->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Fatima',
            'last_name' => 'Hassan',
            'phone' => '+971502223344',
            'unit_no' => '501',
            'building' => 'Sidra Tower',
            'community' => 'Palm Jumeirah',
            'rent_price' => '155000',
        ]);
        $this->post(route('market.convert-property', $second), ['agent_id' => $this->adminUser->id]);

        $this->assertSame(1, Property::where('tenant_id', $this->tenant->id)->count());

        $property = Property::where('tenant_id', $this->tenant->id)->first();
        $this->assertSame('155000.00', $property->rent_price);
        $this->assertSame('Fatima Hassan', $property->owner_name);

        $second->refresh();
        $this->assertSame('converted', $second->status);
    }

    public function test_successful_investor_call_creates_sales_lead_prefilled(): void
    {
        $this->actingAsRealEstateAdmin();
        $contact = MarketContact::factory()->investor()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Maya',
            'last_name' => 'Rao',
            'phone' => '+971503334455',
            'email' => 'maya.rao@example.com',
            'budget' => '1800000',
            'preferred_type' => 'Apartment',
            'requirements' => '2BR near the Metro',
        ]);

        $this->post(route('market.convert-lead', $contact), ['agent_id' => $this->adminUser->id])
            ->assertRedirect(route('leads.edit', Lead::where('tenant_id', $this->tenant->id)->firstOrFail()));

        $lead = Lead::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($lead);
        $this->assertSame('Maya', $lead->first_name);
        $this->assertSame('maya.rao@example.com', $lead->email);
        $this->assertSame($this->adminUser->id, $lead->agent_id);
        $this->assertSame('sale', $lead->deal_type);
        $this->assertSame('buyer_lead', $lead->contact_type);
        $this->assertSame('cold_call', $lead->lead_source);
        $this->assertSame('Apartment', $lead->custom_fields['sought_unit'] ?? null);
        $this->assertSame('1800000.00', (string) ($lead->custom_fields['budget'] ?? null));

        $contact->refresh();
        $this->assertSame('converted', $contact->status);
        $this->assertSame('lead', $contact->converted_type);
        $this->assertSame($lead->id, $contact->converted_id);
    }

    public function test_investor_conversion_matches_existing_lead_and_redirects_to_edit(): void
    {
        $this->actingAsRealEstateAdmin();
        $existing = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'email' => 'existing.buyer@example.com',
            'phone' => '+971504445566',
            'contact_type' => 'buyer_lead',
            'deal_type' => 'sale',
            'stage' => 'new_lead',
        ]);

        $contact = MarketContact::factory()->investor()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'existing.buyer@example.com',
            'phone' => '+971504445566',
        ]);

        $this->post(route('market.convert-lead', $contact), ['agent_id' => $this->adminUser->id])
            ->assertRedirect(route('leads.edit', $existing));

        $this->assertSame(1, Lead::where('tenant_id', $this->tenant->id)->count());

        $contact->refresh();
        $this->assertSame('lead', $contact->converted_type);
        $this->assertSame($existing->id, $contact->converted_id);
    }

    public function test_update_allows_correcting_imported_information(): void
    {
        $this->actingAsRealEstateAdmin();
        $contact = MarketContact::factory()->landlord()->create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+971505556677',
        ]);

        $this->patch(route('market.update', $contact), [
            'type' => 'landlord',
            'phone' => '+971509992222',
            'unit_no' => '802',
            'building' => 'Corrected Tower',
            'community' => 'JLT',
        ])->assertRedirect(route('market.show', $contact));

        $contact->refresh();
        $this->assertSame('+971509992222', $contact->phone);
        $this->assertSame('802', $contact->unit_no);
        $this->assertSame('JLT', $contact->community);
    }

    public function test_cold_call_agent_role_can_use_the_cold_calls_module(): void
    {
        $coldCall = $this->actingAsRole('cold_call_agent', ['business_mode' => 'realestate']);
        MarketContact::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        $this->get(route('market.index'))->assertStatus(200);
        $this->get(route('market.create'))->assertStatus(200);
        $this->assertSame($coldCall->tenant_id, $this->tenant->id);
    }

    public function test_plain_agent_and_marketing_roles_are_rejected_from_cold_calls(): void
    {
        foreach (['agent', 'marketing'] as $role) {
            $this->actingAsRole($role, ['business_mode' => 'realestate']);
            $this->get(route('market.index'))->assertStatus(403);
        }
    }

    public function test_secondary_cold_call_role_grants_access_to_cold_calls(): void
    {
        $user = $this->actingAsRole('listing_agent', ['business_mode' => 'realestate']);
        $user->secondaryRoles()->attach(\App\Models\Role::where('name', 'cold_call_agent')->firstOrFail()->id);

        $this->get(route('market.index'))->assertStatus(200);
        $this->get(route('market.create'))->assertStatus(200);
    }

    public function test_legacy_market_url_returns_404(): void
    {
        $this->actingAsRealEstateAdmin();
        $this->get('/market')->assertStatus(404);
    }

    public function test_market_pages_render(): void
    {
        $this->actingAsRealEstateAdmin();
        MarketContact::factory()->landlord()->create(['tenant_id' => $this->tenant->id]);
        $investor = MarketContact::factory()->investor()->create(['tenant_id' => $this->tenant->id]);

        $this->get(route('market.index'))->assertStatus(200);
        $this->get(route('market.create'))->assertStatus(200);
        $this->get(route('market.show', $investor))->assertStatus(200);
    }
}
