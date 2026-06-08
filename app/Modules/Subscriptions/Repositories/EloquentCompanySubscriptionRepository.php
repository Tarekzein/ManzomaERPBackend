<?php

namespace App\Modules\Subscriptions\Repositories;

use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;

class EloquentCompanySubscriptionRepository implements CompanySubscriptionRepository
{
    public function current(Company $company): ?CompanySubscription
    {
        return $company->subscription()->with('plan.features')->first();
    }

    public function replaceActive(
        Company $company,
        SubscriptionPlan $plan,
        string $billingCycle,
        array $metadata = [],
    ): CompanySubscription {
        $company->subscriptions()->where('status', 'active')->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => now(),
        ]);

        return $company->subscriptions()->create([
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => $billingCycle,
            'starts_at' => now(),
            'provider' => 'internal',
            'metadata' => $metadata,
        ])->load('plan.features');
    }
}
