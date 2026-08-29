<?php

namespace Tests\Feature;

use App\Helpers\TenantFormatHelper;
use Tests\TestCase;

/**
 * The WhatsApp quick action on the lead detail page. Phone numbers are stored
 * however the user typed them, so the country code comes from the tenant rather
 * than being assumed.
 */
class WhatsAppQuickActionTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantFormatHelper::forgetTenant();
        parent::tearDown();
    }

    private function phoneFor(string $country, ?string $phone): ?string
    {
        $this->actingAsAdmin(['country' => $country]);
        TenantFormatHelper::setTenant($this->tenant);

        return $this->createLead(['phone' => $phone])->whatsapp_phone;
    }

    public function test_national_number_uses_the_tenant_country_code(): void
    {
        $this->assertSame('27821234567', $this->phoneFor('ZA', '082 123 4567'));
    }

    public function test_the_country_code_is_not_hardcoded_to_south_africa(): void
    {
        $this->assertSame('442079460958', $this->phoneFor('GB', '020 7946 0958'));
    }

    public function test_numbers_without_a_trunk_prefix_still_get_a_country_code(): void
    {
        // NANP numbers have no leading zero, so a naive trunk prefix rule misses them.
        $this->assertSame('15551234567', $this->phoneFor('US', '(555) 123 4567'));
    }

    public function test_explicit_international_numbers_are_left_alone(): void
    {
        $this->assertSame('27821234567', $this->phoneFor('US', '+27 82 123 4567'));
    }

    public function test_double_zero_international_prefix_is_stripped(): void
    {
        $this->assertSame('27821234567', $this->phoneFor('US', '0027821234567'));
    }

    public function test_a_number_already_carrying_its_country_code_is_not_doubled(): void
    {
        $this->assertSame('27821234567', $this->phoneFor('ZA', '27821234567'));
    }

    public function test_missing_or_unusable_numbers_yield_null(): void
    {
        $this->actingAsAdmin(['country' => 'US']);
        TenantFormatHelper::setTenant($this->tenant);

        foreach ([null, '', 'n/a', '123'] as $unusable) {
            $this->assertNull(
                $this->createLead(['phone' => $unusable])->whatsapp_phone,
                'Unusable input should produce no link: ' . var_export($unusable, true)
            );
        }
    }

    public function test_the_button_appears_on_the_lead_page(): void
    {
        $this->actingAsAdmin(['country' => 'ZA']);
        TenantFormatHelper::setTenant($this->tenant);
        $lead = $this->createLead(['phone' => '082 123 4567', 'do_not_contact' => false]);

        $this->get("/leads/{$lead->id}")
            ->assertStatus(200)
            ->assertSee('https://wa.me/27821234567');
    }

    public function test_the_button_respects_do_not_contact(): void
    {
        $this->actingAsAdmin(['country' => 'ZA']);
        TenantFormatHelper::setTenant($this->tenant);
        $lead = $this->createLead(['phone' => '082 123 4567', 'do_not_contact' => true]);

        $this->get("/leads/{$lead->id}")
            ->assertStatus(200)
            ->assertDontSee('wa.me');
    }
}
