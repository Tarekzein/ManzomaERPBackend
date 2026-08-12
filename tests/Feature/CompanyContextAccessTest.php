<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\CompanyMembershipPermissionOverride;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyContextAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_header_selects_a_membership_without_mutating_the_legacy_default(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $legacyCompanyId = $admin->company_id;
        $organizationSubscriptionId = $admin->company->organization->subscription()->value('id');
        $second = $this->secondCompany($admin);
        $this->membership($admin, $second, UserRole::Manager);
        Sanctum::actingAs($admin);

        $this->getJson('/api/dashboard')
            ->assertStatus(409)
            ->assertJsonPath('code', 'WORKSPACE_REQUIRED');

        $this->withHeader('X-Manzoma-Workspace', $second->slug)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.company.id', $second->id)
            ->assertJsonPath('data.company.subscription.id', $organizationSubscriptionId)
            ->assertJsonPath('data.company_id', $second->id)
            ->assertJsonPath('data.roles.0.name', UserRole::Manager->value)
            ->assertJsonCount(2, 'data.workspaces');

        $this->assertSame($legacyCompanyId, $admin->refresh()->company_id);
        $this->assertNotSame($second->id, $admin->company_id);

        $unassigned = Company::factory()->create(['organization_id' => $admin->company->organization_id]);
        $this->withHeader('X-Manzoma-Workspace', $unassigned->slug)
            ->getJson('/api/auth/me')
            ->assertNotFound()
            ->assertJsonPath('code', 'WORKSPACE_NOT_FOUND');
    }

    public function test_company_setup_uses_the_selected_membership_role_not_the_global_role(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $second = $this->secondCompany($admin);
        $membership = $this->membership($admin, $second, UserRole::Employee);
        Sanctum::actingAs($admin);

        $this->withHeader('X-Manzoma-Workspace', $second->slug)
            ->putJson('/api/company/setup', ['name' => 'Unauthorized Rename'])
            ->assertForbidden();

        $this->assertNotSame('Unauthorized Rename', $second->refresh()->name);

        $membership->forceFill([
            'role_id' => Role::findByName(UserRole::CompanyAdmin->value, 'web')->id,
        ])->save();

        $this->withHeader('X-Manzoma-Workspace', $second->slug)
            ->putJson('/api/company/setup', ['name' => 'Authorized Rename'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Authorized Rename');
    }

    public function test_effective_permissions_come_from_the_selected_company_membership_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $second = $this->secondCompany($admin);
        $membership = $this->membership($admin, $second, UserRole::Employee);
        $membership->permissionOverrides()->create([
            'permission_name' => 'inventory.view',
            'effect' => CompanyMembershipPermissionOverride::EFFECT_ALLOW,
        ]);
        $membership->permissionOverrides()->create([
            'permission_name' => 'projects.view',
            'effect' => CompanyMembershipPermissionOverride::EFFECT_DENY,
        ]);

        $context = app(CompanyContext::class);
        $context->bind(
            $admin,
            $second->load('organization'),
            $membership->load(['role.permissions', 'permissionOverrides']),
            $admin->organizationMemberships()->where('organization_id', $second->organization_id)->firstOrFail(),
        );

        try {
            $permissions = app(EffectiveAccessService::class)->effectivePermissionNames($admin);

            $this->assertTrue($permissions->contains('inventory.view'));
            $this->assertFalse($permissions->contains('projects.view'));
            $this->assertFalse($permissions->contains('users.view'));
        } finally {
            $context->clear();
        }
    }

    public function test_deactivating_one_company_membership_preserves_other_company_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $second = $this->secondCompany($admin);
        $this->membership($admin, $second, UserRole::CompanyAdmin);

        $employee = User::factory()->create([
            'company_id' => $admin->company_id,
            'default_company_id' => $admin->company_id,
        ]);
        $employee->assignRole(UserRole::Employee->value);
        OrganizationMembership::query()->create([
            'organization_id' => $second->organization_id,
            'user_id' => $employee->id,
            'role' => OrganizationMembership::ROLE_MEMBER,
            'status' => OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);
        $this->membership($employee, $admin->company, UserRole::Employee);
        $secondMembership = $this->membership($employee, $second, UserRole::Employee);
        Sanctum::actingAs($admin);

        $this->withHeader('X-Manzoma-Workspace', $second->slug)
            ->postJson("/api/users/{$employee->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(CompanyMembership::STATUS_SUSPENDED, $secondMembership->refresh()->status);
        $this->assertSame(CompanyMembership::STATUS_ACTIVE, $employee->companyMemberships()
            ->where('company_id', $admin->company_id)
            ->value('status'));
        $this->assertTrue($employee->refresh()->is_active);
        $this->assertSame($admin->company_id, $employee->company_id);
    }

    public function test_legacy_single_company_user_without_memberships_keeps_working(): void
    {
        $this->seed(DatabaseSeeder::class);
        $company = Company::where('name', 'Demo Company')->firstOrFail();
        $legacy = User::factory()->create([
            'company_id' => $company->id,
            'default_company_id' => null,
        ]);
        $legacy->assignRole(UserRole::Employee->value);
        Sanctum::actingAs($legacy);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.company.id', $company->id)
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.workspaces.0.id', $company->id);
    }

    public function test_empty_membership_catalogs_do_not_restore_stale_legacy_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $admin->companyMemberships()->update([
            'status' => CompanyMembership::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
        $admin->organizationMemberships()->update([
            'status' => OrganizationMembership::STATUS_SUSPENDED,
            'suspended_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonCount(0, 'data.workspaces')
            ->assertJsonCount(0, 'data.organizations');
    }

    private function secondCompany(User $user): Company
    {
        return Company::factory()->create([
            'organization_id' => $user->company->organization_id,
            'name' => 'Second Workspace',
        ]);
    }

    private function membership(User $user, Company $company, UserRole $role): CompanyMembership
    {
        $spatieRole = Role::findByName($role->value);

        return CompanyMembership::query()->updateOrCreate(
            ['company_id' => $company->id, 'user_id' => $user->id],
            [
                'organization_id' => $company->organization_id,
                'role_id' => $spatieRole->id,
                'custom_role_id' => null,
                'status' => CompanyMembership::STATUS_ACTIVE,
                'joined_at' => now(),
            ],
        );
    }
}
