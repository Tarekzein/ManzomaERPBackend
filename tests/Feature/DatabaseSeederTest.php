<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMCampaign;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\CRM\Models\CRMPipelineStage;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_roles_permissions_plans_features_and_admins(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas(Role::class, ['name' => 'Super Admin']);
        $this->assertDatabaseHas(Role::class, ['name' => 'Company Admin']);
        $this->assertDatabaseHas(Permission::class, ['name' => 'subscriptions.manage']);

        $this->assertSame(3, SubscriptionPlan::count());
        $this->assertDatabaseHas(SubscriptionPlan::class, ['slug' => 'basic']);
        $this->assertDatabaseHas(SubscriptionPlan::class, ['slug' => 'professional']);
        $this->assertDatabaseHas(SubscriptionPlan::class, ['slug' => 'enterprise']);
        $this->assertGreaterThanOrEqual(10, SubscriptionFeature::count());

        $this->assertDatabaseHas(Company::class, ['name' => 'Demo Company']);
        $this->assertDatabaseHas(User::class, [
            'email' => 'admin@manzomatech.com',
            'company_id' => null,
        ]);
        $this->assertDatabaseHas(User::class, ['email' => 'company.admin@example.com']);
        $this->assertSame(1, Company::count());
        $this->assertSame(1, CompanySubscription::where('status', 'active')->count());

        $this->assertTrue(User::where('email', 'admin@manzomatech.com')->first()->hasRole('Super Admin'));
        $this->assertTrue(User::where('email', 'company.admin@example.com')->first()->hasRole('Company Admin'));

        $this->assertDatabaseHas(SalesContact::class, ['email' => 'customer@seed.example', 'type' => 'customer']);
        $this->assertDatabaseHas(SalesContact::class, ['email' => 'vendor@seed.example', 'type' => 'vendor']);
        $this->assertDatabaseHas(PriceList::class, ['name' => 'Strategic Customer Pricing']);
        $this->assertDatabaseHas(SalesQuotation::class, ['number' => 'SQ-SEED-001']);
        $this->assertDatabaseHas(SalesOrder::class, ['number' => 'SO-SEED-001', 'status' => 'confirmed']);
        $this->assertDatabaseHas(PurchaseOrder::class, ['number' => 'PO-SEED-001', 'status' => 'confirmed']);

        $this->assertSame(6, CRMPipelineStage::count());
        $this->assertSame(3, CRMContact::count());
        $this->assertSame(3, CRMOpportunity::count());
        $this->assertDatabaseHas(CRMCampaign::class, ['external_id' => 'seed-crm-campaign-001', 'status' => 'sent']);

        $salesContacts = SalesContact::count();
        $crmContacts = CRMContact::count();
        $crmOpportunities = CRMOpportunity::count();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($salesContacts, SalesContact::count());
        $this->assertSame($crmContacts, CRMContact::count());
        $this->assertSame($crmOpportunities, CRMOpportunity::count());

        $this->getJson('/api/subscriptions/plans')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'basic')
            ->assertJsonCount(3, 'data');
    }
}
