<?php

namespace Tests\Feature;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CustomModules\Models\CustomModule;
use App\Modules\Finance\Models\Account;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Unit;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Projects\Models\Project;
use App\Modules\Reporting\Models\ReportDefinition;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiCompanyModuleContextTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $primary;

    private Company $selected;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::query()->where('email', 'company.admin@example.com')->firstOrFail();
        $this->primary = $this->admin->company;
        $this->selected = Company::factory()->create([
            'organization_id' => $this->primary->organization_id,
            'name' => 'Alexandria Operations',
            'slug' => 'alexandria-operations',
            'settings' => [
                'notifications' => [
                    'email' => ['enabled' => true, 'from_name' => 'Alexandria Alerts'],
                ],
            ],
        ]);

        CompanyMembership::query()->create([
            'organization_id' => $this->primary->organization_id,
            'company_id' => $this->selected->id,
            'user_id' => $this->admin->id,
            'role_id' => $this->admin->roles()->where('name', UserRole::CompanyAdmin->value)->value('roles.id'),
            'status' => CompanyMembership::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);
        $this->withHeader('X-Manzoma-Workspace', $this->selected->slug);
    }

    public function test_selected_workspace_scopes_core_module_lists_instead_of_the_legacy_company(): void
    {
        Account::query()->create([
            'company_id' => $this->primary->id,
            'code' => 'CTX-A',
            'name' => 'Primary Context Account',
            'type' => 'asset',
        ]);
        Account::query()->create([
            'company_id' => $this->selected->id,
            'code' => 'CTX-B',
            'name' => 'Selected Context Account',
            'type' => 'asset',
        ]);

        $primaryUnit = Unit::query()->create([
            'company_id' => $this->primary->id,
            'name' => 'Primary Unit',
            'symbol' => 'ctx-a',
        ]);
        $selectedUnit = Unit::query()->create([
            'company_id' => $this->selected->id,
            'name' => 'Selected Unit',
            'symbol' => 'ctx-b',
        ]);
        Product::query()->create([
            'company_id' => $this->primary->id,
            'unit_id' => $primaryUnit->id,
            'sku' => 'CTX-A',
            'name' => 'Primary Context Product',
        ]);
        Product::query()->create([
            'company_id' => $this->selected->id,
            'unit_id' => $selectedUnit->id,
            'sku' => 'CTX-B',
            'name' => 'Selected Context Product',
        ]);

        CRMContact::query()->create([
            'company_id' => $this->primary->id,
            'name' => 'Primary Context Contact',
        ]);
        CRMContact::query()->create([
            'company_id' => $this->selected->id,
            'name' => 'Selected Context Contact',
        ]);
        Project::query()->create([
            'company_id' => $this->primary->id,
            'owner_id' => $this->admin->id,
            'name' => 'Primary Context Project',
        ]);
        Project::query()->create([
            'company_id' => $this->selected->id,
            'owner_id' => $this->admin->id,
            'name' => 'Selected Context Project',
        ]);
        ReportDefinition::query()->create([
            'company_id' => $this->primary->id,
            'created_by' => $this->admin->id,
            'name' => 'Primary Context Report',
            'source' => 'crm_contacts',
            'fields' => ['name'],
        ]);
        ReportDefinition::query()->create([
            'company_id' => $this->selected->id,
            'created_by' => $this->admin->id,
            'name' => 'Selected Context Report',
            'source' => 'crm_contacts',
            'fields' => ['name'],
        ]);
        SalesContact::query()->create([
            'company_id' => $this->primary->id,
            'type' => 'customer',
            'name' => 'Primary Context Sales Contact',
        ]);
        SalesContact::query()->create([
            'company_id' => $this->selected->id,
            'type' => 'customer',
            'name' => 'Selected Context Sales Contact',
        ]);
        Employee::query()->create([
            'company_id' => $this->primary->id,
            'employee_number' => 'CTX-HR-A',
            'name' => 'Primary Context Employee',
            'hire_date' => today(),
        ]);
        Employee::query()->create([
            'company_id' => $this->selected->id,
            'employee_number' => 'CTX-HR-B',
            'name' => 'Selected Context Employee',
            'hire_date' => today(),
        ]);

        $this->assertSelectedOnly('/api/finance/accounts', 'Selected Context Account', 'Primary Context Account');
        $this->assertSelectedOnly('/api/inventory/products', 'Selected Context Product', 'Primary Context Product');
        $this->assertSelectedOnly('/api/crm/contacts', 'Selected Context Contact', 'Primary Context Contact');
        $this->assertSelectedOnly('/api/projects', 'Selected Context Project', 'Primary Context Project');
        $this->assertSelectedOnly('/api/reporting/reports', 'Selected Context Report', 'Primary Context Report');
        $this->assertSelectedOnly('/api/sales/contacts', 'Selected Context Sales Contact', 'Primary Context Sales Contact');
        $this->assertSelectedOnly('/api/hr/employees', 'Selected Context Employee', 'Primary Context Employee');
    }

    public function test_integrations_custom_modules_and_notification_settings_use_the_selected_workspace(): void
    {
        MetaConnection::query()->create([
            'company_id' => $this->primary->id,
            'connected_by' => $this->admin->id,
            'business_id' => 'primary-business',
        ]);
        $selectedMeta = MetaConnection::query()->create([
            'company_id' => $this->selected->id,
            'connected_by' => $this->admin->id,
            'business_id' => 'selected-business',
        ]);
        TikTokConnection::query()->create([
            'company_id' => $this->primary->id,
            'connected_by' => $this->admin->id,
            'core_user_id' => 'primary-tiktok-user',
        ]);
        $selectedTikTok = TikTokConnection::query()->create([
            'company_id' => $this->selected->id,
            'connected_by' => $this->admin->id,
            'core_user_id' => 'selected-tiktok-user',
        ]);

        $this->getJson('/api/meta/connection')
            ->assertOk()
            ->assertJsonPath('data.id', $selectedMeta->id)
            ->assertJsonPath('data.business_id', 'selected-business');
        $this->getJson('/api/tiktok/connection')
            ->assertOk()
            ->assertJsonPath('data.id', $selectedTikTok->id)
            ->assertJsonPath('data.core_user_id', 'selected-tiktok-user');
        $this->getJson('/api/notifications/settings')
            ->assertOk()
            ->assertJsonPath('data.email.from_name', 'Alexandria Alerts');

        $customFeature = SubscriptionFeature::query()
            ->where('slug', 'custom_modules.marketplace')
            ->firstOrFail();
        $subscription = $this->primary->organization->subscription()->with('plan')->firstOrFail();
        $subscription->plan->features()->updateExistingPivot($customFeature->id, ['enabled' => true]);
        $module = CustomModule::query()->where('slug', 'example-approval-workflows')->firstOrFail();

        $this->postJson("/api/custom-modules/{$module->id}/install", ['settings' => []])
            ->assertOk();

        $this->assertDatabaseHas('company_custom_modules', [
            'company_id' => $this->selected->id,
            'custom_module_id' => $module->id,
            'status' => 'enabled',
        ]);
        $this->assertDatabaseMissing('company_custom_modules', [
            'company_id' => $this->primary->id,
            'custom_module_id' => $module->id,
        ]);
    }

    private function assertSelectedOnly(string $uri, string $visible, string $hidden): void
    {
        $response = $this->getJson($uri)->assertOk();
        $payload = json_encode($response->json('data'), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString($visible, $payload);
        $this->assertStringNotContainsString($hidden, $payload);
    }
}
