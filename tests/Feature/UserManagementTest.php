<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Services\EffectiveAccessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_has_no_company_and_can_create_all_user_types(): void
    {
        $this->seed(DatabaseSeeder::class);

        $superAdmin = User::where('email', 'admin@manzomatech.com')->firstOrFail();
        $company = Company::where('name', 'Demo Company')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->assertNull($superAdmin->company_id);

        $this->postJson('/api/users', [
            'name' => 'Second System Admin',
            'email' => 'system2@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::SuperAdmin->value,
            'company_id' => $company->id,
        ])->assertCreated()->assertJsonPath('data.company_id', null);

        $this->postJson('/api/users', [
            'name' => 'Company Manager',
            'email' => 'manager@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::Manager->value,
            'company_id' => $company->id,
        ])->assertCreated()->assertJsonPath('data.company.id', $company->id);
    }

    public function test_company_admin_can_only_create_company_managed_roles_in_own_company(): void
    {
        $this->seed(DatabaseSeeder::class);

        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/users', [
            'name' => 'Employee One',
            'email' => 'employee@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::Employee->value,
        ])->assertCreated()->assertJsonPath('data.company_id', $companyAdmin->company_id);

        $this->postJson('/api/users', [
            'name' => 'Forbidden System Admin',
            'email' => 'forbidden@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::SuperAdmin->value,
        ])->assertUnprocessable();
    }

    public function test_company_admin_only_receives_subscription_allowed_assignable_permissions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonFragment(['name' => 'projects.view'])
            ->assertJsonMissing(['name' => 'custom_modules.view'])
            ->assertJsonMissing(['name' => 'platform.view'])
            ->assertJsonMissing(['name' => 'users.delete']);

        $this->postJson('/api/users', [
            'name' => 'Injected User',
            'email' => 'injected@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::Employee->value,
            'allowed_permissions' => ['custom_modules.view'],
        ])->assertUnprocessable();
    }

    public function test_user_permission_denies_remove_role_default_permissions_from_effective_access(): void
    {
        $this->seed(DatabaseSeeder::class);

        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson('/api/users', [
            'name' => 'Restricted Manager',
            'email' => 'restricted.manager@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::Manager->value,
            'denied_permissions' => ['projects.view'],
        ])->assertCreated();

        $this->assertNotContains('projects.view', $response->json('data.access.permissions'));
        $this->assertContains('projects.view', $response->json('data.access.role_permissions'));
        $this->assertContains('projects.view', $response->json('data.access.denied_permissions'));
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $response->json('data.id'),
            'permission_name' => 'projects.view',
            'effect' => 'deny',
        ]);
    }

    public function test_user_specific_allowed_permissions_are_enforced_by_spatie_can_and_module_policies(): void
    {
        $this->seed(DatabaseSeeder::class);

        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $response = $this->postJson('/api/users', [
            'name' => 'Inventory Enabled Employee',
            'email' => 'inventory.employee@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::Employee->value,
            'allowed_permissions' => ['inventory.view', 'inventory.create', 'inventory.edit', 'inventory.export'],
        ])->assertCreated();

        $employee = User::findOrFail($response->json('data.id'));
        $employee->forceFill(['must_change_password' => false])->save();
        $this->assertTrue($employee->can('inventory.view'));
        $this->assertTrue($employee->can('inventory.create'));
        $this->assertContains('inventory.view', app(EffectiveAccessService::class)->effectivePermissionNames($employee)->all());

        $listedUsers = $this->getJson('/api/users?per_page=100')->assertOk()->json('data.data');
        $listedEmployee = collect($listedUsers)->firstWhere('id', $employee->id);
        $this->assertTrue($listedEmployee['access']['modules']['inventory']['enabled']);
        $this->assertContains('inventory.view', $listedEmployee['access']['allowed_permissions']);

        Sanctum::actingAs($employee);
        $this->getJson('/api/inventory/products')->assertOk();
    }

    public function test_manager_can_delegate_limited_permissions_but_employee_cannot_manage_users(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::where('name', 'Demo Company')->firstOrFail();
        $manager = User::factory()->create(['company_id' => $company->id]);
        $manager->assignRole(UserRole::Manager->value);
        Sanctum::actingAs($manager);

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/roles')->assertOk()->assertJsonFragment([UserRole::Employee->value]);
        $this->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonFragment(['hr.view'])
            ->assertJsonMissing(['users.delete']);

        $this->postJson('/api/users', [
            'name' => 'Team Member',
            'email' => 'team.member@example.com',
            'password' => 'Secret#123',
            'password_confirmation' => 'Secret#123',
            'role' => UserRole::Employee->value,
            'permissions' => ['hr.view', 'projects.view'],
        ])->assertCreated()
            ->assertJsonPath('data.company_id', $company->id)
            ->assertJsonPath('data.access.permissions.0', 'hr.view');

        $employee = User::factory()->create(['company_id' => $company->id]);
        $employee->assignRole(UserRole::Employee->value);
        Sanctum::actingAs($employee);

        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
    }

    public function test_user_of_inactive_company_cannot_login_but_super_admin_can(): void
    {
        $this->seed(DatabaseSeeder::class);

        Company::where('name', 'Demo Company')->update(['is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email' => 'company.admin@example.com',
            'password' => 'Admin#12345',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/login', [
            'email' => 'admin@manzomatech.com',
            'password' => 'Admin#12345',
        ])->assertOk()->assertJsonPath('data.user.company_id', null);
    }
}
