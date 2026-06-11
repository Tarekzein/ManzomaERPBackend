<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
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
            ->assertJsonPath('data.metrics.companies', 1);
    }

    public function test_company_user_gets_company_dashboard_and_cannot_list_companies(): void
    {
        $this->seed(DatabaseSeeder::class);
        $companyAdmin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($companyAdmin);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('data.scope', 'company');

        $this->getJson('/api/companies')->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_open_dashboard_or_company_directory(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/companies')->assertUnauthorized();
    }
}
