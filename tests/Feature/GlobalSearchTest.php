<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Models\UserPermissionOverride;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            SubscriptionSeeder::class,
            AdminUserSeeder::class,
        ]);
    }

    public function test_search_normalizes_tokens_ranks_results_and_returns_safe_navigation_metadata(): void
    {
        $admin = $this->companyAdmin();
        $exact = $this->product($admin->company_id, 'Needle Widget', 'NW-EXACT');
        $this->product($admin->company_id, 'Premium Needle Widget', 'NW-PREMIUM');
        $contact = CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Needle Account',
            'company_name' => 'Widget Holdings',
            'email' => 'needle@example.test',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/search?q=%20%20needle%20%20widget%20%20&limit=2')
            ->assertOk()
            ->assertJsonPath('data.products.0.id', $exact->id)
            ->assertJsonPath('data.products.0.type', 'product')
            ->assertJsonPath('data.products.0.module', 'inventory')
            ->assertJsonPath('data.products.0.title', 'Needle Widget')
            ->assertJsonPath('data.products.0.target', "/inventory/products?product_id={$exact->id}")
            ->assertJsonPath('data.products.0.meta.sku', 'NW-EXACT')
            ->assertJsonMissing(['company_id']);

        $this->assertSame(2, $this->resultCount($response->json('data')));

        $this->getJson('/api/search?q=needle%20widget&limit=10')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $contact->id,
                'type' => 'crm_contact',
                'target' => "/crm/contacts?contact_id={$contact->id}",
            ]);
    }

    public function test_search_treats_wildcards_literally_and_validates_normalized_query_and_limit(): void
    {
        Sanctum::actingAs($this->companyAdmin());

        $response = $this->getJson('/api/search?q=%25_')
            ->assertOk();

        $this->assertSame(0, $this->resultCount($response->json('data')));

        $this->getJson('/api/search?q=%20a%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->getJson('/api/search?q=valid&limit=26')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_every_result_is_scoped_to_the_authenticated_users_company(): void
    {
        $otherCompany = Company::factory()->create(['name' => 'Other Search Company']);
        $otherOwner = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherOwner->syncRoles([UserRole::CompanyAdmin->value]);

        $this->product($otherCompany->id, 'Foreign Tenant Needle', 'FOREIGN-NEEDLE');
        CRMContact::create([
            'company_id' => $otherCompany->id,
            'name' => 'Foreign Tenant Needle',
            'email' => 'foreign@example.test',
        ]);
        Employee::create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherOwner->id,
            'employee_number' => 'FOREIGN-EMP',
            'name' => 'Foreign Tenant Needle',
            'hire_date' => now()->toDateString(),
        ]);
        Project::create([
            'company_id' => $otherCompany->id,
            'owner_id' => $otherOwner->id,
            'name' => 'Foreign Tenant Needle',
        ]);

        Sanctum::actingAs($this->companyAdmin());
        $response = $this->getJson('/api/search?q=foreign%20tenant%20needle')
            ->assertOk();

        $this->assertSame(0, $this->resultCount($response->json('data')));
    }

    public function test_permission_denials_and_subscription_features_remove_entire_result_groups(): void
    {
        $admin = $this->companyAdmin();
        $this->product($admin->company_id, 'Access Needle', 'ACCESS-NEEDLE');
        CRMContact::create([
            'company_id' => $admin->company_id,
            'name' => 'Access Needle',
        ]);
        $project = Project::create([
            'company_id' => $admin->company_id,
            'owner_id' => $admin->id,
            'name' => 'Plan Feature Needle',
        ]);
        $admin->permissionOverrides()->create([
            'permission_name' => 'crm.view',
            'effect' => UserPermissionOverride::EFFECT_DENY,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/search?q=access%20needle')
            ->assertOk()
            ->assertJsonPath('data.products.0.type', 'product')
            ->assertJsonMissingPath('data.crm_contacts');

        $basic = SubscriptionPlan::where('slug', 'basic')->firstOrFail();
        $admin->company->subscription->update(['subscription_plan_id' => $basic->id]);
        $admin->unsetRelation('company');

        $this->getJson('/api/search?q=plan%20feature%20needle')
            ->assertOk()
            ->assertJsonMissingPath('data.projects')
            ->assertJsonMissing(['id' => $project->id, 'type' => 'project']);
    }

    public function test_employee_search_only_returns_their_own_hr_profile_and_owned_or_assigned_projects(): void
    {
        $companyAdmin = $this->companyAdmin();
        $employeeUser = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $employeeUser->syncRoles([UserRole::Employee->value]);
        $peerUser = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $peerUser->syncRoles([UserRole::Employee->value]);

        $ownEmployee = Employee::create([
            'company_id' => $companyAdmin->company_id,
            'user_id' => $employeeUser->id,
            'employee_number' => 'SCOPE-SELF',
            'name' => 'Scoped Needle Self',
            'hire_date' => now()->toDateString(),
        ]);
        Employee::create([
            'company_id' => $companyAdmin->company_id,
            'user_id' => $peerUser->id,
            'employee_number' => 'SCOPE-PEER',
            'name' => 'Scoped Needle Peer',
            'hire_date' => now()->toDateString(),
        ]);
        $ownProject = Project::create([
            'company_id' => $companyAdmin->company_id,
            'owner_id' => $employeeUser->id,
            'name' => 'Scoped Needle Own Project',
        ]);
        $assignedProject = Project::create([
            'company_id' => $companyAdmin->company_id,
            'owner_id' => $peerUser->id,
            'name' => 'Scoped Needle Assigned Project',
        ]);
        ProjectTask::create([
            'project_id' => $assignedProject->id,
            'assignee_id' => $employeeUser->id,
            'title' => 'Assigned project access',
        ]);
        Project::create([
            'company_id' => $companyAdmin->company_id,
            'owner_id' => $peerUser->id,
            'name' => 'Scoped Needle Unassigned Project',
        ]);
        Sanctum::actingAs($employeeUser);

        $response = $this->getJson('/api/search?q=scoped%20needle&limit=10')
            ->assertOk()
            ->assertJsonMissingPath('data.products')
            ->assertJsonMissingPath('data.crm_contacts');

        $this->assertSame([$ownEmployee->id], collect($response->json('data.employees'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$ownProject->id, $assignedProject->id],
            collect($response->json('data.projects'))->pluck('id')->all(),
        );
    }

    public function test_manager_search_includes_direct_reports_and_their_assigned_projects_but_unlinked_manager_has_no_scope(): void
    {
        $companyAdmin = $this->companyAdmin();
        $managerUser = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $managerUser->syncRoles([UserRole::Manager->value]);
        $reportUser = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $reportUser->syncRoles([UserRole::Employee->value]);
        $outsiderUser = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $outsiderUser->syncRoles([UserRole::Employee->value]);

        $managerEmployee = Employee::create([
            'company_id' => $companyAdmin->company_id,
            'user_id' => $managerUser->id,
            'employee_number' => 'MANAGER-SCOPE',
            'name' => 'Manager Scope Needle',
            'hire_date' => now()->toDateString(),
        ]);
        $reportEmployee = Employee::create([
            'company_id' => $companyAdmin->company_id,
            'user_id' => $reportUser->id,
            'manager_id' => $managerEmployee->id,
            'employee_number' => 'REPORT-SCOPE',
            'name' => 'Manager Scope Direct Report Needle',
            'hire_date' => now()->toDateString(),
        ]);
        Employee::create([
            'company_id' => $companyAdmin->company_id,
            'user_id' => $outsiderUser->id,
            'employee_number' => 'OUTSIDER-SCOPE',
            'name' => 'Manager Scope Outsider Needle',
            'hire_date' => now()->toDateString(),
        ]);

        $assignedProject = Project::create([
            'company_id' => $companyAdmin->company_id,
            'owner_id' => $outsiderUser->id,
            'name' => 'Manager Scope Assigned Needle Project',
        ]);
        ProjectTask::create([
            'project_id' => $assignedProject->id,
            'assignee_id' => $reportUser->id,
            'title' => 'Direct report assignment',
        ]);
        Project::create([
            'company_id' => $companyAdmin->company_id,
            'owner_id' => $outsiderUser->id,
            'name' => 'Manager Scope Unassigned Needle Project',
        ]);

        Sanctum::actingAs($managerUser);
        $response = $this->getJson('/api/search?q=manager%20scope%20needle&limit=10')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$managerEmployee->id, $reportEmployee->id],
            collect($response->json('data.employees'))->pluck('id')->all(),
        );
        $this->assertSame([$assignedProject->id], collect($response->json('data.projects'))->pluck('id')->all());

        $unlinkedManager = User::factory()->create(['company_id' => $companyAdmin->company_id]);
        $unlinkedManager->syncRoles([UserRole::Manager->value]);
        Sanctum::actingAs($unlinkedManager);

        $unlinkedResponse = $this->getJson('/api/search?q=manager%20scope%20needle&limit=10')
            ->assertOk();

        $this->assertSame([], $unlinkedResponse->json('data.employees'));
        $this->assertSame([], $unlinkedResponse->json('data.projects'));
    }

    private function companyAdmin(): User
    {
        return User::where('email', 'company.admin@example.com')->firstOrFail();
    }

    private function product(int $companyId, string $name, string $sku): Product
    {
        $unit = Unit::firstOrCreate(
            ['company_id' => $companyId, 'symbol' => 'EA'],
            ['name' => 'Each', 'precision' => 0],
        );

        return Product::create([
            'company_id' => $companyId,
            'unit_id' => $unit->id,
            'name' => $name,
            'sku' => $sku,
        ]);
    }

    private function resultCount(array $groups): int
    {
        return collect($groups)->sum(fn (array $items) => count($items));
    }
}
