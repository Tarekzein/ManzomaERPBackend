<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAndCompaniesTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_companies_and_view_platform_dashboard(): void
    {
        $this->seed(DatabaseSeeder::class);
        $superAdmin = User::where('email', 'admin@manzomatech.com')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/companies?search=Demo')
            ->assertOk()
            ->assertJsonPath('data.data.0.name', 'Demo Company');

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.metrics.companies', 1)
            ->assertJsonCount(6, 'data.analytics.company_growth')
            ->assertJsonStructure(['data' => ['analytics' => ['user_growth', 'subscriptions_by_plan', 'subscriptions_by_status']]]);
    }

    public function test_company_user_gets_company_dashboard_and_cannot_list_companies(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'company')
            ->assertJsonCount(6, 'data.analytics.finance.invoice_trend')
            ->assertJsonCount(6, 'data.analytics.sales.order_trend')
            ->assertJsonStructure(['data' => ['analytics' => [
                'finance' => ['invoice_statuses', 'receivables_outstanding', 'payables_outstanding'],
                'sales' => ['sales_statuses', 'purchase_statuses'],
                'crm' => ['contacts_by_type', 'pipeline_by_stage'],
                'inventory' => ['valuation_by_warehouse', 'reorder_alerts'],
                'projects' => ['projects_by_status', 'tasks_by_status', 'budget_total'],
                'hr' => ['headcount_by_department', 'leave_by_status', 'active_employees', 'payroll_total'],
            ]]]);

        $this->getJson('/api/companies')->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_open_dashboard_or_company_directory(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/companies')->assertUnauthorized();
    }

    public function test_dashboard_and_profile_only_expose_effectively_accessible_modules(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $basic = SubscriptionPlan::where('slug', 'basic')->firstOrFail();
        $companyAdmin->company->subscription->update(['subscription_plan_id' => $basic->id]);
        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.access.modules.finance.enabled', true)
            ->assertJsonPath('data.access.modules.projects.enabled', false);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('data.analytics.projects')
            ->assertJsonMissingPath('data.metrics.active_projects')
            ->assertJsonPath('data.access.modules.projects.enabled', false);

        $this->getJson('/api/projects')->assertForbidden();
    }

    public function test_employee_dashboard_and_search_are_filtered_by_permission_and_plan(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        $employee = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $employee->syncRoles([UserRole::Employee->value]);
        Sanctum::actingAs($employee);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => ['analytics' => ['hr', 'projects']]])
            ->assertJsonMissingPath('data.analytics.finance')
            ->assertJsonMissingPath('data.analytics.inventory')
            ->assertJsonMissingPath('data.analytics.crm');

        $this->getJson('/api/search?q=demo')
            ->assertOk()
            ->assertJsonStructure(['data' => ['projects', 'employees']])
            ->assertJsonMissingPath('data.products')
            ->assertJsonMissingPath('data.crm_contacts');

        $this->getJson('/api/finance/accounts')->assertForbidden();
    }
}
