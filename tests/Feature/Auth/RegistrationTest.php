<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_page_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_submission_is_disabled(): void
    {
        $this->post('/register', [
            'name' => 'John Doe',
            'company_name' => 'Test Corp',
            'email' => 'john@testcorp.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'john@testcorp.com']);
    }

    public function test_login_page_does_not_link_to_registration(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertDontSee('route(\'register\')');
        $response->assertDontSee('href="/register"');
    }
}
