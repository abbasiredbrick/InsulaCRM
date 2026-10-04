<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PortalIntegration;
use App\Services\Portals\PortalLeadService;
use App\Services\Portals\PortalLinkResolver;
use App\Services\Portals\PropertyFinderPortalService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A portal lead has two links that go to different places: the property page
 * and the message thread with the agent. They used to be conflated, so the
 * "Listing" link opened a WhatsApp chat window.
 */
class PortalLinkSplitTest extends TestCase
{
    private PortalLinkResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $this->resolver = app(PortalLinkResolver::class);
    }

    private function pf(): PropertyFinderPortalService
    {
        return new PropertyFinderPortalService(PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]));
    }

    public static function contactUrlProvider(): array
    {
        return [
            'pf message thread' => ['https://www.propertyfinder.ae/leads/v1/lead/message/1jsTirgYEfGPTwIkur2pUQ/ueNTlytu'],
            'bayut pm thread' => ['https://www.bayut.com/pm/16475863/f00ffe29-a204-4c5b-9b52-46bfc554773f'],
            'bayut arabic pm thread' => ['https://www.bayut.com/ar/pm/16495131/f8a5bdaf-afe7-4a39-8347-55e45b6329e5'],
            'whatsapp host' => ['https://wa.me/971501234567'],
            'bayut enquiries' => ['https://www.bayut.com/enquiries/abc-123'],
        ];
    }

    #[DataProvider('contactUrlProvider')]
    public function test_it_recognises_message_threads(string $url): void
    {
        $this->assertTrue($this->resolver->isContactUrl($url), $url.' should be a contact link');
    }

    public static function listingUrlProvider(): array
    {
        return [
            'bayut details page' => ['https://www.bayut.com/property/details-16475863.html'],
            'pf property slug' => ['https://www.propertyfinder.ae/properties/marina-2bed'],
            'pf en property' => ['https://www.propertyfinder.ae/en/property/dubai/marina/2-bed'],
        ];
    }

    #[DataProvider('listingUrlProvider')]
    public function test_it_recognises_property_pages(string $url): void
    {
        $this->assertFalse($this->resolver->isContactUrl($url), $url.' should be a listing link');
    }

    public function test_property_finder_message_url_is_not_stored_as_the_listing(): void
    {
        // PF sends ONE url (responseLink) for both fields, and it is a thread.
        $messageUrl = 'https://www.propertyfinder.ae/leads/v1/lead/message/1jsTirgYEfGPTwIkur2pUQ/ueNTlytu';

        $links = $this->resolver->classify([$messageUrl, $messageUrl]);

        $this->assertNull($links['listing_url'], 'a chat thread must never be offered as the listing');
        $this->assertSame($messageUrl, $links['contact_link']);
    }

    public function test_bayut_listing_page_is_kept_as_the_listing(): void
    {
        $listing = 'https://www.bayut.com/property/details-16475863.html';

        $links = $this->resolver->classify([$listing, null]);

        $this->assertSame($listing, $links['listing_url']);
        $this->assertNull($links['contact_link']);
    }

    public function test_bayut_thread_url_derives_the_listing_page_from_its_id(): void
    {
        // Leads #10 and #24 share reference 10219-ZVrgZW and carry one URL of
        // each shape, which is what makes this derivation safe.
        $links = $this->resolver->resolve(
            ['https://www.bayut.com/pm/16475863/f00ffe29-a204-4c5b-9b52-46bfc554773f', null],
            'bayut'
        );

        $this->assertSame(
            'https://www.bayut.com/property/details-16475863.html',
            $links['listing_url']
        );
        $this->assertSame(
            'https://www.bayut.com/pm/16475863/f00ffe29-a204-4c5b-9b52-46bfc554773f',
            $links['contact_link']
        );
    }

    public function test_property_finder_reference_is_never_guessed_into_a_url(): void
    {
        $messageUrl = 'https://www.propertyfinder.ae/leads/v1/lead/message/abc/def';

        // resolve() must NOT derive a listing for PF - only the API can answer.
        $links = $this->resolver->resolve([$messageUrl, $messageUrl], 'propertyfinder');

        $this->assertNull($links['listing_url']);
        $this->assertSame($messageUrl, $links['contact_link']);
    }

    public function test_ingestion_files_a_pf_thread_under_contact_link(): void
    {
        $integration = PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $thread = 'https://www.propertyfinder.ae/leads/v1/lead/message/abc/def';

        $lead = app(PortalLeadService::class)->createFromPayload($integration, 'property_finder', [
            'id' => 'pf-thread-lead',
            'name' => 'Sara Nasser',
            'phone' => '+971500000777',
            'url' => $thread,
            'contact_link' => $thread,
        ]);

        $this->assertNotNull($lead);
        $this->assertArrayNotHasKey('listing_url', $lead->custom_fields);
        $this->assertSame($thread, $lead->custom_fields['contact_link']);
    }

    public function test_ingestion_splits_a_bayut_listing_from_its_thread(): void
    {
        $integration = PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'bayut',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $lead = app(PortalLeadService::class)->createFromPayload($integration, 'bayut', [
            'id' => 'bayut-thread-lead',
            'name' => 'Huda Karim',
            'phone' => '+971500000888',
            'url' => 'https://www.bayut.com/pm/16475863/f00ffe29-a204-4c5b-9b52-46bfc554773f',
            'reference' => '10219-ZVrgZW',
        ]);

        $this->assertNotNull($lead);
        $this->assertSame(
            'https://www.bayut.com/property/details-16475863.html',
            $lead->custom_fields['listing_url']
        );
        $this->assertSame(
            'https://www.bayut.com/pm/16475863/f00ffe29-a204-4c5b-9b52-46bfc554773f',
            $lead->custom_fields['contact_link']
        );
    }

    public function test_pf_listing_page_is_read_from_the_api(): void
    {
        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt-token',
                'tokenType' => 'Bearer',
                'expiresIn' => 1800,
            ]),
            'https://atlas.propertyfinder.com/v1/listings*' => Http::response([
                'results' => [[
                    'id' => 'L-9',
                    'url' => 'https://www.propertyfinder.ae/en/property/dubai/marina/marina-2-bed-for-sale',
                ]],
            ]),
        ]);

        $url = $this->pf()->listingPageUrl('7RT0GFWPJSS1AQMRGACRD3HBZC');

        $this->assertSame(
            'https://www.propertyfinder.ae/en/property/dubai/marina/marina-2-bed-for-sale',
            $url
        );
    }

    public function test_a_pf_message_url_is_never_accepted_as_the_listing(): void
    {
        // The worst outcome: a "Listing" link that opens a chat window.
        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt-token',
                'tokenType' => 'Bearer',
                'expiresIn' => 1800,
            ]),
            'https://atlas.propertyfinder.com/v1/listings*' => Http::response([
                'results' => [[
                    'id' => 'L-9',
                    'url' => 'https://www.propertyfinder.ae/leads/v1/lead/message/aaa/bbb',
                    'slug' => 'marina-2-bed',
                ]],
            ]),
        ]);

        $url = $this->pf()->listingPageUrl('7RT0GFWPJSS1AQMRGACRD3HBZC');

        $this->assertFalse(
            $this->resolver->isContactUrl($url),
            'a message thread must not survive as the listing URL, got: '.$url
        );
    }

    public function test_pf_listing_falls_back_to_reference_search_not_a_404_slug(): void
    {
        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt-token',
                'tokenType' => 'Bearer',
                'expiresIn' => 1800,
            ]),
            'https://atlas.propertyfinder.com/v1/listings*' => Http::response(['results' => [
                // A realistic PF listing payload: no url, no publicUrl, no slug.
                ['id' => 'L-9', 'reference' => '7RT0GFWPJSS1AQMRGACRD3HBZC', 'type' => 'apartment', 'state' => ['stage' => 'live']],
            ]]),
        ]);

        $url = $this->pf()->listingPageUrl('7RT0GFWPJSS1AQMRGACRD3HBZC');

        // propertyfinder.ae/properties/<slug> was tried against four live
        // references and 404s on every one, so it must never be stored.
        $this->assertSame(
            'https://www.propertyfinder.ae/en/search?q=7RT0GFWPJSS1AQMRGACRD3HBZC',
            $url
        );
        $this->assertStringNotContainsString('/properties/', (string) $url);
        $this->assertFalse($this->resolver->isContactUrl($url));
    }

    public function test_the_resolve_command_reports_and_updates_bayut_links(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Rami',
            'last_name' => 'Saleh',
            'lead_source' => 'bayut',
            'custom_fields' => [
                'portal' => 'bayut',
                // A thread URL misfiled as the listing - the production defect.
                'listing_url' => 'https://www.bayut.com/pm/16475863/abc-123',
            ],
        ]);

        $this->artisan('portals:resolve-listing-links --dry-run')
            ->assertSuccessful();

        $this->assertSame(
            'https://www.bayut.com/pm/16475863/abc-123',
            $lead->fresh()->custom_fields['listing_url'],
            '--dry-run must not write'
        );

        $this->artisan('portals:resolve-listing-links')->assertSuccessful();

        $fields = $lead->fresh()->custom_fields;

        $this->assertSame('https://www.bayut.com/property/details-16475863.html', $fields['listing_url']);
        $this->assertSame('https://www.bayut.com/pm/16475863/abc-123', $fields['contact_link']);
    }

    public function test_the_resolve_command_fills_a_pf_listing_url_from_the_api(): void
    {
        Http::fake([
            'https://atlas.propertyfinder.com/v1/auth/token' => Http::response([
                'accessToken' => 'pf-jwt-token',
                'tokenType' => 'Bearer',
                'expiresIn' => 1800,
            ]),
            'https://atlas.propertyfinder.com/v1/listings*' => Http::response([
                'results' => [[
                    'id' => 'L-9',
                    'url' => 'https://www.propertyfinder.ae/en/property/dubai/marina/marina-2-bed',
                ]],
            ]),
        ]);

        $thread = 'https://www.propertyfinder.ae/leads/v1/lead/message/aaa/bbb';

        // The command needs a real PF integration to authenticate with - it
        // refuses to guess a slug URL when it has no credentials.
        PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Lina',
            'last_name' => 'Fouad',
            'lead_source' => 'property_finder',
            'custom_fields' => [
                'portal' => 'propertyfinder',
                'listing_reference' => '7RT0GFWPJSS1AQMRGACRD3HBZC',
                'contact_link' => $thread,
            ],
        ]);

        $this->artisan('portals:resolve-listing-links')->assertSuccessful();

        $fields = $lead->fresh()->custom_fields;

        $this->assertSame(
            'https://www.propertyfinder.ae/en/property/dubai/marina/marina-2-bed',
            $fields['listing_url']
        );
        $this->assertSame($thread, $fields['contact_link'], 'the thread link must survive');
    }

    public function test_no_pf_integration_means_no_guessed_listing_url(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Maya',
            'last_name' => 'Rashid',
            'lead_source' => 'property_finder',
            'custom_fields' => [
                'portal' => 'propertyfinder',
                'listing_reference' => '7RT0GFWPJSS1AQMRGACRD3HBZC',
                'contact_link' => 'https://www.propertyfinder.ae/leads/v1/lead/message/aaa/bbb',
            ],
        ]);

        // No PF integration exists, so no credentials. An earlier version
        // resolved the client from the container, got an EMPTY integration,
        // failed auth and stored the guessed /properties/<slug> URL as if it
        // were a real listing. Better an empty slot than a 404.
        $this->artisan('portals:resolve-listing-links')->assertSuccessful();

        $this->assertArrayNotHasKey(
            'listing_url',
            $lead->fresh()->custom_fields,
            'without credentials the listing URL must stay empty, not be guessed'
        );
    }

    public function test_a_bayut_reference_is_never_looked_up_against_property_finder(): void
    {
        Http::fake([
            'https://atlas.propertyfinder.com/*' => Http::response(['results' => []]),
        ]);

        PortalIntegration::create([
            'tenant_id' => $this->tenant->id,
            'portal' => 'propertyfinder',
            'is_active' => true,
            'api_token' => 'key',
            'api_secret' => 'secret',
        ]);

        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Yara',
            'last_name' => 'Nabil',
            'lead_source' => 'bayut',
            'custom_fields' => [
                'portal' => 'bayut',
                'listing_reference' => '10219-J70XlC',
            ],
        ]);

        $this->artisan('portals:resolve-listing-links')->assertSuccessful();

        $fields = $lead->fresh()->custom_fields;

        $this->assertArrayNotHasKey(
            'listing_url',
            $fields,
            'a Bayut reference must not be resolved against the PF API'
        );
        $this->assertStringNotContainsString('propertyfinder.ae', json_encode($fields) ?: '');
    }

    public function test_an_already_correct_lead_is_reported_as_unchanged(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Dana',
            'last_name' => 'Khalil',
            'lead_source' => 'bayut',
            'custom_fields' => [
                'portal' => 'bayut',
                'listing_url' => 'https://www.bayut.com/property/details-16475863.html',
            ],
        ]);

        $before = $lead->fresh()->updated_at->toDateTimeString();

        $this->artisan('portals:resolve-listing-links')->assertSuccessful();

        // An absent contact_link must not read as a change (null vs '').
        $this->assertSame(
            $before,
            $lead->fresh()->updated_at->toDateTimeString(),
            'a lead with nothing to fix must not be written'
        );
    }

    public function test_lead_page_shows_both_links_under_distinct_headings(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Sara',
            'last_name' => 'Nasser',
            'lead_source' => 'bayut',
            'custom_fields' => [
                'listing_url' => 'https://www.bayut.com/property/details-16475863.html',
                'contact_link' => 'https://www.bayut.com/pm/16475863/abc',
            ],
        ]);

        $html = $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Portal Links')
            ->assertSee(__('Listing'))
            ->assertSee(__('WhatsApp conversation'))
            ->getContent();

        $this->assertStringContainsString('https://www.bayut.com/property/details-16475863.html', $html);
        $this->assertStringContainsString('https://www.bayut.com/pm/16475863/abc', $html);
    }
}
