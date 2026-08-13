<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SubscriptionSeeder::class,
        ]);

        // Sample tenants, users and transactions are useful in development,
        // but must never be injected into every active company on a live DB.
        // Production can opt in explicitly for an isolated demo environment.
        if ($this->shouldSeedDemoData()) {
            $this->call([
                AdminUserSeeder::class,
                ProjectsSeeder::class,
                FinanceSeeder::class,
                InventorySeeder::class,
                HRSeeder::class,
                SalesSeeder::class,
                CRMSeeder::class,
                ReportingSeeder::class,
                NotificationSeeder::class,
                CustomModuleSeeder::class,
            ]);
        }

        // This is an idempotent projection of legacy company/user records and
        // is deliberately kept in the deployment-safe path.
        $this->call(OrganizationStructureSeeder::class);
    }

    protected function shouldSeedDemoData(): bool
    {
        $override = config('erp.seed_demo_data');

        if ($override !== null) {
            return filter_var($override, FILTER_VALIDATE_BOOL);
        }

        // Protect restored/staging databases even if APP_ENV was accidentally
        // left as local. An explicit true override is required once tenant or
        // user data exists.
        if (Company::query()->exists() || User::query()->exists()) {
            return false;
        }

        return app()->environment(['local', 'testing']);
    }
}
