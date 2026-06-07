<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_company_admin_can_change_company_subscription(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->postJson('/api/subscriptions/subscribe', [
            'plan_slug' => 'enterprise',
            'billing_cycle' => 'annual',
        ])->assertCreated()
            ->assertJsonPath('data.plan.slug', 'enterprise')
            ->assertJsonPath('data.billing_cycle', 'annual');

        $this->assertDatabaseHas('companies', ['id' => $companyAdmin->company_id, 'plan' => 'enterprise']);
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

        $employee = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $employee->assignRole(UserRole::Employee->value);
        Sanctum::actingAs($employee);

        $this->postJson('/api/subscriptions/subscribe', [
            'plan_slug' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertForbidden();
    }
}
