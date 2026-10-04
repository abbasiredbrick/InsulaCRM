<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenantWithAdmin();
    }

    public function test_layout_back_link_is_wired_without_alpine(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('js-back-link', false);
        $response->assertSee('data-fallback="'.route('dashboard').'"', false);
        $response->assertSee('aria-label="Go back"', false);
        $response->assertSee('__backLinkBooted', false);

        // The arrow must render even when there is no history to go back to,
        // because the fallback is always a valid destination. Gating it on
        // history.length left it invisible whenever a URL was opened directly.
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*js-back-link[^>]*style="[^"]*display:\s*none/',
            $response->getContent()
        );

        // Alpine is not loaded in this application, so any x-* attribute on the
        // back link would silently do nothing.
        $response->assertDontSee('x-data', false);
        $response->assertDontSee('x-on:click', false);
        $response->assertDontSee('x-show', false);

        // An SVG with fill="none" and no stroke colour defaults to stroke="none",
        // which paints nothing: an invisible but clickable button.
        $this->assertMatchesRegularExpression(
            '/<svg[^>]*stroke="currentColor"[^>]*>.*?M15 6l-6 6l6 6/s',
            $response->getContent()
        );
    }

    public function test_lead_show_back_link_returns_to_the_preferred_leads_view(): void
    {
        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)->get("/leads/{$lead->id}");

        $response->assertOk();

        // Forced, and pointed at /leads rather than a concrete view: /leads is
        // the dispatcher, so it resolves to whichever view this member chose.
        $response->assertSee('data-fallback="'.route('leads.index').'"', false);
        $response->assertSee('data-force="1"', false);
    }
}
