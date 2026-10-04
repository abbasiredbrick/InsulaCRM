<?php

namespace Tests\Feature;

use App\Helpers\TextRenderHelper;
use Tests\TestCase;

/**
 * Bare URLs in free text must become clickable links, without ever becoming a
 * way to inject markup. The escaping guarantee is the point of these tests:
 * the output is rendered with {!! !!}, so a regression here is an XSS.
 */
class LinkifiedTextTest extends TestCase
{
    private function render(?string $text, bool $newlines = true): string
    {
        return TextRenderHelper::linkify($text, $newlines)->toHtml();
    }

    public function test_it_links_a_https_url(): void
    {
        $html = $this->render('See https://www.bayut.com/property/12345 for details');

        $this->assertStringContainsString('<a href="https://www.bayut.com/property/12345"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_it_links_a_bare_www_url_and_prefixes_https(): void
    {
        $html = $this->render('view www.propertyfinder.com/listing/abc');

        $this->assertStringContainsString('href="https://www.propertyfinder.com/listing/abc"', $html);
    }

    public function test_it_links_several_urls_in_one_block_of_text(): void
    {
        $html = $this->render("PF: https://www.propertyfinder.com/a\nBayut: http://bayut.com/b");

        $this->assertStringContainsString('href="https://www.propertyfinder.com/a"', $html);
        $this->assertStringContainsString('href="http://bayut.com/b"', $html);
    }

    public function test_it_preserves_query_strings_and_fragments(): void
    {
        $html = $this->render('https://atlas.propertyfinder.com/v1/listings?page=2&ref=abc#top');

        $this->assertStringContainsString('ref=abc#top', $html);
        // The ampersand must be encoded in the attribute, not emitted raw.
        $this->assertStringNotContainsString('?page=2&ref', $html);
        $this->assertStringContainsString('&amp;ref=abc', $html);
    }

    public function test_it_escapes_html_in_the_surrounding_text(): void
    {
        $html = $this->render('<script>alert(1)</script> see https://example.com/x');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('href="https://example.com/x"', $html);
    }

    public function test_it_does_not_link_a_dangerous_scheme(): void
    {
        // The scheme allowlist is the real defence: javascript: never reaches
        // an href, so it stays inert text.
        $html = $this->render('javascript:alert(1) and www.example.com');

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('href="https://www.example.com"', $html);
    }

    public function test_it_does_not_link_data_uris(): void
    {
        $html = $this->render('data:text/html;base64,PHNjcmlwdD4=');

        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_it_does_not_turn_an_escaped_quote_into_an_attribute_breakout(): void
    {
        $html = $this->render('https://example.com/"onmouseover="alert(1)');

        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringNotContainsString('"onmouseover', $html);
    }

    public function test_it_leaves_ordinary_text_untouched(): void
    {
        $html = $this->render('Call 0501234567 or 5/6 for a viewing. Price: 1.2M');

        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_it_does_not_double_link_a_url(): void
    {
        $html = $this->render('https://example.com/a');

        $this->assertSame(1, substr_count($html, '<a href='));
    }

    public function test_it_handles_empty_and_null_input(): void
    {
        $this->assertSame('', $this->render(null));
        $this->assertSame('', $this->render(''));
        $this->assertSame('', $this->render('   '));
    }

    public function test_it_renders_newlines_only_when_asked(): void
    {
        $this->assertStringContainsString('<br', $this->render("a\nb"));
        $this->assertStringNotContainsString('<br', $this->render("a\nb", false));
    }

    public function test_a_portal_lead_shows_a_clickable_listing_link(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        // Portal leads store the listing URL in custom_fields, and on this
        // tenant there are no CustomFieldDefinition rows at all - so the
        // "Additional Information" block renders nothing and the link has to
        // come from the portal block instead.
        $listing = 'https://www.propertyfinder.ae/leads/v1/lead/message/abc123';

        $lead = \App\Models\Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Omar',
            'last_name' => 'Haddad',
            'lead_source' => 'property_finder',
            'custom_fields' => [
                'portal' => 'propertyfinder',
                'listing_reference' => 'PXB67FGR',
                'listing_url' => $listing,
            ],
        ]);

        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Portal Links')
            ->assertSee('href="'.$listing.'"', false);
    }

    public function test_a_portal_lead_does_not_print_an_identical_url_twice(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        // Until a Property Finder lead's property page is resolved from the PF
        // API, both slots can hold the same thread URL. Two headings pointing
        // at the same place is noise, so only the first survives.
        $url = 'https://www.propertyfinder.ae/leads/v1/lead/message/fj41t7/vWJEMyyD';

        $lead = \App\Models\Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Aisha',
            'last_name' => 'Alsuwaidi',
            'lead_source' => 'property_finder',
            'custom_fields' => [
                'portal' => 'propertyfinder',
                'listing_url' => $url,
                'contact_link' => $url,
            ],
        ]);

        $html = $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Portal Links')
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'href="'.$url.'"'),
            'an identical URL must not be printed under two headings'
        );
    }

    public function test_a_portal_lead_keeps_distinct_listing_and_contact_links(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        // The property page and the message thread are different destinations,
        // so both must appear - each under its own heading.
        $lead = \App\Models\Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Omar',
            'last_name' => 'Haddad',
            'lead_source' => 'bayut',
            'custom_fields' => [
                'portal' => 'bayut',
                'listing_url' => 'https://www.bayut.com/property/details-16475863.html',
                'contact_link' => 'https://www.bayut.com/pm/16475863/abc',
            ],
        ]);

        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee(__('Listing'))
            ->assertSee(__('WhatsApp conversation'))
            ->assertSee('href="https://www.bayut.com/property/details-16475863.html"', false)
            ->assertSee('href="https://www.bayut.com/pm/16475863/abc"', false);
    }

    public function test_portal_ingestion_does_not_copy_the_url_into_notes(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $integration = \App\Models\PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $lead = app(\App\Services\Portals\PortalLeadService::class)->createFromPayload(
            $integration,
            'property_finder',
            [
                'id' => 'message_lead_1',
                'name' => 'Naila Farman',
                'phone' => '+971500000001',
                'message' => 'Is this still available?',
                'url' => 'https://www.propertyfinder.ae/leads/v1/lead/message/abc',
                'contact_link' => 'https://www.propertyfinder.ae/leads/v1/lead/message/abc',
            ]
        );

        $this->assertNotNull($lead);
        $this->assertStringNotContainsString(
            'propertyfinder.ae/leads',
            (string) $lead->notes,
            'the URL belongs in custom_fields, not in the notes'
        );
        $this->assertStringContainsString('Is this still available?', (string) $lead->notes);
    }

    public function test_a_lead_note_with_a_portal_listing_url_is_clickable(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $lead = \App\Models\Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Aisha',
            'last_name' => 'Alsuwaidi',
            'notes' => 'Interested, listing: https://www.propertyfinder.com/listing/abc123',
        ]);

        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('href="https://www.propertyfinder.com/listing/abc123"', false);
    }
}
