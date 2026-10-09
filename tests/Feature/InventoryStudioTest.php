<?php

namespace Tests\Feature;

use App\Models\PortalIntegration;
use App\Models\Property;
use App\Services\Portals\BayutPortalService;
use App\Services\Portals\PropertyFinderPortalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A Studio is a SIZE, not a category - a studio is still an apartment, and
 * agents differentiate units as studio / 1BR / 2BR / 3BR. It is stored as
 * bedrooms = 0, which is FALSY in PHP, so every plain
 * `$property->bedrooms ? … : null` dropped studios from labels and the
 * inventory showed "0 bd". Nothing could be picked as a studio either, so
 * units named Studio were entered as 1BR and published as 1BR.
 */
class InventoryStudioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    public static function bedroomLabelProvider(): array
    {
        return [
            'studio' => [0, 'Studio'],
            'one bed' => [1, '1BR'],
            'two beds' => [2, '2BR'],
            'unrecorded' => [null, ''],
        ];
    }

    #[DataProvider('bedroomLabelProvider')]
    public function test_bedroom_label(?int $bedrooms, string $expected): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => $bedrooms,
        ]);

        $this->assertSame($expected, $property->bedroomLabel());
        $this->assertSame($bedrooms === 0, $property->isStudio());
    }

    public function test_a_studio_is_still_an_apartment(): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 0,
            'community' => 'Yas Island',
        ]);

        $this->assertTrue($property->isStudio());
        $this->assertSame('Studio', $property->bedroomLabel());
        // Studio is not a property_category, so apartment queries match it.
        $this->assertSame('apartment', $property->property_category);
        $this->assertArrayNotHasKey('studio', Property::CATEGORIES);
        // The size prints once, as "Studio", and the unit stays an apartment.
        $this->assertSame(1, substr_count($property->display_name, 'Studio'));
        $this->assertSame('Studio for Rent in Yas Island', $property->display_name);
    }

    public function test_bedroom_label_is_empty_when_unset(): void
    {
        // null means "not recorded" and must NOT read as a studio.
        $property = new Property;

        $this->assertSame('', $property->bedroomLabel());
        $this->assertFalse($property->isStudio());
    }

    public function test_studio_appears_in_the_generated_display_name(): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 0,
            'community' => 'Yas Island',
        ]);

        $this->assertSame('Studio for Rent in Yas Island', $property->display_name);
    }

    public function test_a_studio_never_prints_the_word_twice(): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 0,
            'bathrooms' => 1,
            'community' => 'Yas Island',
        ]);

        $this->assertSame(1, substr_count($property->display_name, 'Studio'));
        $this->assertSame(1, substr_count($property->optionLabel(), 'Studio'));
    }

    public function test_studio_appears_in_the_option_label(): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 0,
            'bathrooms' => 1,
            'community' => 'Yas Island',
        ]);

        $label = $property->optionLabel();

        $this->assertStringContainsString('Studio', $label);
        $this->assertStringContainsString('1 BA', $label);
    }

    public function test_the_unit_form_offers_a_studio_option(): void
    {
        $this->get(route('inventory.create'))
            ->assertOk()
            ->assertSee('Studio')
            ->assertSee('id="isStudio"', false)
            // Studio is not a category - the category dropdown is unaffected.
            ->assertSee('<option value="apartment"', false)
            ->assertDontSee('<option value="studio"', false);
    }

    public function test_a_studio_can_be_saved_with_zero_bedrooms(): void
    {
        $response = $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'community' => 'Yas Island',
            'rent_price' => 65000,
            'rent_period' => 'monthly',
            'bedrooms' => 0,
            'bathrooms' => 1,
            'availability' => 'ready_to_list',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('errors');

        $property = Property::withoutGlobalScopes()->orderByDesc('id')->first();
        $this->assertNotNull($property, 'the studio unit should have been created');
        $this->assertSame(0, (int) $property->bedrooms, 'zero bedrooms must persist as a studio');
        $this->assertSame('apartment', $property->property_category, 'a studio is still an apartment');
        $this->assertTrue($property->isStudio());
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $this->post(route('inventory.store'), [
            'intent' => 'rent',
            'market_class' => 'ready',
            'property_category' => 'penthousee',
            'community' => 'Yas Island',
            'rent_price' => 65000,
            'rent_period' => 'monthly',
            'availability' => 'ready_to_list',
        ])->assertSessionHasErrors('property_category');
    }

    public function test_the_inventory_detail_page_says_studio_not_zero(): void
    {
        $property = $this->createProperty([
            'marketing_title' => 'Studio in Yas Island',
            'property_category' => 'apartment',
            'bedrooms' => 0,
            'availability' => 'listed',
        ]);

        $html = $this->get(route('inventory.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('Studio', $html);
        $this->assertStringNotContainsString('0 bd', $html);
    }

    public function test_the_beds_filter_offers_studio(): void
    {
        $html = $this->get(route('inventory.index'))->assertOk()->getContent();

        $this->assertStringContainsString('>Studio<', $html);
    }

    public function test_a_studio_publishes_to_property_finder_as_studio(): void
    {
        // Both hosts: base() switches on the integration's sandbox flag.
        Http::fake([
            'https://*.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt', 'tokenType' => 'Bearer', 'expiresIn' => 1800,
            ]),
            'https://*.propertyfinder.com/v1/listings' => Http::response(['id' => 'L-1']),
            'https://*.propertyfinder.com/v1/listings/*/publish' => Http::response([
                'id' => 'L-1',
                'url' => 'https://www.propertyfinder.ae/properties/x',
            ]),
        ]);

        $property = $this->createProperty([
            'market_class' => 'ready',
            'property_category' => 'apartment',
            'intent' => 'rent',
            'bedrooms' => 0,
            'bathrooms' => 1,
            'rent_price' => 65000,
            'rent_period' => 'monthly',
            'propertyfinder_listing_reference' => 'PF-STUDIO-1',
        ]);

        // PF refuses to publish without at least one photo.
        DB::table('property_media')->insert([
            'tenant_id' => $this->tenant->id,
            'property_id' => $property->id,
            'type' => 'photo',
            'original_name' => 'studio.png',
            'path' => 'photos/studio.png',
            'uploaded_by' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Never resolve PF from the container: its constructor takes a
        // PortalIntegration and the container would hand it an empty one.
        $service = new PropertyFinderPortalService(PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
            'default_location_id' => 'loc-1',
            'public_profile_id' => 'profile-1',
        ]));

        $result = $service->publish($property);

        $this->assertTrue($result['ok'] ?? false, (string) ($result['message'] ?? ''));

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1/listings')) {
                return false;
            }

            $data = $request->data();

            // A studio is an apartment whose bedrooms read "studio": PF has no
            // studio *type*.
            return ($data['bedrooms'] ?? null) === 'studio'
                && ($data['type'] ?? null) === 'apartment';
        });
    }

    public function test_a_studio_publishes_to_bayut_with_zero_beds(): void
    {
        $service = new BayutPortalService(PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
            'base_url' => 'https://api.bayut.com',
        ]));

        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 0,
            'bathrooms' => 1,
            'intent' => 'rent',
        ]);

        $method = new \ReflectionMethod($service, 'payload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, $property);

        $this->assertSame(4, $payload['categoryId']);
        $this->assertSame(0, $payload['beds']);
    }

    public function test_a_one_bedroom_apartment_is_not_mistaken_for_a_studio(): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 1,
        ]);

        $this->assertFalse($property->isStudio());
        $this->assertSame('1BR', $property->bedroomLabel());
    }

    /**
     * The size format is "2BR" with no space everywhere, so the inventory list
     * cannot show "3BR" and "3 BR" side by side. Imported units render their
     * stored marketing_title; hand-made units render the computed one, so both
     * paths are asserted here against the same string.
     */
    public function test_the_size_label_has_no_space(): void
    {
        $computed = new Property(['property_category' => 'townhouse', 'bedrooms' => 3, 'sub_community' => 'Reem Hills']);
        $this->assertSame('3BR', $computed->bedroomLabel());
        $this->assertSame('3BR for Rent in Reem Hills', $computed->display_name);

        // What an import writes, through the same helper.
        $service = new \App\Services\AvailabilityIngestService;
        $method = (new \ReflectionClass($service))->getMethod('buildMarketingTitle');
        $method->setAccessible(true);

        $this->assertSame('3BR Townhouse for Rent in Reem Hills', $method->invoke($service, 3, 'townhouse', 'Reem Hills'));
        $this->assertSame('1BR Apartment for Rent in Marina Gate', $method->invoke($service, 1, 'apartment', 'Marina Gate'));
        $this->assertSame('Studio Apartment for Rent in Marina Gate', $method->invoke($service, 0, 'apartment', 'Marina Gate'));
        $this->assertSame('Apartment for Rent in Marina Gate', $method->invoke($service, null, 'apartment', 'Marina Gate'));
    }

    /**
     * The unit label is "3BR for Rent in {sub_community}, {community}" and is
     * built for every unit from the record itself, so the list cannot show two
     * different formats depending on whether a title happens to be stored.
     */
    public function test_the_unit_label_format(): void
    {
        $label = fn (array $attrs) => (new Property($attrs))->unitLabel();

        $this->assertSame(
            '3BR for Rent in Reem Hills, Yas Island',
            $label(['bedrooms' => 3, 'intent' => 'rent', 'sub_community' => 'Reem Hills', 'community' => 'Yas Island'])
        );
        $this->assertSame(
            'Studio for Rent in Bloom Towers B, Bloom Towers',
            $label(['bedrooms' => 0, 'intent' => 'rent', 'sub_community' => 'Bloom Towers B', 'community' => 'Bloom Towers'])
        );
        $this->assertSame(
            '2BR for Sale in Yas Island',
            $label(['bedrooms' => 2, 'intent' => 'sale', 'sub_community' => null, 'community' => 'Yas Island'])
        );
        $this->assertSame(
            '4BR for Rent / Sale in Reem Hills, Yas Island',
            $label(['bedrooms' => 4, 'intent' => 'both', 'sub_community' => 'Reem Hills', 'community' => 'Yas Island'])
        );
        // An unrecorded size prints nothing rather than a guessed number.
        $this->assertSame(
            'for Rent in Reem Hills, Yas Island',
            $label(['bedrooms' => null, 'intent' => 'rent', 'sub_community' => 'Reem Hills', 'community' => 'Yas Island'])
        );
        // Sub-community and community identical must not repeat.
        $this->assertSame(
            '1BR for Rent in Yas Island',
            $label(['bedrooms' => 1, 'intent' => 'rent', 'sub_community' => 'Yas Island', 'community' => 'Yas Island'])
        );
    }

    /**
     * The label is a CRM-side list label. The agent's marketing copy is still
     * what gets published, so relabelling never rewrites a live listing title.
     */
    public function test_the_label_ignores_marketing_title_but_publishing_does_not(): void
    {
        $property = new Property([
            'bedrooms' => 3,
            'intent' => 'rent',
            'sub_community' => 'Reem Hills',
            'community' => 'Yas Island',
            'marketing_title' => 'Uptown living with full marina views',
        ]);

        $this->assertSame('3BR for Rent in Reem Hills, Yas Island', $property->display_name);
        $this->assertSame('Uptown living with full marina views', $property->listingTitle());

        // With no marketing copy, publishing falls back to the label.
        $plain = new Property([
            'bedrooms' => 2,
            'intent' => 'rent',
            'sub_community' => 'Marina Gate',
            'community' => 'Dubai Marina',
        ]);
        $this->assertSame('2BR for Rent in Marina Gate, Dubai Marina', $plain->listingTitle());
    }

    public function test_option_label_does_not_repeat_size_or_location(): void
    {
        $property = new Property([
            'bedrooms' => 2,
            'bathrooms' => 3,
            'intent' => 'rent',
            'sub_community' => 'Marina Gate',
            'community' => 'Dubai Marina',
            'rent_price' => 120000,
        ]);

        $label = $property->optionLabel();

        $this->assertStringStartsWith('2BR for Rent in Marina Gate, Dubai Marina', $label);
        $this->assertStringContainsString('3 BA', $label);
        // Exactly one size mention and one location mention.
        $this->assertSame(1, substr_count($label, '2BR'));
        $this->assertSame(1, substr_count($label, 'Dubai Marina'));
    }

    /**
     * An unrecorded size falls back to the category word, never to a guessed
     * bedroom count — so a shop with no size on file reads "Shop for Rent in
     * Marafid", not a bare "for Rent in Marafid".
     */
    public function test_an_unrecorded_size_falls_back_to_the_category_label(): void
    {
        $property = new Property(['property_category' => 'apartment', 'bedrooms' => null, 'sub_community' => 'Reem Hills']);

        $this->assertSame('', $property->bedroomLabel());
        $this->assertSame('Apartment for Rent in Reem Hills', $property->display_name);

        $shop = new Property(['property_category' => 'shop', 'bedrooms' => null, 'sub_community' => 'Marafid']);
        $this->assertSame('Shop for Rent in Marafid', $shop->display_name);
    }

    /**
     * A maid's room is a premium feature, so its "+ Maid" sits on the label
     * and on the marketing title in the same place.
     */
    public function test_a_maids_room_is_carried_on_the_label_and_title(): void
    {
        $property = new Property([
            'property_category' => 'apartment',
            'bedrooms' => 2,
            'maids_room' => true,
            'sub_community' => 'Burj Al Shams',
            'community' => 'Al Reem Island',
        ]);

        $this->assertSame('2BR + Maid', $property->sizeLabel());
        $this->assertSame('2BR + Maid for Rent in Burj Al Shams, Al Reem Island', $property->display_name);

        $service = new \App\Services\AvailabilityIngestService;
        $method = (new \ReflectionClass($service))->getMethod('buildMarketingTitle');
        $method->setAccessible(true);

        $this->assertSame('2BR + Maid Apartment for Rent in Burj Al Shams', $method->invoke($service, 2, 'apartment', 'Burj Al Shams', true));
        $this->assertSame('2BR Apartment for Rent in Burj Al Shams', $method->invoke($service, 2, 'apartment', 'Burj Al Shams', false));
    }
}
