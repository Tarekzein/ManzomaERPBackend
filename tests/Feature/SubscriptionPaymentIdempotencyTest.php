<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionPaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_SECRET = 'test-hmac-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionSeeder::class);
        config([
            'services.paymob.hmac_secret' => self::HMAC_SECRET,
            'services.paymob.currency' => 'EGP',
        ]);
    }

    public function test_reopening_a_live_checkout_reuses_the_same_paymob_order(): void
    {
        $this->useLivePaymob();
        $this->fakeIntentions();

        [$reference, $token] = $this->registerForCheckout();
        $first = SubscriptionPayment::where('reference', $reference)->firstOrFail();

        // The customer clicks "pay" again before the session expires.
        $again = $this->postJson("/api/payments/{$reference}/checkout", ['registration_token' => $token])
            ->assertOk()
            ->json('data');

        $this->assertSame($first->checkout_url, $again['checkout_url']);
        $this->assertSame(1, SubscriptionPayment::where('reference', $reference)->value('checkout_attempts'));
        // Only the registration created an intention — no second payable order.
        Http::assertSentCount(1);
    }

    public function test_an_expired_checkout_mints_a_new_order_with_a_fresh_reference(): void
    {
        $this->useLivePaymob();
        $this->fakeIntentions();

        [$reference, $token] = $this->registerForCheckout();
        SubscriptionPayment::where('reference', $reference)->update(['checkout_expires_at' => now()->subMinute()]);

        $refreshed = $this->postJson("/api/payments/{$reference}/checkout", ['registration_token' => $token])
            ->assertOk()
            ->json('data');

        $payment = SubscriptionPayment::where('reference', $reference)->firstOrFail();
        $this->assertSame(2, $payment->checkout_attempts);
        $this->assertSame("{$reference}.2", $payment->provider_reference);
        $this->assertStringContainsString('secret-2', (string) $refreshed['checkout_url']);
        Http::assertSentCount(2);

        // Paymob refuses a reused special_reference, so each attempt sends its own.
        Http::assertSent(fn ($request) => ($request->data()['special_reference'] ?? null) === "{$reference}.2");

        // A callback carrying the retry reference still resolves to the invoice.
        // (Signature verification itself is covered in PaymobCheckoutGatewayTest.)
        config(['services.paymob.mode' => 'mock']);
        $this->postCallback($this->transactionObject("{$reference}.2"));
        $this->assertSame('succeeded', $payment->refresh()->status);
        $this->assertTrue($payment->company->refresh()->is_active);
    }

    public function test_a_paid_checkout_cannot_be_reopened(): void
    {
        [$reference, $token] = $this->registerForCheckout();
        $this->postCallback($this->transactionObject($reference));

        $this->postJson("/api/payments/{$reference}/checkout", ['registration_token' => $token])
            ->assertStatus(409);
    }

    public function test_a_second_transaction_on_a_paid_invoice_is_flagged_instead_of_charged_again(): void
    {
        [$reference] = $this->registerForCheckout();

        $this->postCallback($this->transactionObject($reference, ['id' => 111]));
        $this->postCallback($this->transactionObject($reference, ['id' => 222]));

        $payment = SubscriptionPayment::where('reference', $reference)->firstOrFail();
        $this->assertSame('succeeded', $payment->status);
        // The settling transaction is kept and the extra one is recorded for a refund.
        $this->assertSame('111', $payment->provider_transaction_id);
        $this->assertSame(['222'], $payment->metadata['duplicate_transactions']);
        $this->assertSame(1, $payment->company->subscriptions()->where('status', 'active')->count());
    }

    public function test_paying_a_superseded_checkout_does_not_start_a_second_subscription(): void
    {
        [$admin, $company] = $this->companyWithSubscription();
        Sanctum::actingAs($admin);

        $stale = $this->postJson('/api/subscriptions/checkout', ['plan_slug' => 'professional', 'billing_cycle' => 'monthly'])
            ->assertCreated()
            ->json('data.checkout.reference');
        $current = $this->postJson('/api/subscriptions/checkout', ['plan_slug' => 'enterprise', 'billing_cycle' => 'monthly'])
            ->assertCreated()
            ->json('data.checkout.reference');

        $this->app['auth']->forgetGuards();
        $this->postCallback($this->transactionObject($current, ['id' => 900]));

        // The older link is closed the moment a newer checkout settles.
        $staleModel = SubscriptionPayment::where('reference', $stale)->firstOrFail();
        $this->assertSame('failed', $staleModel->status);
        $this->assertNull($staleModel->checkout_url);

        // Paying it anyway must not replace the subscription that was just bought.
        $this->postCallback($this->transactionObject($stale, ['id' => 901]));

        $staleModel->refresh();
        $this->assertSame('succeeded', $staleModel->status);
        $this->assertTrue($staleModel->metadata['duplicate_payment']);
        $this->assertSame(1, $company->subscriptions()->where('status', 'active')->count());
        $this->assertSame(
            'enterprise',
            $company->subscriptions()->where('status', 'active')->firstOrFail()->plan->slug
        );
    }

    public function test_requesting_the_same_plan_change_twice_reuses_one_invoice(): void
    {
        [$admin] = $this->companyWithSubscription();
        Sanctum::actingAs($admin);

        $first = $this->postJson('/api/subscriptions/checkout', ['plan_slug' => 'enterprise', 'billing_cycle' => 'monthly'])
            ->assertCreated()->json('data.checkout.reference');
        $second = $this->postJson('/api/subscriptions/checkout', ['plan_slug' => 'enterprise', 'billing_cycle' => 'monthly'])
            ->assertCreated()->json('data.checkout.reference');

        $this->assertSame($first, $second);
        $this->assertSame(1, SubscriptionPayment::where('purpose', 'upgrade')->count());
    }

    private function useLivePaymob(): void
    {
        config([
            'services.paymob.mode' => 'live',
            'services.paymob.public_key' => 'egy_pk_test_public',
            'services.paymob.secret_key' => 'egy_sk_test_secret',
            'services.paymob.integration_id' => '5818094',
        ]);
    }

    private function fakeIntentions(): void
    {
        $attempt = 0;
        Http::fake([
            'accept.paymob.com/v1/intention/' => function () use (&$attempt) {
                $attempt++;

                return Http::response([
                    'id' => "pi_test_{$attempt}",
                    'client_secret' => "secret-{$attempt}",
                    'intention_order_id' => 700 + $attempt,
                ]);
            },
        ]);
    }

    /** @return array{0: string, 1: string} reference and registration token */
    private function registerForCheckout(): array
    {
        $response = $this->postJson('/api/auth/register', [
            'company_name' => 'Idempotent Co',
            'name' => 'Idempotent Admin',
            'email' => 'idempotent@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertCreated();

        return [$response->json('data.checkout.reference'), $response->json('data.checkout.registration_token')];
    }

    /** @return array{0: User, 1: Company} */
    private function companyWithSubscription(): array
    {
        $company = Company::factory()->create(['plan' => 'basic', 'is_active' => true]);
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $admin->assignRole(UserRole::CompanyAdmin->value);

        CompanySubscription::create([
            'company_id' => $company->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'basic')->value('id'),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'auto_renew' => true,
            'starts_at' => now(),
            'current_period_started_at' => now(),
            'current_period_ends_at' => now()->addMonthNoOverflow(),
            'provider' => 'paymob',
        ]);

        return [$admin, $company];
    }

    private function transactionObject(string $reference, array $overrides = []): array
    {
        return array_replace([
            'merchant_order_id' => $reference,
            'success' => true,
            'id' => 500,
            'order' => ['id' => 9001],
        ], $overrides);
    }

    private function postCallback(array $payload): void
    {
        $this->postJson('/api/payments/paymob/callback', $payload, [
            'X-Paymob-Signature' => hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), self::HMAC_SECRET),
        ])->assertOk();
    }
}
