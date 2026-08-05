<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_plan_and_assign_features(): void
    {
        $this->seed(DatabaseSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@manzomatech.com')->firstOrFail());

        $feature = $this->postJson('/api/subscriptions/features', [
            'slug' => 'automation.workflows',
            'name' => 'Workflow Automation',
            'module' => 'platform',
            'description' => 'Automated ERP workflows.',
            'is_metered' => false,
        ])->assertCreated()->json('data');

        $plan = $this->postJson('/api/subscriptions/plans', [
            'slug' => 'growth',
            'name' => 'Growth',
            'description' => 'Growing teams.',
            'monthly_price' => 99,
            'annual_price' => 990,
            'currency' => 'USD',
            'max_users' => 50,
            'storage_gb' => 50,
            'api_rate_limit_per_minute' => 100,
            'is_active' => true,
            'sort_order' => 2,
        ])->assertCreated()->json('data');

        $this->putJson("/api/subscriptions/plans/{$plan['id']}/features", [
            'features' => [[
                'feature_id' => $feature['id'],
                'enabled' => true,
                'value' => '100',
            ]],
        ])->assertOk()
            ->assertJsonPath('data.features.0.slug', 'automation.workflows')
            ->assertJsonPath('data.features.0.pivot.value', '100');
    }

    public function test_super_admin_can_add_update_and_remove_one_plan_feature_without_affecting_others(): void
    {
        $this->seed(DatabaseSeeder::class);
        Sanctum::actingAs(User::where('email', 'admin@manzomatech.com')->firstOrFail());

        $plan = SubscriptionPlan::where('slug', 'basic')->firstOrFail();
        $keptFeature = SubscriptionFeature::where('slug', 'core.hr')->firstOrFail();
        $managedFeature = SubscriptionFeature::where('slug', 'core.projects')->firstOrFail();

        $this->putJson("/api/subscriptions/plans/{$plan->id}/features/{$managedFeature->id}", [
            'enabled' => true,
            'value' => '25',
        ])->assertOk()
            ->assertJsonFragment(['slug' => 'core.projects'])
            ->assertJsonFragment(['value' => '25']);

        $this->assertDatabaseHas('plan_feature', [
            'subscription_plan_id' => $plan->id,
            'subscription_feature_id' => $managedFeature->id,
            'enabled' => true,
            'value' => '25',
        ]);

        $this->putJson("/api/subscriptions/plans/{$plan->id}/features/{$managedFeature->id}", [
            'enabled' => false,
            'value' => '10',
        ])->assertOk();

        $this->assertDatabaseHas('plan_feature', [
            'subscription_plan_id' => $plan->id,
            'subscription_feature_id' => $managedFeature->id,
            'enabled' => false,
            'value' => '10',
        ]);

        $this->deleteJson("/api/subscriptions/plans/{$plan->id}/features/{$managedFeature->id}")
            ->assertOk()
            ->assertJsonMissing(['slug' => 'core.projects']);

        $this->assertDatabaseMissing('plan_feature', [
            'subscription_plan_id' => $plan->id,
            'subscription_feature_id' => $managedFeature->id,
        ]);
        $this->assertDatabaseHas('plan_feature', [
            'subscription_plan_id' => $plan->id,
            'subscription_feature_id' => $keptFeature->id,
        ]);
    }

    public function test_company_admin_changing_to_a_paid_plan_must_pay_for_it_first(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $checkout = $this->postJson('/api/subscriptions/subscribe', [
            'plan_slug' => 'enterprise',
            'billing_cycle' => 'annual',
        ])->assertStatus(202)
            ->assertJsonPath('data.requires_payment', true)
            ->assertJsonPath('data.payment.purpose', 'upgrade')
            ->json('data');

        // Nothing changes until Paymob confirms the payment.
        $this->assertDatabaseMissing('companies', ['id' => $companyAdmin->company_id, 'plan' => 'enterprise']);

        $payload = ['merchant_order_id' => $checkout['checkout']['reference'], 'success' => true, 'id' => 'txn-42', 'order' => ['id' => 'order-42']];
        $this->postJson('/api/payments/paymob/callback', $payload, [
            'X-Paymob-Signature' => hash_hmac('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES), (string) config('services.paymob.hmac_secret')),
        ])->assertOk()->assertJsonPath('data.payment.status', 'succeeded');

        $this->assertDatabaseHas('companies', ['id' => $companyAdmin->company_id, 'plan' => 'enterprise']);
        $this->assertSame(1, $companyAdmin->company->subscriptions()->where('status', 'active')->count());
        $this->assertSame('enterprise', $companyAdmin->company->subscriptions()->where('status', 'active')->firstOrFail()->plan->slug);
    }

    public function test_company_admin_can_switch_to_a_free_plan_without_payment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);
        SubscriptionPlan::where('slug', 'basic')->update(['monthly_price' => 0, 'annual_price' => 0]);

        $this->postJson('/api/subscriptions/subscribe', [
            'plan_slug' => 'basic',
            'billing_cycle' => 'annual',
        ])->assertCreated()
            ->assertJsonPath('data.plan.slug', 'basic')
            ->assertJsonPath('data.billing_cycle', 'annual');

        $this->assertDatabaseHas('companies', ['id' => $companyAdmin->company_id, 'plan' => 'basic']);
        $this->assertSame(1, $companyAdmin->company->subscriptions()->where('status', 'active')->count());
    }

    public function test_only_super_admin_can_manage_catalog_and_only_company_admin_can_subscribe(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/subscriptions/features', [
            'slug' => 'forbidden',
            'name' => 'Forbidden',
            'module' => 'platform',
            'is_metered' => false,
        ])->assertForbidden();

        $plan = SubscriptionPlan::where('slug', 'basic')->firstOrFail();
        $feature = SubscriptionFeature::where('slug', 'core.projects')->firstOrFail();

        $this->deleteJson("/api/subscriptions/plans/{$plan->id}/features/{$feature->id}")
            ->assertForbidden();

        $employee = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $employee->assignRole(UserRole::Employee->value);
        Sanctum::actingAs($employee);

        $this->postJson('/api/subscriptions/subscribe', [
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }

    public function test_super_admin_can_track_company_subscriptions_and_payment_transactions(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superAdmin = User::where('email', 'admin@manzomatech.com')->firstOrFail();
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $subscription = $companyAdmin->company->latestSubscription()->firstOrFail();
        $plan = SubscriptionPlan::where('slug', 'professional')->firstOrFail();
        SubscriptionPayment::create([
            'reference' => (string) Str::uuid(),
            'company_id' => $companyAdmin->company_id,
            'company_subscription_id' => $subscription->id,
            'user_id' => $companyAdmin->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'purpose' => 'renewal',
            'amount' => 149,
            'currency' => 'EGP',
            'provider' => 'paymob',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/subscriptions/admin/billing')
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Demo Company',
                'status' => 'active',
                'purpose' => 'renewal',
                'amount' => '149.00',
            ]);
    }

    public function test_super_admin_can_renew_a_company_through_a_date_without_payment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superAdmin = User::where('email', 'admin@manzomatech.com')->firstOrFail();
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $company = $companyAdmin->company;
        $subscription = $company->latestSubscription()->firstOrFail();
        $subscription->forceFill([
            'status' => 'expired',
            'current_period_started_at' => now()->subMonth(),
            'current_period_ends_at' => now()->subDay(),
            'ends_at' => now()->subDay(),
        ])->save();
        $company->forceFill(['is_active' => false])->save();
        $paymentsBefore = SubscriptionPayment::count();
        $through = now()->addMonths(3)->toDateString();

        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/subscriptions/admin/companies/{$company->id}/renew-without-payment", [
            'through_date' => $through,
            'reason' => 'Courtesy extension approved by support',
        ])->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.cancel_at_period_end', true);

        $subscription->refresh();
        $this->assertSame($through, $subscription->current_period_ends_at->toDateString());
        $this->assertTrue($company->refresh()->is_active);
        $this->assertSame($paymentsBefore, SubscriptionPayment::count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'subscription.admin_renewed_without_payment',
            'auditable_id' => (string) $subscription->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_company_admin_cannot_view_or_grant_platform_subscription_overrides(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/subscriptions/admin/billing')->assertForbidden();
        $this->postJson("/api/subscriptions/admin/companies/{$companyAdmin->company_id}/renew-without-payment", [
            'through_date' => now()->addMonth()->toDateString(),
        ])->assertForbidden();
    }
}
