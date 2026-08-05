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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionRenewalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionSeeder::class);
    }

    public function test_saved_card_renewal_extends_the_billing_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_ends_at' => now()->subMinute(),
            'payment_method_token' => 'tok_visa_4242',
            'payment_method_brand' => 'Visa',
            'payment_method_last4' => '4242',
        ]);
        $previousEnd = $subscription->current_period_ends_at->copy();

        $this->artisan('subscriptions:process-renewals')->assertExitCode(0);

        $subscription->refresh();
        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();

        $this->assertSame('active', $subscription->status);
        $this->assertSame('renewal', $payment->purpose);
        $this->assertSame('succeeded', $payment->status);
        $this->assertEquals(49.0, (float) $payment->amount);
        $this->assertTrue($subscription->current_period_ends_at->greaterThan($previousEnd));
        $this->assertEquals(
            $previousEnd->copy()->addMonthNoOverflow()->toDateString(),
            $subscription->current_period_ends_at->toDateString()
        );
        $this->assertSame(0, $subscription->renewal_failures);
    }

    public function test_a_saved_card_is_not_charged_before_the_renewal_date(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_ends_at' => now()->addDays(2),
            'payment_method_token' => 'tok_visa_4242',
        ]);

        $this->artisan('subscriptions:process-renewals');

        $this->assertSame(0, SubscriptionPayment::where('company_subscription_id', $subscription->id)->count());
        $this->assertSame('active', $subscription->refresh()->status);
    }

    public function test_renewal_is_not_charged_twice_for_the_same_period(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_ends_at' => now()->subMinute(),
            'payment_method_token' => 'tok_visa_4242',
        ]);

        $this->artisan('subscriptions:process-renewals');
        $this->artisan('subscriptions:process-renewals');

        $this->assertSame(1, SubscriptionPayment::where('company_subscription_id', $subscription->id)
            ->where('purpose', 'renewal')
            ->where('status', 'succeeded')
            ->count());
    }

    public function test_declined_card_moves_the_subscription_to_past_due_and_expires_after_grace(): void
    {
        config(['subscriptions.grace_days' => 3, 'subscriptions.retry.max_attempts' => 2]);

        $subscription = $this->makeSubscription([
            'current_period_ends_at' => now()->subMinutes(5),
            'payment_method_token' => 'decline-me',
        ]);

        $this->artisan('subscriptions:process-renewals');

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertSame(1, $subscription->renewal_failures);
        // Access continues while the grace window is open.
        $this->assertTrue($subscription->company->refresh()->is_active);

        $this->travelTo(now()->addDays(4));
        $this->artisan('subscriptions:process-renewals');

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertNotNull($subscription->ends_at);
        $this->assertFalse($subscription->company->refresh()->is_active);
    }

    public function test_declined_charges_are_retried_on_a_schedule_then_handed_over_to_the_customer(): void
    {
        config([
            'subscriptions.grace_days' => 10,
            'subscriptions.retry.max_attempts' => 2,
            'subscriptions.retry.interval_hours' => 24,
        ]);

        $subscription = $this->makeSubscription([
            'current_period_ends_at' => now()->subMinute(),
            'payment_method_token' => 'decline-me',
        ]);

        $this->artisan('subscriptions:process-renewals');
        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();
        $this->assertSame(1, $payment->attempts);
        $this->assertNull($payment->checkout_url);

        // Too soon for the next attempt.
        $this->artisan('subscriptions:process-renewals');
        $this->assertSame(1, $payment->refresh()->attempts);

        $this->travelTo(now()->addHours(25));
        $this->artisan('subscriptions:process-renewals');

        $payment->refresh();
        $this->assertSame(2, $payment->attempts);
        // Retries are exhausted, so the customer gets a link to pay by hand.
        $this->assertNotNull($payment->checkout_url);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(1, SubscriptionPayment::where('company_subscription_id', $subscription->id)->count());

        $this->travelTo(now()->addHours(25));
        $this->artisan('subscriptions:process-renewals');
        $this->assertSame(2, $payment->refresh()->attempts);
    }

    public function test_subscription_without_a_saved_card_gets_a_checkout_link_and_goes_past_due(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->subMinute()]);

        $this->artisan('subscriptions:process-renewals');

        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->checkout_url);
        $this->assertSame('past_due', $subscription->refresh()->status);
        $this->assertTrue($subscription->company->refresh()->is_active);
    }

    public function test_paying_the_renewal_checkout_restores_the_subscription(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->subMinute()]);
        $this->artisan('subscriptions:process-renewals');

        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();
        $payload = ['merchant_order_id' => $payment->reference, 'success' => true, 'id' => 'txn-1', 'order' => ['id' => 'order-1']];
        $signature = hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('services.paymob.hmac_secret'));

        $this->postJson('/api/payments/paymob/callback', $payload, ['X-Paymob-Signature' => $signature])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'succeeded');

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertTrue($subscription->current_period_ends_at->isFuture());
    }

    public function test_cancel_at_period_end_keeps_access_until_the_period_ends(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->addDays(2)]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/subscriptions/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.cancel_at_period_end', true);

        $subscription->refresh();
        $this->assertFalse($subscription->auto_renew);
        $this->assertTrue($subscription->company->refresh()->is_active);

        $this->travelTo(now()->addDays(3));
        $this->artisan('subscriptions:process-renewals');

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertFalse($subscription->company->refresh()->is_active);
        $this->assertSame(0, SubscriptionPayment::where('company_subscription_id', $subscription->id)->count());
    }

    public function test_immediate_cancellation_suspends_the_company_right_away(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->addDays(20)]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/subscriptions/cancel', ['immediately' => true, 'reason' => 'Closing the business'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $subscription->refresh();
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertSame('Closing the business', $subscription->cancellation_reason);
        $this->assertFalse($subscription->company->refresh()->is_active);
    }

    public function test_a_scheduled_cancellation_can_be_resumed(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->addDays(10)]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/subscriptions/cancel')->assertOk();
        $this->postJson('/api/subscriptions/resume')
            ->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', false)
            ->assertJsonPath('data.auto_renew', true);

        $this->assertNull($subscription->refresh()->cancelled_at);
    }

    public function test_auto_renew_can_be_switched_off_and_the_period_still_ends_with_a_payment_request(): void
    {
        $subscription = $this->makeSubscription([
            'current_period_ends_at' => now()->subMinute(),
            'payment_method_token' => 'tok_visa_4242',
        ]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/subscriptions/auto-renew', ['auto_renew' => false])
            ->assertOk()
            ->assertJsonPath('data.auto_renew', false);

        $this->artisan('subscriptions:process-renewals');

        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();
        // The card is not charged automatically; a checkout link is issued.
        $this->assertSame('pending', $payment->status);
        $this->assertNotNull($payment->checkout_url);
    }

    public function test_paid_plan_change_returns_a_checkout_session_instead_of_activating(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->addDays(10)]);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'professional', 'billing_cycle' => 'monthly'])
            ->assertStatus(202)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.payment.purpose', 'upgrade');

        // The plan only changes once the payment is confirmed.
        $this->assertSame('basic', $subscription->refresh()->plan->slug);
        $this->assertSame(149.0, (float) SubscriptionPayment::latest('id')->firstOrFail()->amount);
    }

    public function test_renewal_reminders_are_sent_once_per_milestone(): void
    {
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->addDays(3)->addHour()]);

        $this->artisan('subscriptions:send-reminders')->assertExitCode(0);
        $this->artisan('subscriptions:send-reminders');

        $subscription->refresh();
        $this->assertCount(1, $subscription->reminders_sent);
        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertStringContainsString('renews', (string) $this->admin->notifications()->first()->data['title']);
    }

    public function test_trial_ending_reminder_is_sent_before_the_trial_expires(): void
    {
        $subscription = $this->makeSubscription([
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(1)->addHour(),
            'current_period_ends_at' => now()->addDays(1)->addHour(),
        ]);

        $this->artisan('subscriptions:send-reminders');

        $this->assertCount(1, $subscription->refresh()->reminders_sent);
        $this->assertSame('subscription.trial.ending', $this->admin->notifications()->first()->data['event_type']);
    }

    public function test_a_company_suspended_for_non_payment_can_still_log_in_and_pay(): void
    {
        config(['subscriptions.grace_days' => 0]);
        $subscription = $this->makeSubscription(['current_period_ends_at' => now()->subMinute()]);
        $this->admin->forceFill(['password' => bcrypt('Secret#123')])->save();

        $this->artisan('subscriptions:process-renewals');
        $this->travelTo(now()->addMinutes(2));
        $this->artisan('subscriptions:process-renewals');

        $this->assertSame('expired', $subscription->refresh()->status);
        $company = $subscription->company->refresh();
        $this->assertFalse($company->is_active);
        $this->assertTrue($company->isBillingSuspended());

        $token = $this->postJson('/api/auth/login', [
            'email' => $this->admin->email,
            'password' => 'Secret#123',
            'device_name' => 'phpunit',
        ])->assertOk()->json('data.token');

        // Billing stays reachable, everything else stays suspended.
        $this->withToken($token)->getJson('/api/subscriptions/current')->assertOk();
        $this->withToken($token)->getJson('/api/finance/accounts')->assertForbidden();

        $checkout = $this->withToken($token)->postJson('/api/subscriptions/checkout', [
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertCreated()->json('data');

        $payload = ['merchant_order_id' => $checkout['checkout']['reference'], 'success' => true, 'id' => 'txn-9', 'order' => ['id' => 'order-9']];
        $this->postJson('/api/payments/paymob/callback', $payload, [
            'X-Paymob-Signature' => hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('services.paymob.hmac_secret')),
        ])->assertOk();

        $company->refresh();
        $this->assertTrue($company->is_active);
        $this->assertFalse($company->isBillingSuspended());

        // The test client keeps one resolved guard user across requests, so the
        // reinstated company is only visible after the guard is re-resolved.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/finance/accounts')->assertOk();
    }

    public function test_mock_checkout_is_rejected_when_paymob_is_live(): void
    {
        config(['services.paymob.mode' => 'live']);

        $register = $this->postJson('/api/auth/register', [
            'company_name' => 'Live Mode Co',
            'name' => 'Live Admin',
            'email' => 'live@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ]);

        config(['services.paymob.mode' => 'mock']);
        $reference = $register->json('data.checkout.reference');
        $token = $register->json('data.checkout.registration_token');
        config(['services.paymob.mode' => 'live']);

        $this->postJson("/api/payments/{$reference}/mock-result", [
            'registration_token' => $token,
            'status' => 'succeeded',
        ])->assertNotFound();

        $this->assertFalse(Company::where('name', 'Live Mode Co')->firstOrFail()->is_active);
    }

    private function makeSubscription(array $attributes = [], string $planSlug = 'basic'): CompanySubscription
    {
        $company = Company::factory()->create(['plan' => $planSlug, 'is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole(UserRole::CompanyAdmin->value);
        $plan = SubscriptionPlan::where('slug', $planSlug)->firstOrFail();

        $subscription = CompanySubscription::create(array_replace([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'auto_renew' => true,
            'cancel_at_period_end' => false,
            'starts_at' => now()->subMonthNoOverflow(),
            'current_period_started_at' => now()->subMonthNoOverflow(),
            'current_period_ends_at' => now()->addDays(5),
            'provider' => 'paymob',
        ], $attributes));

        return $subscription->load('plan', 'company');
    }
}
