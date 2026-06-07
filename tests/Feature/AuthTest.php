<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_read_profile_and_logout(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'company_name' => 'Acme Trading',
            'name' => 'Mona Admin',
            'email' => 'mona@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
        ]);

        $register
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.company.name', 'Acme Trading')
            ->assertJsonStructure(['data' => ['token']]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'mona@example.com',
            'password' => 'Secret#123',
            'device_name' => 'phpunit',
        ]);

        $token = $login->assertOk()->json('data.token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'mona@example.com')
            ->assertJsonPath('data.roles.0.name', 'Company Admin');

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_failed_login_is_audited(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'wrong',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('login_attempts', [
            'email' => 'missing@example.com',
            'successful' => false,
        ]);
    }
}
