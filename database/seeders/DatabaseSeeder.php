<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            AdminUserSeeder::class,
            ProjectsSeeder::class,
            FinanceSeeder::class,
            InventorySeeder::class,
            HRSeeder::class,
            SalesSeeder::class,
            CRMSeeder::class,
            ReportingSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}
