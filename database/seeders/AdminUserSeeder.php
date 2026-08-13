<?php

namespace Database\Seeders;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'name' => 'Demo Company',
                'status' => Organization::STATUS_ACTIVE,
                'billing_email' => env('ERP_COMPANY_ADMIN_EMAIL', 'company.admin@example.com'),
                'timezone' => 'Africa/Cairo',
                'locale' => 'en',
                'currency' => 'EGP',
                'settings' => [],
            ],
        );
        $demo = Company::firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'name' => 'Demo Company',
                'organization_id' => $organization->getKey(),
                'plan' => 'professional',
                'timezone' => 'Africa/Cairo',
                'locale' => 'en',
                'currency' => 'EGP',
                'is_active' => true,
            ]
        );

        $superAdmin = User::firstOrCreate(
            ['email' => env('ERP_SUPER_ADMIN_EMAIL', 'admin@manzomatech.com')],
            [
                'company_id' => null,
                'name' => env('ERP_SUPER_ADMIN_NAME', 'ManzomaTech Super Admin'),
                'password' => Hash::make(env('ERP_SUPER_ADMIN_PASSWORD', 'Admin#12345')),
            ]
        );
        $superAdmin->assignRole(UserRole::SuperAdmin->value);

        $companyAdmin = User::firstOrCreate(
            ['email' => env('ERP_COMPANY_ADMIN_EMAIL', 'company.admin@example.com')],
            [
                'company_id' => $demo->id,
                'default_company_id' => $demo->id,
                'name' => env('ERP_COMPANY_ADMIN_NAME', 'Demo Company Admin'),
                'password' => Hash::make(env('ERP_COMPANY_ADMIN_PASSWORD', 'Admin#12345')),
            ]
        );
        $companyAdmin->assignRole(UserRole::CompanyAdmin->value);

        $organization->forceFill(['created_by_user_id' => $companyAdmin->getKey()])->save();
        OrganizationMembership::query()->updateOrCreate(
            ['organization_id' => $organization->getKey(), 'user_id' => $companyAdmin->getKey()],
            [
                'role' => OrganizationMembership::ROLE_OWNER,
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'joined_at' => now(),
                'suspended_at' => null,
            ],
        );
        CompanyMembership::query()->updateOrCreate(
            ['company_id' => $demo->getKey(), 'user_id' => $companyAdmin->getKey()],
            [
                'organization_id' => $organization->getKey(),
                'role_id' => $companyAdmin->roles()->where('name', UserRole::CompanyAdmin->value)->value('roles.id'),
                'status' => CompanyMembership::STATUS_ACTIVE,
                'joined_at' => now(),
                'suspended_at' => null,
            ],
        );

        $this->ensureSubscription($demo, 'professional');

        // Do not remove similarly named legacy companies here. Data cleanup is
        // an explicit administrative operation, never a seeding side effect.
    }

    private function ensureSubscription(Company $company, string $planSlug): void
    {
        $plan = SubscriptionPlan::where('slug', $planSlug)->firstOrFail();

        CompanySubscription::firstOrCreate(
            [
                'company_id' => $company->id,
                'status' => 'active',
            ],
            [
                'subscription_plan_id' => $plan->id,
                'organization_id' => $company->organization_id,
                'entitlements_snapshot' => app(OrganizationEntitlementService::class)->snapshot($plan),
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
