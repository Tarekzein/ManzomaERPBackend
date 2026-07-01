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
        return $company->subscriptions()
            ->with('plan.features')
            ->whereIn('status', ['active', 'trialing'])
            ->latest()
            ->first();
    }

    public function replaceActive(
        Company $company,
        SubscriptionPlan $plan,
        string $billingCycle,
        array $metadata = [],
        string $status = 'active',
        mixed $trialEndsAt = null,
    ): CompanySubscription {
        $company->subscriptions()->whereIn('status', ['active', 'trialing'])->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => now(),
        ]);

        return $company->subscriptions()->create([
            'subscription_plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => $billingCycle,
            'starts_at' => now(),
            'trial_ends_at' => $trialEndsAt,
            'provider' => 'internal',
            'metadata' => $metadata,
        ])->load('plan.features');
    }
}
