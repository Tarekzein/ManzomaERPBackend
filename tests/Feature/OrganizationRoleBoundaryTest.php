<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_can_assign_general_and_module_workspace_roles(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        Sanctum::actingAs($owner);

        $roles = $this->getJson('/api/roles')->assertOk()->json('data');

        $this->assertSame(
            UserRole::workspaceAssignableValues(),
            collect($roles)->pluck('name')->all(),
        );
        $this->assertTrue(collect($roles)->every(fn (array $role) => $role['id'] !== null));
    }

    public function test_invited_user_receives_the_selected_company_role(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        Sanctum::actingAs($owner);

        $newCompany = $this->postJson("/api/organizations/{$organization->id}/companies", [
            'name' => 'Branch Co',
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ])->assertCreated()->json('data');

        $adminRole = Role::findByName(UserRole::CompanyAdmin->value, 'web');
        $token = $this->postJson("/api/organizations/{$organization->id}/invitations", [
            'email' => 'branch-admin@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [[
                'company_id' => $newCompany['id'],
                'role_id' => $adminRole->id,
            ]],
        ])->assertCreated()->json('data.token');

        $this->postJson("/api/organization-invitations/{$token}/register", [
            'name' => 'Branch Admin',
            'password' => 'Password!2345',
            'password_confirmation' => 'Password!2345',
        ])->assertCreated();

        $invitee = User::query()->where('email', 'branch-admin@example.test')->firstOrFail();

        $this->assertDatabaseHas('company_memberships', [
            'company_id' => $newCompany['id'],
            'user_id' => $invitee->id,
            'role_id' => $adminRole->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
        ]);
    }

    public function test_company_admin_of_a_new_company_cannot_reach_organization_data(): void
    {
        [$organization, $company, $owner] = $this->fixture();

        $branch = Company::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Branch Co',
            'plan' => $company->plan,
            'is_active' => true,
            'settings' => [],
        ]);
        $branchAdmin = User::factory()->create([
            'company_id' => $branch->id,
            'default_company_id' => $branch->id,
            'is_active' => true,
        ]);
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $branchAdmin->id,
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $organization->id,
            'company_id' => $branch->id,
            'user_id' => $branchAdmin->id,
            'role_id' => Role::findByName(UserRole::CompanyAdmin->value, 'web')->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($branchAdmin);

        // The workspace switcher still needs to name the organization…
        $show = $this->getJson("/api/organizations/{$organization->id}")->assertOk();
        $this->assertSame($organization->name, $show->json('data.name'));
        $this->assertSame(['Branch Co'], collect($show->json('data.companies'))->pluck('name')->all());

        // …but no organization-level data comes with it.
        foreach (['billing_email', 'settings', 'created_by_user_id', 'billing_suspended_at', 'active_companies_count', 'active_members_count', 'usage', 'subscription'] as $attribute) {
            $show->assertJsonMissingPath("data.{$attribute}");
        }

        $list = $this->getJson('/api/organizations')->assertOk();
        foreach (['billing_email', 'settings', 'created_by_user_id', 'billing_suspended_at', 'active_companies_count', 'active_members_count'] as $attribute) {
            $list->assertJsonMissingPath("data.data.0.{$attribute}");
        }

        $bootstrap = $this->getJson('/api/workspace/bootstrap')->assertOk();
        foreach (['billing_email', 'settings', 'created_by_user_id', 'billing_suspended_at'] as $attribute) {
            $bootstrap->assertJsonMissingPath("data.organizations.0.{$attribute}");
        }

        // And nothing organization-scoped can be read or written.
        $this->getJson("/api/organizations/{$organization->id}/members")->assertForbidden();
        $this->getJson("/api/organizations/{$organization->id}/invitations")->assertForbidden();
        $this->patchJson("/api/organizations/{$organization->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->postJson("/api/organizations/{$organization->id}/companies", [
            'name' => 'Sneaky',
            'timezone' => 'Africa/Cairo',
            'locale' => 'en',
            'currency' => 'EGP',
        ])->assertForbidden();
    }

    public function test_organization_owner_still_sees_organization_data(): void
    {
        [$organization, , $owner] = $this->fixture();
        Sanctum::actingAs($owner);

        $this->getJson("/api/organizations/{$organization->id}")
            ->assertOk()
            ->assertJsonPath('data.billing_email', 'billing@acme.test')
            ->assertJsonPath('data.created_by_user_id', $owner->id)
            ->assertJsonPath('data.active_companies_count', 1)
            ->assertJsonPath('data.usage.users.used', 1);

        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonPath('data.data.0.billing_email', 'billing@acme.test')
            ->assertJsonPath('data.data.0.active_companies_count', 1);
    }

    public function test_a_workspace_role_outside_admin_manager_employee_is_rejected(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        Sanctum::actingAs($owner);

        $this->postJson("/api/organizations/{$organization->id}/invitations", [
            'email' => 'escalation@example.test',
            'role' => OrganizationMembership::ROLE_MEMBER,
            'companies' => [[
                'company_id' => $company->id,
                'role_id' => Role::findByName(UserRole::SuperAdmin->value, 'web')->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('companies');
    }

    public function test_a_company_admin_cannot_manage_the_organization_owner(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $companyAdmin = $this->workspaceUser($organization, $company, UserRole::CompanyAdmin->value);
        $employee = $this->workspaceUser($organization, $company, UserRole::Employee->value);

        Sanctum::actingAs($companyAdmin);

        // The owner is listed but flagged as untouchable; a peer is not.
        $rows = collect($this->getJson('/api/users')->assertOk()->json('data.data'))->keyBy('id');
        $this->assertFalse($rows[$owner->id]['can_manage']);
        $this->assertTrue($rows[$employee->id]['can_manage']);
        $this->assertFalse($rows[$companyAdmin->id]['can_manage'], 'Nobody manages themselves here.');

        // And every mutation against the owner is refused.
        $this->patchJson("/api/users/{$owner->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->patchJson("/api/users/{$owner->id}/role", ['role' => UserRole::Employee->value])->assertForbidden();
        $this->postJson("/api/users/{$owner->id}/deactivate")->assertForbidden();
        $this->postJson("/api/users/{$owner->id}/force-password-reset")->assertForbidden();
        $this->deleteJson("/api/users/{$owner->id}")->assertForbidden();

        $customRole = $this->postJson('/api/custom-roles', [
            'name' => 'Restricted',
            'permissions' => ['users.view'],
        ])->assertCreated()->json('data');
        $this->postJson("/api/users/{$owner->id}/custom-role", ['custom_role_id' => $customRole['id']])
            ->assertForbidden();

        $this->assertSame($owner->name, $owner->refresh()->name);
        $this->assertTrue($owner->is_active);
        $this->assertSame(
            OrganizationMembership::ROLE_OWNER,
            $owner->organizationMemberships()->where('organization_id', $organization->id)->value('role'),
        );

        // The same company admin still administers their own workspace peers.
        $this->patchJson("/api/users/{$employee->id}", ['name' => 'Renamed Employee'])->assertOk();
    }

    public function test_the_organization_owner_can_still_manage_a_company_admin(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $companyAdmin = $this->workspaceUser($organization, $company, UserRole::CompanyAdmin->value);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Manzoma-Workspace', $company->workspaceKey());

        $rows = collect($this->getJson('/api/users')->assertOk()->json('data.data'))->keyBy('id');
        $this->assertTrue($rows[$companyAdmin->id]['can_manage']);

        $this->patchJson("/api/users/{$companyAdmin->id}/role", ['role' => UserRole::Manager->value])->assertOk();
    }

    public function test_the_dashboard_shows_the_organization_to_its_owner_only(): void
    {
        [$organization, $company, $owner] = $this->fixture();
        $branch = Company::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Branch Co',
            'plan' => $company->plan,
            'is_active' => true,
            'settings' => [],
        ]);
        $companyAdmin = $this->workspaceUser($organization, $company, UserRole::CompanyAdmin->value);

        Sanctum::actingAs($owner);
        $this->withHeader('X-Manzoma-Workspace', $company->workspaceKey());
        $overview = $this->getJson('/api/dashboard')->assertOk()->json('data.organization');

        $this->assertSame($organization->name, $overview['name']);
        $this->assertSame(OrganizationMembership::ROLE_OWNER, $overview['role']);
        $this->assertEqualsCanonicalizing(
            [$company->name, $branch->name],
            collect($overview['companies'])->pluck('name')->all(),
        );
        $this->assertTrue(collect($overview['companies'])->firstWhere('id', $company->id)['is_current']);
        $this->assertSame(2, $overview['usage']['companies']['used']);
        $this->assertNotNull($overview['usage']['users']['limit']);

        // The Company Admin of a workspace inside that organization gets none
        // of it: the organization is not theirs to see.
        Sanctum::actingAs($companyAdmin);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.organization', null);
    }

    public function test_the_platform_dashboard_reports_organizations(): void
    {
        [$organization] = $this->fixture();
        $superAdmin = User::factory()->create(['company_id' => null, 'is_active' => true]);
        $superAdmin->assignRole(UserRole::SuperAdmin->value);

        Sanctum::actingAs($superAdmin);
        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame(1, $data['metrics']['organizations']);
        $this->assertSame($organization->name, $data['recent_organizations'][0]['name']);
        $this->assertSame(1, $data['recent_organizations'][0]['companies_count']);
        $this->assertSame(1, $data['recent_organizations'][0]['members_count']);
        $this->assertNotEmpty($data['analytics']['organization_growth']);
    }

    private function workspaceUser(Organization $organization, Company $company, string $role): User
    {
        $user = User::factory()->create([
            'company_id' => $company->getKey(),
            'default_company_id' => $company->getKey(),
            'is_active' => true,
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
    private function fixture(int $maxCompanies = 5, int $maxUsers = 25): array
    {
        $plan = SubscriptionPlan::query()->create([
            'slug' => 'plan-'.Str::lower(Str::random(10)),
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
        $posFeature = SubscriptionFeature::query()->create([
            'slug' => 'core.pos',
            'name' => 'Point of Sale',
            'module' => 'pos',
            'description' => 'Point of sale workspace access.',
        ]);
        $plan->features()->attach($posFeature->id, ['enabled' => true]);
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
            'organization_id' => $organization->id,
            'name' => 'Acme HQ',
            'plan' => $plan->slug,
            'is_active' => true,
            'settings' => [],
        ]);
        $owner = User::factory()->create([
            'company_id' => $company->id,
            'default_company_id' => $company->id,
            'is_active' => true,
        ]);
        $owner->assignRole(UserRole::CompanyAdmin->value);
        $organization->forceFill(['created_by_user_id' => $owner->id])->save();
        OrganizationMembership::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'role' => OrganizationMembership::ROLE_OWNER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanyMembership::query()->create([
            'organization_id' => $organization->id,
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role_id' => Role::findByName(UserRole::CompanyAdmin->value, 'web')->id,
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        CompanySubscription::query()->create([
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

        return [$organization, $company, $owner];
    }
}
