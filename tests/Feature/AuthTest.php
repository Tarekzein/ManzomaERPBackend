<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_read_profile_and_logout(): void
    {
        $this->seed(SubscriptionSeeder::class);

        $register = $this->postJson('/api/auth/register', [
            'company_name' => 'Acme Trading',
            'name' => 'Mona Admin',
            'email' => 'mona@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'professional',
            'billing_cycle' => 'annual',
        ]);

        $register
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.company.name', 'Acme Trading')
            ->assertJsonPath('data.user.company.subscription.plan.slug', 'professional')
            ->assertJsonPath('data.user.company.subscription.billing_cycle', 'annual')
            ->assertJsonStructure(['data' => ['token']]);

        User::where('email', 'mona@example.com')->update(['last_activity_at' => now()->subDay()]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'mona@example.com',
            'password' => 'Secret#123',
            'device_name' => 'phpunit',
        ]);

        $token = $login->assertOk()->json('data.token');

        $this->assertTrue(User::where('email', 'mona@example.com')->firstOrFail()->last_activity_at->isToday());

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

    public function test_protected_api_endpoint_returns_json_unauthorized_without_accept_header(): void
    {
        $this->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
