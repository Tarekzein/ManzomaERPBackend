<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
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
