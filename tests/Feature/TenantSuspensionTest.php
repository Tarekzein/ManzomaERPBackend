<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Password!2345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_suspended_organization_signs_its_users_in_and_blocks_the_dashboard(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $employee = $this->workspaceUser($organization, $company, UserRole::Employee->value);
        $superAdmin = $this->superAdmin();

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/organizations/{$organization->id}/suspend", [
            'reason' => 'Payment dispute under review.',
        ])->assertOk();

        // Sign-in still succeeds …
        $login = $this->postJson('/api/auth/login', [
            'email' => $employee->email,
            'password' => self::PASSWORD,
            'device_name' => 'test',
        ])->assertOk();

        $this->assertNotEmpty($login->json('data.token'));
        $this->assertSame('organization', $login->json('data.user.suspension.scope'));
        $this->assertSame('Payment dispute under review.', $login->json('data.user.suspension.reason'));
        $this->assertSame($organization->name, $login->json('data.user.suspension.subject'));

        // … but the dashboard is closed, while the session endpoints stay open.
        Sanctum::actingAs($employee);
        $this->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_SUSPENDED');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.suspension.scope', 'organization');

        // The owner is blocked from the dashboard too, and can still recover.
        Sanctum::actingAs($owner);
        $this->getJson('/api/dashboard')->assertForbidden();

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/organizations/{$organization->id}/reactivate")->assertOk();

        Sanctum::actingAs($employee);
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.suspension', null);
    }

    public function test_an_organization_owner_suspends_a_company_and_only_its_users_are_blocked(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $branch = $this->company($organization, 'Branch Co');
        $branchUser = $this->workspaceUser($organization, $branch, UserRole::Employee->value);
        $hqUser = $this->workspaceUser($organization, $company, UserRole::Employee->value);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Manzoma-Workspace', $company->workspaceKey())
            ->postJson("/api/organizations/{$organization->id}/companies/{$branch->id}/suspend", [
                'reason' => 'Seasonal closure.',
            ])->assertOk();
        $this->flushHeaders();

        $this->assertFalse($branch->refresh()->is_active);
        $this->assertNotNull($branch->suspended_at);

        // The branch user signs in and is told why.
        $login = $this->postJson('/api/auth/login', [
            'email' => $branchUser->email,
            'password' => self::PASSWORD,
            'device_name' => 'test',
        ])->assertOk();
        $this->assertSame('company', $login->json('data.user.suspension.scope'));
        $this->assertSame('Seasonal closure.', $login->json('data.user.suspension.reason'));

        Sanctum::actingAs($branchUser);
        $this->getJson('/api/dashboard')->assertForbidden();

        // A user of the untouched workspace is unaffected.
        Sanctum::actingAs($hqUser);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.suspension', null);
        $this->getJson('/api/dashboard')->assertOk();

        Sanctum::actingAs($owner);
        $this->withHeader('X-Manzoma-Workspace', $company->workspaceKey())
            ->postJson("/api/organizations/{$organization->id}/companies/{$branch->id}/reactivate")->assertOk();
        $this->flushHeaders();

        Sanctum::actingAs($branchUser);
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_a_company_admin_cannot_suspend_a_company(): void
    {
        [$organization, $company] = $this->fixture();
        $companyAdmin = $this->workspaceUser($organization, $company, UserRole::CompanyAdmin->value);

        Sanctum::actingAs($companyAdmin);
        $this->postJson("/api/organizations/{$organization->id}/companies/{$company->id}/suspend")
            ->assertForbidden();

        $this->assertTrue($company->refresh()->is_active);
    }

    public function test_a_suspended_member_signs_in_and_is_blocked_while_peers_continue(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $employee = $this->workspaceUser($organization, $company, UserRole::Employee->value);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Manzoma-Workspace', $company->workspaceKey())
            ->postJson("/api/users/{$employee->id}/deactivate")->assertOk();
        $this->flushHeaders();

        $login = $this->postJson('/api/auth/login', [
            'email' => $employee->email,
            'password' => self::PASSWORD,
            'device_name' => 'test',
        ])->assertOk();
        $this->assertSame('membership', $login->json('data.user.suspension.scope'));

        Sanctum::actingAs($employee);
        $this->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.suspension.scope', 'membership');
    }

    public function test_the_platform_tenant_directory_merges_organizations_with_their_companies(): void
    {
        [$organization, $company] = $this->fixture();
        $branch = $this->company($organization, 'Branch Co');
        $this->workspaceUser($organization, $branch, UserRole::Employee->value);
        $superAdmin = $this->superAdmin();

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/organizations/{$organization->id}/companies/{$branch->id}/suspend", [
            'reason' => 'Under review.',
        ])->assertOk();

        $data = $this->getJson('/api/platform/tenants')->assertOk()->json('data');
        $row = collect($data['organizations'])->firstWhere('id', $organization->id);

        $this->assertSame(2, $row['companies_count']);
        $this->assertSame(1, $row['active_companies_count']);
        $this->assertSame(1, $row['suspended_companies_count']);
        $this->assertEqualsCanonicalizing(
            ['active', 'suspended'],
            collect($row['companies'])->pluck('state')->all(),
        );
        $this->assertSame('Under review.', collect($row['companies'])->firstWhere('id', $branch->id)['suspension_reason']);
        $this->assertSame(2, $data['totals']['companies']);
        $this->assertSame(1, $data['totals']['suspended_companies']);
        $this->assertNotEmpty($data['analytics']['companies_by_state']);
        $this->assertNotEmpty($data['filters']['plans']);

        // Search reaches an organization through its company name.
        $this->getJson('/api/platform/tenants?search=Branch')
            ->assertOk()
            ->assertJsonPath('data.organizations.0.id', $organization->id);
        $this->getJson('/api/platform/tenants?search=nothing-matches')
            ->assertOk()
            ->assertJsonPath('data.organizations', []);

        // Status filtering works off the organization's own state.
        $this->getJson('/api/platform/tenants?status=suspended')
            ->assertOk()
            ->assertJsonPath('data.organizations', []);
    }

    public function test_the_tenant_directory_is_closed_to_tenant_accounts(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $companyAdmin = $this->workspaceUser($organization, $company, UserRole::CompanyAdmin->value);

        Sanctum::actingAs($owner);
        $this->getJson('/api/platform/tenants')->assertForbidden();

        Sanctum::actingAs($companyAdmin);
        $this->getJson('/api/platform/tenants')->assertForbidden();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['company_id' => null, 'is_active' => true]);
        $user->assignRole(UserRole::SuperAdmin->value);

        return $user;
    }

    private function company(Organization $organization, string $name): Company
    {
        return Company::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'plan' => 'organization-plan',
            'is_active' => true,
            'settings' => [],
        ]);
    }

    private function workspaceUser(Organization $organization, Company $company, string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $company->getKey(),
            'default_company_id' => $company->getKey(),
            'is_active' => true,
            'password' => bcrypt(self::PASSWORD),
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $organization->getKey(),
            'company_id' => $company->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => Role::findByName($role, 'web')->getKey(),
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        return $user;
    }

    /** @return array{0: Organization, 1: Company, 2: User} */
    private function fixture(): array
    {
        $plan = SubscriptionPlan::query()->create([
            'slug' => 'plan-'.Str::lower(Str::random(10)),
            'name' => 'Organization Plan',
            'monthly_price' => 100,
            'annual_price' => 1000,
            'currency' => 'EGP',
            'max_companies' => 5,
            'max_users' => 50,
            'storage_gb' => 50,
            'api_rate_limit_per_minute' => 120,
            'trial_enabled' => false,
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $organization = Organization::query()->create([
            'name' => 'Acme Group',
            'status' => Organization::STATUS_ACTIVE,
            'billing_email' => 'billing@acme.test',
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
            'settings' => [],
        ]);
        $company = Company::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Acme HQ',
            'plan' => $plan->slug,
            'is_active' => true,
            'settings' => [],
        ]);
        $owner = User::factory()->create([
            'company_id' => $company->getKey(),
            'default_company_id' => $company->getKey(),
            'is_active' => true,
            'password' => bcrypt(self::PASSWORD),
        ]);
        $organization->forceFill(['created_by_user_id' => $owner->getKey()])->save();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->getKey(),
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $organization->getKey(),
            'company_id' => $company->getKey(),
            'user_id' => $owner->getKey(),
            'role_id' => Role::findByName(UserRole::CompanyAdmin->value, 'web')->getKey(),
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanySubscription::query()->create([
            'company_id' => $company->getKey(),
            'organization_id' => $organization->getKey(),
            'subscription_plan_id' => $plan->id,
            'entitlements_snapshot' => app(OrganizationEntitlementService::class)->snapshot($plan),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'auto_renew' => false,
            'starts_at' => now(),
            'current_period_started_at' => now(),
            'current_period_ends_at' => now()->addMonth(),
        ]);

        return [$organization, $company, $owner];
    }
}
