<?php

namespace App\Modules\Subscriptions\Contracts;

use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;

interface CompanySubscriptionRepository
{
    public function current(Company $company): ?CompanySubscription;

    /**
     * @param  array  $attributes  extra columns (billing period, payment method, …) for the new record
     */
    public function replaceActive(
        Company $company,
        SubscriptionPlan $plan,
        string $billingCycle,
        array $metadata = [],
        string $status = 'active',
        mixed $trialEndsAt = null,
        array $attributes = [],
    ): CompanySubscription;
}
