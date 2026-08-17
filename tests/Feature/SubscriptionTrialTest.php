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

class SubscriptionTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionSeeder::class);
        SubscriptionPlan::where('slug', 'basic')->update(['trial_enabled' => true, 'trial_days' => 14]);
    }

    public function test_registering_on_a_trial_plan_activates_the_workspace_without_payment(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'company_name' => 'Trial Co',
            'name' => 'Trial Admin',
            'email' => 'trial@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertCreated()
            ->assertJsonPath('data.activation_mode', 'trial')
            ->assertJsonPath('data.trial.days', 14)
            ->assertJsonPath('data.subscription.status', 'trialing');

        // The admin is signed in immediately, no checkout in the way.
        $this->assertNotNull($response->json('data.auth.token'));
        $this->assertNull($response->json('data.checkout.checkout_url'));

        $company = Company::where('name', 'Trial Co')->firstOrFail();
        $this->assertTrue($company->is_active);

        $subscription = $company->subscriptions()->firstOrFail();
        $this->assertSame('trialing', $subscription->status);
        $this->assertEqualsWithDelta(14, now()->diffInDays($subscription->trial_ends_at), 1);
        // The trial period is the billing period, so renewal picks it up on time.
        $this->assertSame(
            $subscription->trial_ends_at->toDateTimeString(),
            $subscription->current_period_ends_at->toDateTimeString()
        );
        $this->assertSame(0.0, (float) SubscriptionPayment::where('company_id', $company->id)->value('amount'));
    }

    public function test_a_company_can_start_a_trial_from_inside_the_app(): void
    {
        [$admin, $company] = $this->companyWithoutSubscription();
        Sanctum::actingAs($admin);

        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'basic', 'billing_cycle' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.plan.slug', 'basic')
            ->assertJsonPath('data.is_on_trial', true);

        $this->assertSame(0, SubscriptionPayment::where('company_id', $company->id)->count());
    }

    public function test_a_trial_is_only_offered_once_per_company(): void
    {
        [$admin, $company] = $this->companyWithoutSubscription();
        Sanctum::actingAs($admin);

        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'basic', 'billing_cycle' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'trialing');

        // A second attempt is billed like any other paid plan change.
        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'basic', 'billing_cycle' => 'monthly'])
            ->assertStatus(202)
            ->assertJsonPath('data.requires_payment', true);

        $this->postJson('/api/subscriptions/checkout', [
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
            'start_trial' => true,
        ])->assertCreated()->assertJsonPath('data.requires_payment', true);

        $this->assertTrue($company->refresh()->subscriptions()->whereNotNull('trial_ends_at')->exists());
    }

    public function test_an_unconverted_trial_goes_past_due_then_expires(): void
    {
        config(['subscriptions.grace_days' => 2]);
        [$admin, $company] = $this->companyWithoutSubscription();
        Sanctum::actingAs($admin);

        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'basic', 'billing_cycle' => 'monthly'])->assertCreated();
        $subscription = $company->subscriptions()->where('status', 'trialing')->firstOrFail();

        $this->travelTo($subscription->trial_ends_at->copy()->addMinute());
        $this->artisan('subscriptions:process-renewals');

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertTrue($company->refresh()->is_active);

        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();
        $this->assertNotNull($payment->checkout_url);
        $this->assertEquals(49.0, (float) $payment->amount);

        $this->travelTo(now()->addDays(3));
        $this->artisan('subscriptions:process-renewals');

        $this->assertSame('expired', $subscription->refresh()->status);
        $this->assertFalse($company->refresh()->is_active);
    }

    public function test_paying_a_trial_conversion_starts_the_first_paid_period(): void
    {
        [$admin, $company] = $this->companyWithoutSubscription();
        Sanctum::actingAs($admin);

        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'basic', 'billing_cycle' => 'monthly'])->assertCreated();
        $subscription = $company->subscriptions()->where('status', 'trialing')->firstOrFail();

        $this->travelTo($subscription->trial_ends_at->copy()->addMinute());
        $this->artisan('subscriptions:process-renewals');

        $payment = SubscriptionPayment::where('company_subscription_id', $subscription->id)->latest('id')->firstOrFail();
        // Paymob calls back unauthenticated; drop the acting-as user.
        $this->app['auth']->forgetGuards();

        $payload = ['merchant_order_id' => $payment->reference, 'success' => true, 'id' => 'txn-trial', 'order' => ['id' => 'order-trial']];
        $this->postJson('/api/payments/paymob/callback', $payload, [
            'X-Paymob-Signature' => hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('services.paymob.hmac_secret')),
        ])->assertOk();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->current_period_ends_at->isFuture());
        $this->assertFalse($subscription->is_on_trial);
    }

    public function test_a_paid_registration_can_be_claimed_for_a_session_after_the_webhook(): void
    {
        SubscriptionPlan::where('slug', 'basic')->update(['trial_enabled' => false, 'trial_days' => 0]);

        $register = $this->postJson('/api/auth/register', [
            'company_name' => 'Paid Co',
            'name' => 'Paid Admin',
            'email' => 'paid@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'device_name' => 'phpunit',
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertCreated();

        $reference = $register->json('data.checkout.reference');
        $token = $register->json('data.checkout.registration_token');
        $this->assertSame('mock', $register->json('data.checkout.mode'));

        // Nothing to claim until the payment actually lands.
        $this->postJson("/api/payments/{$reference}/session", ['registration_token' => $token])->assertStatus(409);

        $payload = ['merchant_order_id' => $reference, 'success' => true, 'id' => 'txn-77', 'order' => ['id' => 'order-77']];
        $this->postJson('/api/payments/paymob/callback', $payload, [
            'X-Paymob-Signature' => hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('services.paymob.hmac_secret')),
        ])->assertOk();

        $auth = $this->postJson("/api/payments/{$reference}/session", [
            'registration_token' => $token,
            'device_name' => 'phpunit',
        ])->assertOk()->json('data.auth');

        $this->assertNotNull($auth['token']);
        $this->withToken($auth['token'])->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/payments/{$reference}/session", ['registration_token' => 'wrong-token'])
            ->assertUnprocessable();
    }

    public function test_an_organization_that_already_subscribed_is_never_offered_a_trial(): void
    {
        [$admin] = $this->companyOnPaidPlan();
        Sanctum::actingAs($admin);

        // The paid plan was bought outright, so no trial was ever consumed —
        // and there is still none left to take.
        $this->getJson('/api/subscriptions/current')
            ->assertOk()
            ->assertJsonPath('data.trial_used', false)
            ->assertJsonPath('data.trial_available', false);

        // Switching to a plan that advertises a trial is a paid plan change.
        $this->postJson('/api/subscriptions/subscribe', ['plan_slug' => 'basic', 'billing_cycle' => 'monthly'])
            ->assertStatus(202)
            ->assertJsonPath('data.requires_payment', true);

        $this->postJson('/api/subscriptions/checkout', [
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
            'start_trial' => true,
        ])->assertCreated()->assertJsonPath('data.requires_payment', true);

        $this->assertFalse(CompanySubscription::query()->whereNotNull('trial_ends_at')->exists());
    }

    /** @return array{0: User, 1: Company} */
    private function companyWithoutSubscription(): array
    {
        $company = Company::factory()->create(['plan' => 'basic', 'is_active' => true]);
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $admin->assignRole(UserRole::CompanyAdmin->value);

        return [$admin, $company];
    }

    /** @return array{0: User, 1: Company} */
    private function companyOnPaidPlan(): array
    {
        $company = Company::factory()->create(['plan' => 'professional', 'is_active' => true]);
        $admin = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $admin->assignRole(UserRole::CompanyAdmin->value);
        $plan = SubscriptionPlan::where('slug', 'professional')->firstOrFail();

        CompanySubscription::create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
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
}
