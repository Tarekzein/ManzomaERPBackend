<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Models\User;
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

        $this->assertDatabaseHas(Company::class, ['name' => 'ManzomaTech Platform']);
        $this->assertDatabaseHas(Company::class, ['name' => 'Demo Company']);
        $this->assertDatabaseHas(User::class, ['email' => 'admin@manzomatech.com']);
        $this->assertDatabaseHas(User::class, ['email' => 'company.admin@example.com']);
        $this->assertSame(2, CompanySubscription::where('status', 'active')->count());

        $this->assertTrue(User::where('email', 'admin@manzomatech.com')->first()->hasRole('Super Admin'));
        $this->assertTrue(User::where('email', 'company.admin@example.com')->first()->hasRole('Company Admin'));

        $this->getJson('/api/subscriptions/plans')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'basic')
            ->assertJsonCount(3, 'data');
    }
}
