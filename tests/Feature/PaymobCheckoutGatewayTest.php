<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\PaymobCheckoutGateway;
use App\Modules\Subscriptions\Services\SubscriptionPaymentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymobCheckoutGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const HMAC_SECRET = 'test-hmac-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionSeeder::class);

        config([
            'services.paymob.mode' => 'live',
            'services.paymob.public_key' => 'egy_pk_test_public',
            'services.paymob.secret_key' => 'egy_sk_test_secret',
            'services.paymob.api_key' => 'legacy-api-key',
            'services.paymob.integration_id' => '5818094',
            'services.paymob.moto_integration_id' => '5818095',
            'services.paymob.iframe_id' => '1066410',
            'services.paymob.hmac_secret' => self::HMAC_SECRET,
            'services.paymob.currency' => 'EGP',
        ]);
    }

    public function test_live_mode_resolves_the_real_gateway(): void
    {
        $this->assertInstanceOf(PaymobCheckoutGateway::class, app(PaymobGateway::class));

        config(['services.paymob.mode' => 'mock']);
        $this->assertNotInstanceOf(PaymobCheckoutGateway::class, app(PaymobGateway::class));
    }

    public function test_checkout_uses_the_unified_intention_api(): void
    {
        Http::fake([
            'accept.paymob.com/v1/intention/' => Http::response([
                'id' => 991,
                'client_secret' => 'egy_csk_test_abc123',
                'intention_order_id' => 778899,
            ]),
        ]);

        $payment = $this->pendingPayment();
        $payment = app(SubscriptionPaymentService::class)->openCheckout($payment);

        $this->assertStringContainsString('https://accept.paymob.com/unifiedcheckout/', $payment->checkout_url);
        $this->assertStringContainsString('publicKey=egy_pk_test_public', $payment->checkout_url);
        $this->assertStringContainsString('clientSecret=egy_csk_test_abc123', $payment->checkout_url);
        $this->assertSame('778899', $payment->provider_order_id);
        // The client secret belongs in the URL only, never in stored metadata.
        $this->assertArrayNotHasKey('client_secret', $payment->metadata['gateway']);

        Http::assertSent(function (Request $request) use ($payment) {
            $body = $request->data();

            return $request->url() === 'https://accept.paymob.com/v1/intention/'
                && $request->header('Authorization')[0] === 'Token egy_sk_test_secret'
                && $body['amount'] === 4900
                && $body['currency'] === 'EGP'
                && $body['special_reference'] === $payment->reference
                && $body['payment_methods'] === [5818094]
                && str_contains($body['notification_url'], '/api/payments/paymob/callback')
                && $body['redirection_url'] === route('payments.paymob.redirect');
        });
    }

    public function test_a_gateway_outage_keeps_the_payment_retryable_instead_of_losing_it(): void
    {
        Http::fake(['accept.paymob.com/*' => Http::response(['detail' => 'service unavailable'], 503)]);

        $payment = app(SubscriptionPaymentService::class)->openCheckout($this->pendingPayment());

        $this->assertNull($payment->checkout_url);
        $this->assertSame('pending', $payment->status);
        $this->assertStringContainsString('503', (string) $payment->failure_reason);
    }

    public function test_transaction_callback_signature_is_verified_against_paymobs_field_order(): void
    {
        $payment = $this->pendingPayment();
        $object = $this->transactionObject($payment->reference);

        $expected = hash_hmac('sha512', implode('', [
            '4900', '2026-08-04T12:00:00.000000', 'EGP', 'false', 'false', '123456', '5818094',
            'true', 'false', 'false', 'false', 'true', 'false', '987654', '42', 'false',
            '2346', 'MasterCard', 'card', 'true',
        ]), self::HMAC_SECRET);

        $gateway = app(PaymobGateway::class);

        $this->assertTrue($gateway->verifyCallback(['type' => 'TRANSACTION', 'obj' => $object], $expected));
        $this->assertFalse($gateway->verifyCallback(['type' => 'TRANSACTION', 'obj' => $object], 'not-the-signature'));

        $tampered = $object;
        $tampered['amount_cents'] = 100;
        $this->assertFalse($gateway->verifyCallback(['type' => 'TRANSACTION', 'obj' => $tampered], $expected));
    }

    public function test_signed_webhook_activates_the_company_and_an_unsigned_one_is_rejected(): void
    {
        $payment = $this->pendingPayment();
        $object = $this->transactionObject($payment->reference);
        $payload = ['type' => 'TRANSACTION', 'obj' => $object];
        $signature = $this->signature($object);

        $this->postJson('/api/payments/paymob/callback', $payload)->assertForbidden();

        $this->postJson('/api/payments/paymob/callback?hmac='.$signature, $payload)
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded');

        $payment->refresh();
        $this->assertSame('123456', $payment->provider_transaction_id);
        $this->assertTrue($payment->company->refresh()->is_active);
        $this->assertSame(1, $payment->company->subscriptions()->where('status', 'active')->count());

        // Replaying the same callback must not create a second subscription.
        $this->postJson('/api/payments/paymob/callback?hmac='.$signature, $payload)->assertOk();
        $this->assertSame(1, $payment->company->subscriptions()->where('status', 'active')->count());
    }

    public function test_a_failed_transaction_callback_leaves_the_company_suspended(): void
    {
        $payment = $this->pendingPayment();
        $object = $this->transactionObject($payment->reference, ['success' => false]);

        $this->postJson('/api/payments/paymob/callback?hmac='.$this->signature($object), ['type' => 'TRANSACTION', 'obj' => $object])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'failed');

        $this->assertFalse($payment->company->refresh()->is_active);
    }

    public function test_token_callback_stores_the_card_for_automatic_renewals(): void
    {
        $payment = $this->pendingPayment();
        $subscription = CompanySubscription::create([
            'company_id' => $payment->company_id,
            'subscription_plan_id' => $payment->subscription_plan_id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'auto_renew' => true,
            'starts_at' => now(),
            'current_period_started_at' => now(),
            'current_period_ends_at' => now()->addMonthNoOverflow(),
            'provider' => 'paymob',
        ]);
        $payment->forceFill(['company_subscription_id' => $subscription->id])->save();

        $object = [
            'card_subtype' => 'Visa',
            'created_at' => '2026-08-04T12:00:00.000000',
            'email' => 'billing@example.com',
            'id' => 5544,
            'masked_pan' => 'xxxx-xxxx-xxxx-2346',
            'merchant_id' => 1209748,
            'order_id' => 987654,
            'token' => 'card-token-abc',
            'merchant_order_id' => $payment->reference,
        ];
        $signature = hash_hmac('sha512', implode('', [
            'Visa', '2026-08-04T12:00:00.000000', 'billing@example.com', '5544',
            'xxxx-xxxx-xxxx-2346', '1209748', '987654', 'card-token-abc',
        ]), self::HMAC_SECRET);

        $this->postJson('/api/payments/paymob/callback?hmac='.$signature, ['type' => 'TOKEN', 'obj' => $object])
            ->assertOk();

        $subscription->refresh();
        $this->assertTrue($subscription->hasSavedCard());
        $this->assertSame('card-token-abc', $subscription->payment_method_token);
        $this->assertSame('Visa', $subscription->payment_method_brand);
        $this->assertSame('2346', $subscription->payment_method_last4);
    }

    public function test_refund_callback_ends_the_subscription(): void
    {
        $payment = $this->pendingPayment();
        $object = $this->transactionObject($payment->reference);
        $this->postJson('/api/payments/paymob/callback?hmac='.$this->signature($object), ['type' => 'TRANSACTION', 'obj' => $object])->assertOk();

        $refund = $this->transactionObject($payment->reference, ['is_refunded' => true, 'id' => 123457]);
        $this->postJson('/api/payments/paymob/callback?hmac='.$this->signature($refund), ['type' => 'TRANSACTION', 'obj' => $refund])->assertOk();

        $this->assertSame('refunded', $payment->refresh()->status);
        $this->assertSame(0, $payment->company->subscriptions()->whereIn('status', ['active', 'trialing'])->count());
        $this->assertFalse($payment->company->refresh()->is_active);
    }

    public function test_saved_card_renewal_charges_through_the_moto_integration(): void
    {
        Http::fake([
            'accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'auth-token']),
            'accept.paymob.com/api/ecommerce/orders' => Http::response(['id' => 5001]),
            'accept.paymob.com/api/acceptance/payment_keys' => Http::response(['token' => 'payment-key']),
            'accept.paymob.com/api/acceptance/payments/pay' => Http::response([
                'id' => 77001,
                'success' => true,
                'pending' => false,
                'order' => ['id' => 5001],
            ]),
        ]);

        $payment = $this->pendingPayment();
        $result = app(PaymobGateway::class)->chargeSavedCard($payment, 'card-token-abc');

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('77001', $result['provider_transaction_id']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://accept.paymob.com/api/acceptance/payment_keys'
            && $request->data()['integration_id'] === 5818095);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://accept.paymob.com/api/acceptance/payments/pay'
            && $request->data()['source'] === ['identifier' => 'card-token-abc', 'subtype' => 'TOKEN']);
    }

    public function test_a_declined_saved_card_charge_is_reported_as_failed(): void
    {
        Http::fake([
            'accept.paymob.com/api/auth/tokens' => Http::response(['token' => 'auth-token']),
            'accept.paymob.com/api/ecommerce/orders' => Http::response(['id' => 5002]),
            'accept.paymob.com/api/acceptance/payment_keys' => Http::response(['token' => 'payment-key']),
            'accept.paymob.com/api/acceptance/payments/pay' => Http::response([
                'id' => 77002,
                'success' => false,
                'pending' => false,
                'data' => ['message' => 'Insufficient Funds'],
            ]),
        ]);

        $result = app(PaymobGateway::class)->chargeSavedCard($this->pendingPayment(), 'card-token-abc');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Insufficient Funds', $result['message']);
    }

    public function test_the_browser_redirect_sends_the_customer_back_to_the_app(): void
    {
        config(['subscriptions.checkout.app_url' => 'https://app.example.test']);

        $payment = $this->pendingPayment();
        // Redirect callbacks arrive flattened: `order` is the order id, nested
        // fields keep dotted names, and every value is a string.
        $query = [
            'amount_cents' => '4900',
            'created_at' => '2026-08-04T12:00:00.000000',
            'currency' => 'EGP',
            'error_occured' => 'false',
            'has_parent_transaction' => 'false',
            'id' => '123456',
            'integration_id' => '5818094',
            'is_3d_secure' => 'true',
            'is_auth' => 'false',
            'is_capture' => 'false',
            'is_refunded' => 'false',
            'is_standalone_payment' => 'true',
            'is_voided' => 'false',
            'order' => '987654',
            'owner' => '42',
            'pending' => 'false',
            'source_data.pan' => '2346',
            'source_data.sub_type' => 'MasterCard',
            'source_data.type' => 'card',
            'success' => 'true',
            'merchant_order_id' => $payment->reference,
        ];
        $signature = hash_hmac('sha512', implode('', [
            '4900', '2026-08-04T12:00:00.000000', 'EGP', 'false', 'false', '123456', '5818094',
            'true', 'false', 'false', 'false', 'true', 'false', '987654', '42', 'false',
            '2346', 'MasterCard', 'card', 'true',
        ]), self::HMAC_SECRET);

        $this->get('/api/payments/paymob/callback?'.http_build_query($query + ['hmac' => $signature]))
            ->assertRedirect("https://app.example.test/checkout/{$payment->reference}?status=succeeded");

        $this->assertSame('succeeded', $payment->refresh()->status);
    }

    private function signature(array $object): string
    {
        return hash_hmac('sha512', implode('', [
            (string) $object['amount_cents'],
            $object['created_at'],
            $object['currency'],
            $object['error_occured'] ? 'true' : 'false',
            $object['has_parent_transaction'] ? 'true' : 'false',
            (string) $object['id'],
            (string) $object['integration_id'],
            $object['is_3d_secure'] ? 'true' : 'false',
            $object['is_auth'] ? 'true' : 'false',
            $object['is_capture'] ? 'true' : 'false',
            $object['is_refunded'] ? 'true' : 'false',
            $object['is_standalone_payment'] ? 'true' : 'false',
            $object['is_voided'] ? 'true' : 'false',
            (string) $object['order']['id'],
            (string) $object['owner'],
            $object['pending'] ? 'true' : 'false',
            $object['source_data']['pan'],
            $object['source_data']['sub_type'],
            $object['source_data']['type'],
            $object['success'] ? 'true' : 'false',
        ]), self::HMAC_SECRET);
    }

    private function transactionObject(string $reference, array $overrides = []): array
    {
        return array_replace([
            'amount_cents' => 4900,
            'created_at' => '2026-08-04T12:00:00.000000',
            'currency' => 'EGP',
            'error_occured' => false,
            'has_parent_transaction' => false,
            'id' => 123456,
            'integration_id' => 5818094,
            'is_3d_secure' => true,
            'is_auth' => false,
            'is_capture' => false,
            'is_refunded' => false,
            'is_standalone_payment' => true,
            'is_voided' => false,
            'order' => ['id' => 987654, 'merchant_order_id' => $reference],
            'owner' => 42,
            'pending' => false,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
            'success' => true,
        ], $overrides);
    }

    private function pendingPayment(): SubscriptionPayment
    {
        $company = Company::factory()->create(['plan' => 'basic', 'is_active' => false]);
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $admin->assignRole(UserRole::CompanyAdmin->value);
        $plan = SubscriptionPlan::where('slug', 'basic')->firstOrFail();

        return SubscriptionPayment::create([
            'reference' => (string) Str::uuid(),
            'company_id' => $company->id,
            'user_id' => $admin->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'purpose' => 'registration',
            'amount' => 49,
            'currency' => 'EGP',
            'provider' => 'paymob',
            'status' => 'pending',
        ]);
    }
}
