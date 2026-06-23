<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_checkout_then_payment_success_activates_login_profile_and_logout(): void
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
            ->assertJsonPath('data.company.name', 'Acme Trading')
            ->assertJsonPath('data.company.is_active', false)
            ->assertJsonPath('data.plan.slug', 'professional')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonStructure(['data' => ['checkout' => ['reference', 'registration_token']]]);

        $this->postJson('/api/auth/login', [
            'email' => 'mona@example.com',
            'password' => 'Secret#123',
            'device_name' => 'phpunit',
        ])->assertUnprocessable();

        $reference = $register->json('data.checkout.reference');
        $registrationToken = $register->json('data.checkout.registration_token');

        $payment = $this->postJson("/api/payments/{$reference}/mock-result", [
            'registration_token' => $registrationToken,
            'status' => 'succeeded',
            'device_name' => 'phpunit',
        ])->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded')
            ->assertJsonPath('data.auth.user.company.is_active', true)
            ->assertJsonStructure(['data' => ['auth' => ['token']]])
            ->json('data.auth');

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

        $this->assertGreaterThanOrEqual(1, PersonalAccessToken::count());
        $this->assertDatabaseHas('company_subscriptions', [
            'status' => 'active',
            'billing_cycle' => 'annual',
        ]);
        $this->assertSame('succeeded', SubscriptionPayment::where('reference', $reference)->value('status'));
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
