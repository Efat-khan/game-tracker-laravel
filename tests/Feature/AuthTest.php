<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_returns_a_token_and_the_cafe_it_belongs_to(): void
    {
        $cafe = $this->makeCafe('My Gaming Cafe');
        $this->makeUser($cafe, 'admin', 'admin@example.com');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret123',
        ]);

        $response->assertOk()->assertJson([
            'email' => 'admin@example.com',
            'role' => 'admin',
            'cafe_id' => $cafe->id,
            'cafe_name' => 'My Gaming Cafe',
        ]);

        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_a_suspended_cafe_cannot_log_in(): void
    {
        $cafe = $this->makeCafe('Closed Cafe', active: false);
        $this->makeUser($cafe, 'admin', 'shut@example.com');

        $this->postJson('/api/auth/login', [
            'email' => 'shut@example.com',
            'password' => 'secret123',
        ])->assertForbidden();
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $cafe = $this->makeCafe();
        $this->makeUser($cafe, 'admin', 'admin@example.com');

        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'nope',
        ])->assertUnauthorized();
    }
}
