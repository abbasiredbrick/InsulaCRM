<?php

namespace Tests\Feature;

use App\Models\UserCloudConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_dashboard_prompts_to_connect_google_calendar(): void
    {
        $this->actingAsRole('agent', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-client-secret',
        ]);

        $response = $this->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Connect Google Calendar');
    }

    public function test_agent_dashboard_shows_connected_account_instead_of_prompt(): void
    {
        $agent = $this->actingAsRole('agent', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-client-secret',
        ]);

        UserCloudConnection::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $agent->id,
            'provider' => 'google',
            'scope' => 'calendar',
            'provider_account_email' => 'agent@gmail.com',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
        ]);

        $response = $this->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Calendar sync is on');
        $response->assertSee('agent@gmail.com');
        $response->assertDontSee('Connect Google Calendar');
    }

    public function test_dashboard_does_not_prompt_admins_to_connect(): void
    {
        $this->actingAsAdmin([
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-client-secret',
        ]);

        $this->get('/dashboard')->assertOk()->assertDontSee('Connect Google Calendar');
    }

    public function test_my_cloud_shows_the_connected_email(): void
    {
        $agent = $this->actingAsRole('agent', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-client-secret',
        ]);

        UserCloudConnection::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $agent->id,
            'provider' => 'microsoft',
            'scope' => 'calendar',
            'provider_account_email' => 'agent@outlook.com',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
        ]);

        $this->get(route('my-cloud.show'))
            ->assertOk()
            ->assertSee('Connected as')
            ->assertSee('agent@outlook.com');
    }

    public function test_start_blocks_external_redirects(): void
    {
        $this->actingAsRole('agent', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-client-secret',
        ]);

        $response = $this->get(route('cloud.start', [
            'provider' => 'google',
            'scope' => 'calendar',
            'redirect' => 'https://evil.example.com/steal',
        ]));

        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com', $response->headers->get('Location'));

        $payload = collect(session('cloud_oauth', []))->first();
        $this->assertSame(route('my-cloud.show'), $payload['redirect']);
    }

    public function test_start_allows_local_redirects(): void
    {
        $this->actingAsRole('agent', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-client-secret',
        ]);

        $this->get(route('cloud.start', [
            'provider' => 'google',
            'scope' => 'calendar',
            'redirect' => '/dashboard',
        ]))->assertRedirect();

        $payload = collect(session('cloud_oauth', []))->first();
        $this->assertSame('/dashboard', $payload['redirect']);
    }
}
