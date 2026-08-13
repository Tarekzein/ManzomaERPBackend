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
use App\Modules\Subscriptions\Models\SubscriptionPlanPromotion;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OrganizationStructureSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_catalog_seeders_add_new_defaults_without_overwriting_live_customizations(): void
    {
        $this->seed([
            RolesAndPermissionsSeeder::class,
            SubscriptionSeeder::class,
        ]);

        $customPermission = Permission::findOrCreate('live.custom.permission');
        $viewer = Role::findOrCreate('Viewer');
        $viewer->givePermissionTo($customPermission);

        $manager = Role::findByName('Manager');
        $manager->givePermissionTo($customPermission);

        $professional = SubscriptionPlan::where('slug', 'professional')->firstOrFail();
        $professional->forceFill([
            'name' => 'Live Professional',
            'monthly_price' => 777,
            'max_users' => 73,
            'max_companies' => 9,
            'is_active' => false,
        ])->save();

        $posFeature = SubscriptionFeature::where('slug', 'core.pos')->firstOrFail();
        $professional->features()->updateExistingPivot($posFeature->getKey(), [
            'enabled' => false,
            'value' => 'disabled-by-platform-admin',
        ]);

        $customFeature = SubscriptionFeature::create([
            'slug' => 'live.custom.feature',
            'name' => 'Live Custom Feature',
            'module' => 'custom',
            'description' => 'Managed on the live platform.',
            'is_metered' => true,
        ]);
        $professional->features()->attach($customFeature->getKey(), [
            'enabled' => true,
            'value' => '250',
        ]);

        $promotion = SubscriptionPlanPromotion::where('subscription_plan_id', $professional->getKey())
            ->where('name', 'Launch annual discount')
            ->firstOrFail();
        $promotion->forceFill([
            'discount_value' => 7,
            'is_active' => false,
            'ends_at' => now()->subDay(),
        ])->save();
        $promotionEndsAt = $promotion->ends_at->toDateTimeString();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            SubscriptionSeeder::class,
        ]);

        $this->assertTrue(Role::findByName('Viewer')->hasPermissionTo('live.custom.permission'));
        $this->assertTrue(Role::findByName('Manager')->hasPermissionTo('live.custom.permission'));
        $this->assertTrue(Role::findByName('POS Cashier')->hasPermissionTo('pos.sell'));

        $professional->refresh();
        $this->assertSame('Live Professional', $professional->name);
        $this->assertSame('777.00', $professional->monthly_price);
        $this->assertSame(73, $professional->max_users);
        $this->assertSame(9, $professional->max_companies);
        $this->assertFalse($professional->is_active);

        $posPivot = $professional->features()->whereKey($posFeature->getKey())->firstOrFail()->pivot;
        $this->assertFalse((bool) $posPivot->enabled);
        $this->assertSame('disabled-by-platform-admin', $posPivot->value);

        $customPivot = $professional->features()->whereKey($customFeature->getKey())->firstOrFail()->pivot;
        $this->assertTrue((bool) $customPivot->enabled);
        $this->assertSame('250', $customPivot->value);

        $promotion->refresh();
        $this->assertSame('7.00', $promotion->discount_value);
        $this->assertFalse($promotion->is_active);
        $this->assertSame($promotionEndsAt, $promotion->ends_at->toDateTimeString());
    }

    public function test_deployment_safe_database_seeding_does_not_create_demo_business_data(): void
    {
        $seeder = new class extends DatabaseSeeder
        {
            protected function shouldSeedDemoData(): bool
            {
                return false;
            }
        };

        $seeder->setContainer($this->app)->__invoke();

        $this->assertDatabaseHas(Permission::class, ['name' => 'pos.sell']);
        $this->assertDatabaseHas(SubscriptionFeature::class, ['slug' => 'core.pos']);
        $this->assertDatabaseHas(SubscriptionPlan::class, ['slug' => 'professional']);
        $this->assertDatabaseMissing(Company::class, ['slug' => 'demo-company']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, SalesContact::count());
    }

    public function test_subscription_catalog_upgrade_does_not_launch_a_promotion_for_an_existing_plan(): void
    {
        SubscriptionPlan::create([
            'slug' => 'professional',
            'name' => 'Existing Live Plan',
            'monthly_price' => 149,
            'annual_price' => 1490,
            'currency' => 'EGP',
            'max_users' => 100,
            'max_companies' => 5,
            'storage_gb' => 100,
            'api_rate_limit_per_minute' => 120,
            'trial_enabled' => false,
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->seed(SubscriptionSeeder::class);

        $this->assertDatabaseMissing(SubscriptionPlanPromotion::class, [
            'name' => 'Launch annual discount',
        ]);
        $this->assertDatabaseHas(SubscriptionFeature::class, ['slug' => 'core.pos']);
    }

    public function test_organization_structure_seeder_is_safe_before_the_foundation_migration(): void
    {
        Schema::shouldReceive('hasTable')
            ->once()
            ->with('organizations')
            ->andReturnFalse();

        $this->seed(OrganizationStructureSeeder::class);

        $this->assertTrue(true);
    }

    public function test_demo_seeding_defaults_off_for_a_populated_database_even_in_testing(): void
    {
        Company::factory()->create();

        $seeder = new class extends DatabaseSeeder
        {
            public function demoDataEnabled(): bool
            {
                return $this->shouldSeedDemoData();
            }
        };

        $this->assertFalse($seeder->demoDataEnabled());
    }
}
