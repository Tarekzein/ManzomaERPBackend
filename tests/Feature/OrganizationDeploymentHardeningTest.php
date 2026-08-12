<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Services\CompanyDataPrivacyService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationDeploymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_repairs_billing_fields_and_translates_legacy_billing_suspension_once(): void
    {
        $legacySuspendedAt = now()->subDay()->startOfSecond();
        $company = Company::factory()->create([
            'is_active' => false,
            'settings' => [
                'billing' => ['suspended_at' => $legacySuspendedAt->toISOString()],
                'preserved' => 'value',
            ],
        ]);
        $admin = User::factory()->create([
            'company_id' => $company->getKey(),
            'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate(UserRole::CompanyAdmin->value, 'web'));
        $plan = $this->createPlan();
        $subscription = CompanySubscription::query()->create([
            'company_id' => $company->getKey(),
            'organization_id' => null,
            'subscription_plan_id' => $plan->getKey(),
            'entitlements_snapshot' => null,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);
        $payment = SubscriptionPayment::query()->create([
            'reference' => (string) Str::uuid(),
            'company_id' => $company->getKey(),
            'organization_id' => null,
            'initiated_from_company_id' => null,
            'user_id' => $admin->getKey(),
            'subscription_plan_id' => $plan->getKey(),
            'amount' => 149,
        ]);

        $this->artisan('organizations:backfill', ['--fail-on-issues' => true])
            ->assertExitCode(0);

        $company->refresh();
        $organization = $company->organization()->firstOrFail();

        $this->assertTrue($company->is_active);
        $this->assertSame('value', data_get($company->settings, 'preserved'));
        $this->assertNull(data_get($company->settings, 'billing.suspended_at'));
        $this->assertSame(
            $legacySuspendedAt->toISOString(),
            data_get($company->settings, 'migration.legacy_billing_suspension.legacy_suspended_at'),
        );
        $this->assertTrue($organization->billing_suspended_at->equalTo($legacySuspendedAt));
        $this->assertSame($organization->getKey(), $subscription->refresh()->organization_id);
        // MySQL's native JSON type does not preserve object-key order.
        $this->assertEquals([
            'max_companies' => 3,
            'max_users' => 40,
            'storage_gb' => 25,
            'api_rate_limit_per_minute' => 90,
        ], $subscription->entitlements_snapshot);
        $this->assertSame($organization->getKey(), $payment->refresh()->organization_id);
        $this->assertSame($company->getKey(), $payment->initiated_from_company_id);

        // A successful payment may clear the translated organization marker.
        // Rerunning the migration command must not recreate it from stale data.
        $organization->forceFill(['billing_suspended_at' => null])->save();

        $this->artisan('organizations:backfill', ['--fail-on-issues' => true])
            ->assertExitCode(0);

        $this->assertNull($organization->refresh()->billing_suspended_at);
    }

    public function test_administratively_inactive_company_is_not_reactivated_without_billing_marker(): void
    {
        $company = Company::factory()->create([
            'is_active' => false,
            'settings' => ['administrative_suspension' => true],
        ]);
        $admin = User::factory()->create([
            'company_id' => $company->getKey(),
            'is_active' => true,
        ]);
        $admin->assignRole(Role::findOrCreate(UserRole::CompanyAdmin->value, 'web'));

        $this->artisan('organizations:backfill', ['--fail-on-issues' => true])
            ->assertExitCode(0);

        $company->refresh();

        $this->assertFalse($company->is_active);
        $this->assertNull($company->organization()->firstOrFail()->billing_suspended_at);
    }

    public function test_company_with_legacy_or_organization_billing_history_cannot_be_erased(): void
    {
        $organization = Organization::query()->create(['name' => 'Retained Billing Group']);
        $company = Company::factory()->create(['organization_id' => $organization->getKey()]);
        $plan = $this->createPlan();
        CompanySubscription::query()->create([
            'company_id' => $company->getKey(),
            // Deliberately legacy-shaped: the application guard must protect it
            // even before its organization reference has been repaired.
            'organization_id' => null,
            'subscription_plan_id' => $plan->getKey(),
            'status' => 'expired',
            'billing_cycle' => 'monthly',
        ]);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::findOrCreate(UserRole::SuperAdmin->value, 'web'));

        try {
            app(CompanyDataPrivacyService::class)->erase($superAdmin, $company, $company->name);
            $this->fail('The application must retain organization billing history.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertDatabaseHas('companies', ['id' => $company->getKey()]);

        $this->expectException(QueryException::class);
        $company->delete();
    }

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create([
            'slug' => 'deployment-hardening-'.Str::lower(Str::random(8)),
            'name' => 'Deployment Hardening',
            'monthly_price' => 149,
            'annual_price' => 1490,
            'currency' => 'EGP',
            'max_users' => 40,
            'max_companies' => 3,
            'storage_gb' => 25,
            'api_rate_limit_per_minute' => 90,
            'is_active' => true,
        ]);
    }
}
