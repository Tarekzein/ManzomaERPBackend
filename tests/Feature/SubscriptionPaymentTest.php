<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_failed_and_pending_payments_do_not_activate_company_or_subscription(): void
    {
        $this->seed(SubscriptionSeeder::class);

        $register = $this->registerPendingCompany();
        $reference = $register->json('data.checkout.reference');
        $token = $register->json('data.checkout.registration_token');

        $this->postJson("/api/payments/{$reference}/mock-result", [
            'registration_token' => $token,
            'status' => 'pending',
        ])->assertOk()->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.auth', null);

        $this->postJson("/api/payments/{$reference}/mock-result", [
            'registration_token' => $token,
            'status' => 'failed',
        ])->assertOk()->assertJsonPath('data.payment.status', 'failed')->assertJsonPath('data.auth', null);

        $company = Company::where('name', 'Checkout Co')->firstOrFail();
        $this->assertFalse($company->is_active);
        $this->assertSame(0, $company->subscriptions()->where('status', 'active')->count());
    }

    public function test_successful_payment_is_idempotent(): void
    {
        $this->seed(SubscriptionSeeder::class);

        $register = $this->registerPendingCompany();
        $reference = $register->json('data.checkout.reference');
        $token = $register->json('data.checkout.registration_token');

        $payload = [
            'registration_token' => $token,
            'status' => 'succeeded',
            'device_name' => 'phpunit',
        ];

        $this->postJson("/api/payments/{$reference}/mock-result", $payload)->assertOk();
        $this->postJson("/api/payments/{$reference}/mock-result", $payload)->assertOk();

        $company = Company::where('name', 'Checkout Co')->firstOrFail();
        $this->assertTrue($company->is_active);
        $this->assertSame(1, $company->subscriptions()->where('status', 'active')->count());
        $this->assertSame('succeeded', SubscriptionPayment::where('reference', $reference)->value('status'));
    }

    public function test_paymob_callback_signature_activates_payment_idempotently(): void
    {
        config(['services.paymob.hmac_secret' => 'testing-secret']);
        $this->seed(SubscriptionSeeder::class);

        $register = $this->registerPendingCompany();
        $reference = $register->json('data.checkout.reference');
        $payload = [
            'reference' => $reference,
            'success' => true,
            'id' => 'txn-100',
            'order' => ['id' => 'order-100'],
        ];
        $signature = hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), 'testing-secret');

        $this->postJson('/api/payments/paymob/callback', $payload, ['X-Paymob-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded');
        $this->postJson('/api/payments/paymob/callback', $payload, ['X-Paymob-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded');

        $company = Company::where('name', 'Checkout Co')->firstOrFail();
        $this->assertTrue($company->is_active);
        $this->assertSame(1, $company->subscriptions()->where('status', 'active')->count());
    }

    public function test_company_setup_can_be_saved_after_payment(): void
    {
        $this->seed(SubscriptionSeeder::class);

        $register = $this->registerPendingCompany();
        $reference = $register->json('data.checkout.reference');
        $token = $register->json('data.checkout.registration_token');

        $auth = $this->postJson("/api/payments/{$reference}/mock-result", [
            'registration_token' => $token,
            'status' => 'succeeded',
        ])->assertOk()->json('data.auth');

        $this->withToken($auth['token'])
            ->putJson('/api/company/setup', [
                'display_name' => 'Checkout Co ERP',
                'address' => 'Cairo, Egypt',
                'contact_email' => 'ops@example.com',
                'contact_phone' => '+201000000000',
            ])->assertOk()
            ->assertJsonPath('data.settings.display_name', 'Checkout Co ERP')
            ->assertJsonPath('data.settings.address', 'Cairo, Egypt');
    }

    public function test_inactive_user_cannot_login_and_company_admin_can_deactivate_user(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $employee = User::factory()->create([
            'company_id' => $admin->company_id,
            'email' => 'inactive@example.com',
            'password' => bcrypt('Secret#123'),
        ]);
        $employee->assignRole('Employee');
        Sanctum::actingAs($admin);

        $this->postJson("/api/users/{$employee->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson('/api/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'Secret#123',
        ])->assertUnprocessable();
    }

    private function registerPendingCompany()
    {
        return $this->postJson('/api/auth/register', [
            'company_name' => 'Checkout Co',
            'name' => 'Checkout Admin',
            'email' => 'checkout@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertCreated();
    }
}
