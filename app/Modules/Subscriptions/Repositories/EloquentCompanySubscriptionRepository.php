<?php

namespace App\Modules\Subscriptions\Repositories;

use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Support\BillingPeriod;
use Illuminate\Support\Carbon;

class EloquentCompanySubscriptionRepository implements CompanySubscriptionRepository
{
    public function current(Company $company): ?CompanySubscription
    {
        return $company->subscriptions()
            ->with('plan.features')
            ->whereIn('status', SubscriptionStatus::servingValues())
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
        array $attributes = [],
    ): CompanySubscription {
        $previous = $this->current($company);

        $company->subscriptions()->whereIn('status', SubscriptionStatus::servingValues())->update([
            'status' => SubscriptionStatus::Cancelled->value,
            'cancelled_at' => now(),
            'cancellation_reason' => $attributes['replacement_reason'] ?? 'replaced_by_new_subscription',
            'ends_at' => now(),
            'auto_renew' => false,
        ]);
        unset($attributes['replacement_reason']);

        $startsAt = now();
        $trialEnd = $trialEndsAt ? Carbon::parse($trialEndsAt) : null;
        $periodEnd = $status === SubscriptionStatus::Trialing->value && $trialEnd
            ? $trialEnd
            : BillingPeriod::end($billingCycle, $startsAt);

        return $company->subscriptions()->create(array_replace([
            'subscription_plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => $billingCycle,
            'auto_renew' => true,
            'cancel_at_period_end' => false,
            'starts_at' => $startsAt,
            'current_period_started_at' => $startsAt,
            'current_period_ends_at' => $periodEnd,
            'trial_ends_at' => $trialEnd,
            'provider' => 'paymob',
            // Carry a saved card over so a plan change does not force the
            // customer to re-enter their card for the next renewal.
            'payment_method_token' => $previous?->payment_method_token,
            'payment_method_brand' => $previous?->payment_method_brand,
            'payment_method_last4' => $previous?->payment_method_last4,
            'metadata' => $metadata,
        ], $attributes))->load('plan.features');
    }
}
