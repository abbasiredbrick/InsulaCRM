<?php

namespace Tests\Feature;

use App\Helpers\TenantFormatHelper as Fmt;
use Tests\TestCase;

class CurrencyFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Fmt::forgetTenant();
    }

    public function test_aed_renders_a_readable_symbol_not_a_missing_glyph(): void
    {
        $this->actingAsAdmin(['currency' => 'AED', 'country' => 'AE']);
        Fmt::forgetTenant();

        $this->assertSame('AED', Fmt::currencySymbol());
        $this->assertSame('AED ', Fmt::currencyPrefix());
        $this->assertSame('AED 1,200.50', Fmt::currency(1200.5));
        $this->assertStringNotContainsString("\u{20C3}", Fmt::currency(1));
    }

    public function test_glyph_currencies_have_no_extra_space(): void
    {
        $this->actingAsAdmin(['currency' => 'USD']);
        Fmt::forgetTenant();

        $this->assertSame('$', Fmt::currencyPrefix());
        $this->assertSame('$1,200.00', Fmt::currency(1200));
    }

    public function test_the_currency_dropdown_shows_a_readable_aed_label(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        Fmt::forgetTenant();

        $this->assertSame('AED', Fmt::currencies()['AED']);
        $this->assertStringNotContainsString("\u{20C3}", implode(' ', Fmt::currencies()));
    }

    public function test_the_dashboard_shows_the_tenant_currency_symbol(): void
    {
        $this->actingAsAdmin(['currency' => 'AED', 'country' => 'AE', 'business_mode' => 'realestate']);
        Fmt::forgetTenant();

        $this->get(route('dashboard'))->assertOk()->assertSee('AED', false);
    }
}
