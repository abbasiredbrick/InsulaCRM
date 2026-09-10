<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Property;
use Tests\TestCase;

class ClientShareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate', 'timezone' => 'Asia/Dubai', 'country' => 'AE']);
    }

    protected function availableUnit(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'availability' => 'ready_to_list',
            'community' => 'Rihan Heights',
            'sub_community' => 'Al Rihan Heights',
            'unit_no' => '1401',
            'bedrooms' => 1,
            'bathrooms' => 1,
            'square_footage' => 800,
            'furnishing' => 'unfurnished',
            'rent_price' => 75000,
            'rent_period' => 'yearly',
            'address' => 'Al Rihan Heights Abu Dhabi',
            'city' => 'Abu Dhabi',
            'state' => '',
            'zip_code' => '',
            'marketing_title' => '1BR Apartment for Rent in Al Rihan Heights',
        ], $overrides));
    }

    public function test_guest_is_shown_the_verification_gate(): void
    {
        $this->availableUnit();

        $this->get(route('share.inventory', 'test-company'))
            ->assertOk()
            ->assertSee('Confirm who you are')
            ->assertSee('View Available Units');
    }

    protected function shareCookie($response): object
    {
        return collect($response->headers->getCookies())->first(function ($cookie) {
            return str_starts_with($cookie->getName(), 'insulacrm_share');
        });
    }

    public function test_verification_creates_a_new_lead_with_captured_filters(): void
    {
        $unit = $this->availableUnit();
        $this->availableUnit(['unit_no' => '5001', 'availability' => 'leased', 'marketing_title' => 'Leased penthouse']);

        $response = $this->post('/s/test-company/verify?bedrooms=1&max_rent=120000', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
            'email' => 'sara@example.com',
        ]);

        $response->assertRedirect('/s/test-company?bedrooms=1&max_rent=120000');

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($lead);
        $this->assertSame('Sara', $lead->first_name);
        $this->assertSame('Khan', $lead->last_name);
        $this->assertSame('+971501234567', $lead->phone);
        $this->assertSame('Share Link', $lead->lead_source);
        $this->assertSame('rent', $lead->deal_type);
        $this->assertSame('new_lead', $lead->stage);
        $this->assertStringContainsString('1BR', $lead->notes);
        $this->assertStringContainsString('max AED 120,000', $lead->notes);
        $this->assertSame(['bedrooms' => '1', 'max_rent' => '120000'], $lead->custom_fields['share_link_filters']);

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'lead.created_via_share_link',
            'model_id' => $lead->id,
        ]);

        // A verified visitor sees available units, not leased ones.
        $cookie = $this->shareCookie($response);
        $this->assertNotNull($cookie);

        $inventory = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company?bedrooms=1');

        $inventory->assertOk()
            ->assertSee('1BR Apartment for Rent in Al Rihan Heights')
            ->assertDontSee('Leased penthouse')
            ->assertSee("Hi")
            ->assertSee('Sara');
    }

    public function test_verification_matches_an_existing_lead(): void
    {
        $existing = Lead::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Ahmad',
            'last_name' => 'Raza',
            'phone' => '+971509988776',
            'email' => 'ahmad@example.com',
            'lead_source' => 'Referral',
            'status' => 'new',
        ]);

        $response = $this->post('/s/test-company/verify', [
            'first_name' => 'Ahmad',
            'last_name' => 'Raza',
            'phone' => '+971509988776',
        ])->assertRedirect(route('share.inventory', 'test-company'));

        $this->assertSame(1, Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'lead.verified_share_link',
            'model_id' => $existing->id,
        ]);
    }

    public function test_interested_unit_is_linked_to_the_lead(): void
    {
        $unit = $this->availableUnit();

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $cookie = $this->shareCookie($verify);

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->post(route('share.interest', ['slug' => 'test-company', 'property' => $unit->id]))
            ->assertRedirect(route('share.inventory', 'test-company'))
            ->assertSessionHas('interest', $unit->id);

        $this->assertDatabaseHas('lead_property', [
            'lead_id' => $lead->id,
            'property_id' => $unit->id,
            'relation_type' => 'interest',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'tenant_id' => $this->tenant->id,
            'action' => 'lead.interested_in_unit',
            'model_id' => $lead->id,
        ]);
    }

    public function test_interest_without_verification_redirects_to_the_gate(): void
    {
        $unit = $this->availableUnit();

        $this->post(route('share.interest', ['slug' => 'test-company', 'property' => $unit->id]))
            ->assertRedirect(route('share.inventory', 'test-company'));

        $this->assertDatabaseMissing('lead_property', ['property_id' => $unit->id]);
    }

    public function test_sale_and_unavailable_units_are_not_shared(): void
    {
        $this->availableUnit(); // 1BR, available rent
        $this->availableUnit(['intent' => 'sale', 'marketing_title' => 'For Sale Villa']);
        $this->availableUnit(['availability' => 'leased', 'marketing_title' => 'Already Leased Unit', 'unit_no' => '3301']);

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);
        $cookie = $this->shareCookie($verify);

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company')
            ->assertOk()
            ->assertSee('1BR Apartment for Rent in Al Rihan Heights')
            ->assertDontSee('For Sale Villa')
            ->assertDontSee('Already Leased Unit');
    }
}