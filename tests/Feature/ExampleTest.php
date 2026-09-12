<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Guests are served the landing page; authenticated users go to the dashboard.
     */
    public function test_root_serves_landing_page_for_guests(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('redbrickworks.com');
    }

    public function test_root_redirects_authenticated_users_to_dashboard(): void
    {
        $this->actingAsAdmin();

        $this->get('/')->assertRedirect(route('dashboard'));
    }
}
