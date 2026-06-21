<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CustomModules\Models\CustomModule;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutations_are_audited_and_usage_is_metered(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->putJson("/api/companies/{$admin->company_id}", ['settings' => ['session_timeout_hours' => 6]])->assertOk();
        $this->getJson('/api/usage')->assertOk()->assertJsonPath('data.summary.active_users', fn ($value) => $value > 0);

        $this->assertTrue(AuditLog::where('auditable_type', Company::class)->where('event', 'updated')->exists());
    }

    public function test_company_admin_can_install_approved_custom_module(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $enterprise = SubscriptionPlan::where('slug', 'enterprise')->firstOrFail();
        $admin->company->subscription->update(['subscription_plan_id' => $enterprise->id]);
        Sanctum::actingAs($admin);
        $module = CustomModule::firstOrFail();

        $this->postJson("/api/custom-modules/{$module->id}/install", ['settings' => ['approval_levels' => 2]])
            ->assertOk()
            ->assertJsonPath('data.companies.0.pivot.status', 'enabled');

        $this->patchJson("/api/custom-modules/{$module->id}/status", ['status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.companies.0.pivot.status', 'disabled');
    }

    public function test_company_admin_can_create_and_assign_custom_role_and_force_password_reset(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        $employee = $this->postJson('/api/users', [
            'name' => 'Auditor',
            'email' => 'auditor@example.com',
            'password' => 'Secret#12345',
            'password_confirmation' => 'Secret#12345',
            'role' => 'Employee',
        ])->assertCreated()->json('data');

        $role = $this->postJson('/api/custom-roles', [
            'name' => 'Finance Auditor',
            'permissions' => ['finance.view', 'audit.view'],
        ])->assertCreated()->json('data');

        $this->postJson("/api/users/{$employee['id']}/custom-role", ['custom_role_id' => $role['id']])
            ->assertOk()
            ->assertJsonPath('data.custom_role.name', 'Finance Auditor');

        $this->postJson("/api/users/{$employee['id']}/force-password-reset")
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true);
    }

    public function test_api_login_requires_mfa_only_for_untrusted_devices_and_accepts_recovery_code(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        app(EnableTwoFactorAuthentication::class)($admin);
        $admin->forceFill(['two_factor_confirmed_at' => now()])->save();
        $recoveryCode = $admin->recoveryCodes()[0];

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin#12345',
            'device_name' => 'dashboard',
        ], ['User-Agent' => 'Known Browser'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.two_factor_code.0', 'A valid two-factor or recovery code is required for this device.');

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin#12345',
            'device_name' => 'dashboard',
            'recovery_code' => $recoveryCode,
        ], ['User-Agent' => 'Known Browser'])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin#12345',
            'device_name' => 'dashboard',
        ], ['User-Agent' => 'Known Browser'])->assertOk()->assertJsonStructure(['data' => ['token']]);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'Admin#12345',
            'device_name' => 'dashboard',
        ], ['User-Agent' => 'Unknown Browser'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.two_factor_code.0', 'A valid two-factor or recovery code is required for this device.');

        $this->assertDatabaseHas('trusted_login_devices', [
            'user_id' => $admin->id,
            'device_name' => 'dashboard',
        ]);

        Sanctum::actingAs($admin);
        $deviceId = $this->getJson('/api/auth/trusted-devices')
            ->assertOk()
            ->assertJsonPath('data.0.device_name', 'dashboard')
            ->json('data.0.id');

        $this->deleteJson("/api/auth/trusted-devices/{$deviceId}")->assertOk();
        $this->assertDatabaseMissing('trusted_login_devices', ['id' => $deviceId]);
    }
}
