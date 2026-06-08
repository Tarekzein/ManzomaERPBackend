<?php

namespace Database\Seeders;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $demo = Company::updateOrCreate(
            ['name' => 'Demo Company'],
            [
                'plan' => 'professional',
                'timezone' => 'Africa/Cairo',
                'locale' => 'en',
                'currency' => 'EGP',
                'is_active' => true,
            ]
        );

        $superAdmin = User::updateOrCreate(
            ['email' => env('ERP_SUPER_ADMIN_EMAIL', 'admin@manzomatech.com')],
            [
                'company_id' => null,
                'name' => env('ERP_SUPER_ADMIN_NAME', 'ManzomaTech Super Admin'),
                'password' => Hash::make(env('ERP_SUPER_ADMIN_PASSWORD', 'Admin#12345')),
            ]
        );
        $superAdmin->syncRoles([UserRole::SuperAdmin->value]);

        $companyAdmin = User::updateOrCreate(
            ['email' => env('ERP_COMPANY_ADMIN_EMAIL', 'company.admin@example.com')],
            [
                'company_id' => $demo->id,
                'name' => env('ERP_COMPANY_ADMIN_NAME', 'Demo Company Admin'),
                'password' => Hash::make(env('ERP_COMPANY_ADMIN_PASSWORD', 'Admin#12345')),
            ]
        );
        $companyAdmin->syncRoles([UserRole::CompanyAdmin->value]);

        $this->ensureSubscription($demo, 'professional');

        Company::where('name', 'ManzomaTech Platform')
            ->whereDoesntHave('users')
            ->delete();
    }

    private function ensureSubscription(Company $company, string $planSlug): void
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->firstOrFail();

        CompanySubscription::updateOrCreate(
            [
                'company_id' => $company->id,
                'status' => 'active',
            ],
            [
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'starts_at' => now(),
                'provider' => 'seed',
                'metadata' => [
                    'seeded' => true,
                ],
            ]
        );
    }
}
