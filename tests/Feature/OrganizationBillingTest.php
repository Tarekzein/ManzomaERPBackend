<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Enums\PaymentPurpose;
use App\Modules\Subscriptions\Exceptions\OrganizationQuotaExceededException;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Repositories\EloquentCompanySubscriptionRepository;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use App\Modules\Subscriptions\Services\SubscriptionLifecycleService;
use App\Modules\Subscriptions\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_subscription_serves_every_company_without_rekeying_legacy_company_ownership(): void
    {
        [$organization, $origin, $plan, $subscription] = $this->billingFixture();
        $sibling = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Sibling Company',
            'plan' => $plan->slug,
        ]);

        $repository = app(EloquentCompanySubscriptionRepository::class);

        $this->assertTrue($repository->current($origin)?->is($subscription));
        $this->assertTrue($repository->current($sibling)?->is($subscription));
        $this->assertSame($origin->id, $subscription->company_id);
        $this->assertSame($organization->id, $subscription->organization_id);
    }

    public function test_company_and_user_limits_are_organization_wide_and_pending_invitations_reserve_seats(): void
    {
        [$organization, , $plan] = $this->billingFixture(maxCompanies: 2, maxUsers: 2);
        $owner = User::factory()->create(['company_id' => null, 'is_active' => true]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        OrganizationInvitation::query()->create([
            'organization_id' => $organization->id,
            'email' => 'reserved@example.test',
            'token_hash' => hash('sha256', 'reserved-token'),
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);

        $quotas = app(OrganizationQuotaService::class);
        $usage = $quotas->usage($organization);

        $this->assertSame(1, $usage['companies']['used']);
        $this->assertSame(1, $usage['companies']['remaining']);
        $this->assertSame(1, $usage['users']['used']);
        $this->assertSame(1, $usage['users']['reserved']);
        $this->assertSame(0, $usage['users']['remaining']);

        $existingMemberOperationRan = false;
        $quotas->withinUserCapacity($organization, $owner, function () use (&$existingMemberOperationRan): void {
            $existingMemberOperationRan = true;
        });
        $this->assertTrue($existingMemberOperationRan, 'Assigning the same identity to another company must not consume a seat.');

        try {
            $quotas->withinUserCapacity($organization, null, fn () => null);
            $this->fail('A third unique organization user should have exceeded the limit.');
        } catch (OrganizationQuotaExceededException $exception) {
            $this->assertSame('USER_LIMIT_REACHED', $exception->errorCode);
            $this->assertSame(2, $exception->details['limit']);
        }

        $quotas->withinInvitationAcceptance($organization, null, fn () => null);
    }

    public function test_company_capacity_check_and_insert_share_the_same_transactional_lock(): void
    {
        [$organization, , $plan] = $this->billingFixture(maxCompanies: 2);
        $quotas = app(OrganizationQuotaService::class);

        $quotas->withinCompanyCapacity($organization, function (Organization $locked) use ($plan): void {
            Company::factory()->create([
                'organization_id' => $locked->id,
                'name' => 'Second Company',
                'plan' => $plan->slug,
            ]);
        });

        try {
            $quotas->withinCompanyCapacity($organization, fn () => null);
            $this->fail('A third active company should have exceeded the limit.');
        } catch (OrganizationQuotaExceededException $exception) {
            $this->assertSame('COMPANY_LIMIT_REACHED', $exception->errorCode);
            $this->assertSame(2, $exception->details['used']);
        }
    }

    public function test_raising_plan_limits_reaches_organizations_already_serving_on_that_plan(): void
    {
        [$organization, , $plan, $subscription] = $this->billingFixture(maxCompanies: 1, maxUsers: 5);
        $quotas = app(OrganizationQuotaService::class);

        $this->assertSame(1, $quotas->usage($organization)['companies']['limit']);

        $plan->forceFill(['max_companies' => 5, 'max_users' => 20])->save();

        // The purchase-time snapshot still records the old entitlement; the
        // live plan is what an organization is actually held to.
        $this->assertSame(1, $subscription->fresh()->entitlements_snapshot['max_companies']);

        $usage = $quotas->usage($organization->fresh());
        $this->assertSame(5, $usage['companies']['limit']);
        $this->assertSame(20, $usage['users']['limit']);

        $quotas->withinCompanyCapacity($organization, function (Organization $locked) use ($plan): void {
            Company::factory()->create([
                'organization_id' => $locked->id,
                'name' => 'Second Company',
                'plan' => $plan->slug,
            ]);
        });

        $this->assertSame(2, $quotas->usage($organization->fresh())['companies']['used']);
    }

    public function test_billing_suspension_is_organization_scoped_and_never_reactivates_admin_suspended_companies(): void
    {
        [$organization, $company, , $subscription] = $this->billingFixture();
        $company->forceFill(['is_active' => false])->save();

        $lifecycle = app(SubscriptionLifecycleService::class);
        $lifecycle->expire($subscription, 'test_expiry');

        $this->assertNotNull($organization->refresh()->billing_suspended_at);
        $this->assertFalse($company->refresh()->is_active);

        $subscription->forceFill(['status' => 'active'])->save();
        $lifecycle->restoreAccess($subscription->refresh());

        $this->assertNull($organization->refresh()->billing_suspended_at);
        $this->assertFalse($company->refresh()->is_active, 'Billing recovery must not undo an administrative company suspension.');
    }

    public function test_successful_payment_restores_billing_access_and_is_idempotent(): void
    {
        [$organization, $company, $plan, $subscription] = $this->billingFixture();
        $payer = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        app(SubscriptionLifecycleService::class)->expire($subscription, 'unpaid');
        $this->assertNotNull($organization->refresh()->billing_suspended_at);

        $payment = SubscriptionPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'initiated_from_company_id' => $company->id,
            'company_subscription_id' => $subscription->id,
            'user_id' => $payer->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'purpose' => PaymentPurpose::Upgrade->value,
            'amount' => 100,
            'currency' => 'EGP',
            'provider' => 'paymob',
            'status' => SubscriptionPayment::STATUS_PENDING,
        ]);

        $payments = app(SubscriptionPaymentService::class);
        $first = $payments->markSucceeded($payment, ['provider_transaction_id' => 'txn-restored']);
        $second = $payments->markSucceeded($payment->refresh(), ['provider_transaction_id' => 'txn-restored']);

        $this->assertSame(SubscriptionPayment::STATUS_SUCCEEDED, $first['payment']->status);
        $this->assertSame(SubscriptionPayment::STATUS_SUCCEEDED, $second['payment']->status);
        $this->assertNull($organization->refresh()->billing_suspended_at);
        $this->assertSame(1, CompanySubscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->count());
    }

    public function test_captured_payment_is_preserved_for_manual_review_when_activation_fails(): void
    {
        [$organization, $company, , $subscription] = $this->billingFixture(maxUsers: 3);
        $payer = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        foreach ([$payer, User::factory()->create(['company_id' => $company->id, 'is_active' => true])] as $member) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'role' => OrganizationMembership::ROLE_MEMBER,
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
        }
        $smallerPlan = SubscriptionPlan::query()->create([
            'slug' => 'smaller-'.Str::lower(Str::random(8)),
            'name' => 'Smaller Plan',
            'monthly_price' => 50,
            'annual_price' => 500,
            'currency' => 'EGP',
            'max_companies' => 3,
            'max_users' => 1,
            'storage_gb' => 10,
            'api_rate_limit_per_minute' => 60,
            'trial_enabled' => false,
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $payment = SubscriptionPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'initiated_from_company_id' => $company->id,
            'company_subscription_id' => $subscription->id,
            'user_id' => $payer->id,
            'subscription_plan_id' => $smallerPlan->id,
            'billing_cycle' => 'monthly',
            'purpose' => PaymentPurpose::Upgrade->value,
            'amount' => 50,
            'currency' => 'EGP',
            'provider' => 'paymob',
            'status' => SubscriptionPayment::STATUS_PENDING,
        ]);

        $payments = app(SubscriptionPaymentService::class);
        $result = $payments->markSucceeded($payment, ['provider_transaction_id' => 'txn-needs-review']);
        $duplicate = $payments->markSucceeded($payment->refresh(), ['provider_transaction_id' => 'txn-needs-review']);
        $payment->refresh();

        $this->assertSame(SubscriptionPayment::STATUS_REQUIRES_REVIEW, $result['payment']->status);
        $this->assertSame(SubscriptionPayment::STATUS_REQUIRES_REVIEW, $duplicate['payment']->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('txn-needs-review', $payment->provider_transaction_id);
        $this->assertSame('DOWNGRADE_USAGE_EXCEEDED', data_get($payment->metadata, 'activation_error.code'));
        $this->assertTrue($subscription->refresh()->status === 'active');
        $this->assertSame(1, CompanySubscription::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->count());

        $refunded = $payments->markRefunded($payment->refresh());
        $refundedAgain = $payments->markRefunded($payment->refresh());
        $this->assertSame(SubscriptionPayment::STATUS_REFUNDED, $refunded['payment']->status);
        $this->assertSame(SubscriptionPayment::STATUS_REFUNDED, $refundedAgain['payment']->status);
        $this->assertSame('active', $subscription->refresh()->status);
    }

    /** @return array{Organization, Company, SubscriptionPlan, CompanySubscription} */
    private function billingFixture(int $maxCompanies = 3, int $maxUsers = 10): array
    {
        $organization = Organization::query()->create([
            'name' => fake()->unique()->company().' Organization',
            'status' => Organization::STATUS_ACTIVE,
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ]);
        $plan = SubscriptionPlan::query()->create([
            'slug' => fake()->unique()->slug(2),
            'name' => 'Organization Plan',
            'monthly_price' => 100,
            'annual_price' => 1000,
            'currency' => 'EGP',
            'max_companies' => $maxCompanies,
            'max_users' => $maxUsers,
            'storage_gb' => 50,
            'api_rate_limit_per_minute' => 120,
            'trial_enabled' => false,
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Origin Company',
            'plan' => $plan->slug,
        ]);
        $subscription = CompanySubscription::query()->create([
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'subscription_plan_id' => $plan->id,
            'entitlements_snapshot' => app(OrganizationEntitlementService::class)->snapshot($plan),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'auto_renew' => false,
            'starts_at' => now(),
            'current_period_started_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        return [$organization, $company, $plan, $subscription];
    }
}
