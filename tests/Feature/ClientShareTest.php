<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Property;
use App\Notifications\ShareInterest;
use Illuminate\Support\Facades\Notification;
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
            return str_starts_with($cookie->getName(), 'keystone_share');
        });
    }

    public function test_verification_creates_a_new_lead_with_captured_filters(): void
    {
        $unit = $this->availableUnit();
        // Distinct location: the list now labels units by size/intent/location,
        // so a leased twin of the available unit would be indistinguishable.
        $leased = $this->availableUnit([
            'unit_no' => '5001', 'availability' => 'leased', 'marketing_title' => 'Leased penthouse',
            'sub_community' => 'Leased Tower',
        ]);

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
            ->assertSee($unit->unitLabel())
            ->assertDontSee($leased->unitLabel())
            ->assertSee('Hi')
            ->assertSee('Sara');
    }

    public function test_verification_requires_a_phone(): void
    {
        $response = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'email' => 'sara@example.com',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(0, Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_verification_rejects_a_phone_without_a_country_code(): void
    {
        $response = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '0501234567',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(0, Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_verification_rejects_a_malformed_phone(): void
    {
        $response = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'phone' => '+971',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(0, Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_verification_normalizes_and_stores_an_international_phone(): void
    {
        $response = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971 50 123 4567',
        ]);

        $response->assertRedirect(route('share.inventory', 'test-company'));

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($lead);
        $this->assertSame('+971501234567', $lead->phone);
    }

    public function test_verification_allows_a_missing_last_name(): void
    {
        $response = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'phone' => '+971501234567',
        ]);

        $response->assertRedirect(route('share.inventory', 'test-company'));

        $lead = Lead::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($lead);
        $this->assertSame('Sara', $lead->first_name);
        $this->assertSame('', $lead->last_name);
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

    public function test_assigned_agent_is_emailed_when_a_viewer_registers_interest(): void
    {
        Notification::fake();

        $agent = $this->createUserWithRole('agent');
        $unit = $this->availableUnit(['assigned_agent_id' => $agent->id]);

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

        Notification::assertSentOnDemand(ShareInterest::class, function ($notification, $channels, $notifiable) use ($agent) {
            return ($notifiable->routes['mail'] ?? null) === $agent->email;
        });
    }

    public function test_no_email_is_sent_for_an_unassigned_unit(): void
    {
        Notification::fake();

        $unit = $this->availableUnit(['assigned_agent_id' => null]);

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);
        $cookie = $this->shareCookie($verify);

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->post(route('share.interest', ['slug' => 'test-company', 'property' => $unit->id]))
            ->assertSessionHas('interest', $unit->id);

        Notification::assertNothingSent();
    }

    public function test_share_pages_expose_pwa_meta_and_register_the_service_worker(): void
    {
        $this->get(route('share.inventory', 'test-company'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('/s/test-company/manifest.webmanifest')
            ->assertSee('theme-color', false)
            ->assertSee('#17212f')
            ->assertSee('apple-touch-icon', false)
            ->assertSee('service-worker.js')
            ->assertSee('navigator.serviceWorker.register');
    }

    public function test_share_manifest_is_a_tenant_scoped_installable_manifest(): void
    {
        $this->get('/s/test-company/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $this->get('/s/test-company/manifest.webmanifest')->json();

        $this->assertSame('Test Company — Available Units', $manifest['name']);
        $this->assertSame(url('/s/test-company'), $manifest['start_url']);
        $this->assertSame('/s/test-company/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#17212f', $manifest['theme_color']);
        $this->assertCount(2, $manifest['icons']);
        $this->assertSame('512x512', $manifest['icons'][1]['sizes']);
    }

    public function test_share_manifest_requires_an_active_tenant(): void
    {
        $this->get('/s/no-such-company/manifest.webmanifest')->assertNotFound();
    }

    public function test_sale_and_unavailable_units_are_not_shared(): void
    {
        $rented = $this->availableUnit(); // 1BR, available rent
        $sale = $this->availableUnit([
            'intent' => 'sale', 'marketing_title' => 'For Sale Villa', 'sub_community' => 'Sale Tower',
        ]);
        $leased = $this->availableUnit([
            'availability' => 'leased', 'marketing_title' => 'Already Leased Unit',
            'unit_no' => '3301', 'sub_community' => 'Leased Tower',
        ]);

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
        ]);
        $cookie = $this->shareCookie($verify);

        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company')
            ->assertOk()
            ->assertSee($rented->unitLabel())
            ->assertDontSee($sale->unitLabel())
            ->assertDontSee($leased->unitLabel());
    }

    public function test_public_inventory_prices_use_the_tenant_currency(): void
    {
        $this->tenant->update(['currency' => 'AED']);

        $this->availableUnit();

        $verify = $this->post('/s/test-company/verify', [
            'first_name' => 'Sara',
            'last_name' => 'Khan',
            'phone' => '+971501234567',
            'email' => 'sara@example.com',
        ]);
        $cookie = $this->shareCookie($verify);

        // The real visit is a guest: no authenticated user exists for the
        // currency helper to resolve a tenant from.
        auth()->logout();

        $inventory = $this->withCookie($cookie->getName(), $cookie->getValue())
            ->get('/s/test-company');

        $inventory->assertOk()
            ->assertSee('د.إ75,000')
            ->assertDontSee('$75,000')
            ->assertSee('Max Rent (AED)');
    }

    public function test_share_brandbar_spans_ends_on_desktop_and_stacks_on_phones(): void
    {
        $this->get(route('share.inventory', 'test-company'))
            ->assertOk()
            ->assertSee('flex-column flex-sm-row', false)
            ->assertSee('justify-content-between', false);
    }
}
