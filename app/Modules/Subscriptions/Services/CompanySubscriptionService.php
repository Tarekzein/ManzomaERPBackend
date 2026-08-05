<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Policies\SubscriptionPolicy;
use Illuminate\Support\Facades\DB;

class CompanySubscriptionService
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly SubscriptionPolicy $policy,
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    public function current(User $user): ?CompanySubscription
    {
        return $user->company ? $this->subscriptions->current($user->company) : null;
    }

    public function currentForCompany(Company $company): ?CompanySubscription
    {
        return $this->subscriptions->current($company);
    }

    public function ensureCanManageBilling(User $actor): void
    {
        $this->policy->ensureCanSubscribe($actor);
    }

    /**
     * Direct activation without a payment. Only free plans and super-admin
     * assignments take this path; paid plans go through Paymob checkout.
     */
    public function subscribe(User $actor, SubscribeData $data): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);

        return $this->start($actor->company, $data, ['subscribed_by_user_id' => $actor->id]);
    }

    public function start(Company $company, SubscribeData $data, array $metadata = [], array $attributes = []): CompanySubscription
    {
        return DB::transaction(function () use ($company, $data, $metadata, $attributes) {
            $plan = $this->plans->findActiveBySlug($data->planSlug);
            $company->update(['plan' => $plan->slug]);

            return $this->subscriptions->replaceActive(
                $company,
                $plan,
                $data->billingCycle,
                $metadata,
                SubscriptionStatus::Active->value,
                null,
                $attributes,
            );
        });
    }

    /**
     * A plan's trial is offered once per company, so switching plans cannot be
     * used to stack free periods.
     */
    public function trialEligible(?Company $company, SubscriptionPlan $plan): bool
    {
        return $company !== null
            && (bool) $plan->trial_enabled
            && (int) $plan->trial_days > 0
            && ! $this->hasUsedTrial($company);
    }

    public function hasUsedTrial(Company $company): bool
    {
        return $company->subscriptions()->whereNotNull('trial_ends_at')->exists();
    }

    /** Start a plan's free trial from inside the app. */
    public function startTrialFor(User $actor, SubscriptionPlan $plan, string $billingCycle): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);
        $company = $actor->company;

        abort_unless(
            $this->trialEligible($company, $plan),
            422,
            'This company has already used its free trial.'
        );

        $subscription = $this->startTrial(
            $company,
            new SubscribeData($plan->slug, $billingCycle),
            (int) $plan->trial_days,
            ['source' => 'in_app_trial', 'subscribed_by_user_id' => $actor->id],
        );

        // A company suspended for non-payment comes back with the trial.
        $this->lifecycle->restoreAccess($subscription);

        return $subscription->refresh()->load('plan.features');
    }

    public function startTrial(Company $company, SubscribeData $data, int $trialDays, array $metadata = []): CompanySubscription
    {
        return DB::transaction(function () use ($company, $data, $trialDays, $metadata) {
            $plan = $this->plans->findActiveBySlug($data->planSlug);
            $trialEndsAt = now()->addDays($trialDays);
            $company->update(['plan' => $plan->slug]);

            return $this->subscriptions->replaceActive(
                $company,
                $plan,
                $data->billingCycle,
                array_replace($metadata, ['trial_days' => $trialDays]),
                SubscriptionStatus::Trialing->value,
                $trialEndsAt,
            );
        });
    }

    /**
     * Cancelling keeps the paid period by default; `$immediately` ends access
     * and suspends the company right away.
     */
    public function cancel(User $actor, bool $immediately = false, ?string $reason = null): CompanySubscription
    {
        $subscription = $this->requireCurrent($actor);

        $subscription = $immediately
            ? $this->lifecycle->cancelImmediately($subscription, $reason)
            : $this->lifecycle->cancelAtPeriodEnd($subscription, $reason);

        return $subscription->load('plan.features');
    }

    /** Undo a scheduled cancellation while the period is still running. */
    public function resume(User $actor): CompanySubscription
    {
        $subscription = $this->requireCurrent($actor);

        abort_unless(
            $subscription->cancel_at_period_end || ! $subscription->auto_renew,
            422,
            'This subscription is already set to renew.'
        );
        abort_if(
            $subscription->periodEndsAt()?->isPast() === true && ! $subscription->inGracePeriod(),
            422,
            'This subscription has already ended. Start a new checkout to reactivate it.'
        );

        return $this->lifecycle->resume($subscription)->load('plan.features');
    }

    public function setAutoRenew(User $actor, bool $autoRenew): CompanySubscription
    {
        $subscription = $this->requireCurrent($actor);

        return $this->lifecycle->setAutoRenew($subscription, $autoRenew)->load('plan.features');
    }

    /** Drop the stored card so nothing is charged automatically again. */
    public function forgetPaymentMethod(User $actor): CompanySubscription
    {
        $subscription = $this->requireCurrent($actor);

        return $this->lifecycle->forgetPaymentMethod($subscription)->load('plan.features');
    }

    private function requireCurrent(User $actor): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);
        $subscription = $this->subscriptions->current($actor->company);
        abort_unless($subscription, 404, 'No active subscription was found.');

        return $subscription;
    }
}
