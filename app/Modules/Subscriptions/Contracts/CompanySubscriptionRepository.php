<?php

namespace App\Modules\Subscriptions\Contracts;

use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;

interface CompanySubscriptionRepository
{
    public function current(Company $company): ?CompanySubscription;

    public function replaceActive(
        Company $company,
        SubscriptionPlan $plan,
        string $billingCycle,
        array $metadata = [],
    ): CompanySubscription;
}
