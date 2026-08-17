<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Policies\SubscriptionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CompanySubscriptionService
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly SubscriptionPolicy $policy,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly OrganizationQuotaService $quotas,
        private readonly CompanyContext $context,
    ) {}

    public function current(User $user): ?CompanySubscription
    {
        $company = $this->context->companyFor($user);

        return $company ? $this->subscriptions->current($company) : null;
    }

    public function companyFor(User $user): Company
    {
        $company = $this->context->companyFor($user);
        abort_unless($company, 422, 'An active company workspace is required.');

        return $company;
    }

    public function currentForCompany(Company $company): ?CompanySubscription
    {
        return $this->subscriptions->current($company);
    }

    public function ensureCanManageBilling(User $actor): void
    {
        $this->policy->ensureCanSubscribe($actor);
    }

    public function ensurePlanFits(Company $company, SubscriptionPlan $plan): void
    {
        if ($company->organization) {
            $this->quotas->ensurePlanFits($company->organization, $plan);
        }
    }

    public function quotaUsage(Company $company): ?array
    {
        return $company->organization ? $this->quotas->usage($company->organization) : null;
    }

    /**
     * Direct activation without a payment. Only free plans and super-admin
     * assignments take this path; paid plans go through Paymob checkout.
     */
    public function subscribe(User $actor, SubscribeData $data): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);

        return $this->start($this->companyFor($actor), $data, ['subscribed_by_user_id' => $actor->id]);
    }

    public function start(Company $company, SubscribeData $data, array $metadata = [], array $attributes = []): CompanySubscription
    {
        return DB::transaction(function () use ($company, $data, $metadata, $attributes) {
            $this->lockOrganization($company);
            $plan = $this->plans->findActiveBySlug($data->planSlug);
            $this->ensurePlanFits($company, $plan);
            $this->projectPlanToCompanies($company, $plan);

            $subscription = $this->subscriptions->replaceActive(
                $company,
                $plan,
                $data->billingCycle,
                $metadata,
                SubscriptionStatus::Active->value,
                null,
                $attributes,
            );

            // Starting a new serving subscription is also the recovery path
            // after an organization (or legacy company) settles an overdue
            // bill. Keep administrative company suspension independent: the
            // lifecycle service only clears the billing-owned suspension.
            $this->lifecycle->restoreAccess($subscription);

            return $subscription->refresh()->load('plan.features');
        });
    }

    /**
     * A plan's trial is offered once per organization, so switching plans cannot be
     * used to stack free periods.
     */
    public function trialEligible(?Company $company, SubscriptionPlan $plan): bool
    {
        return $company !== null
            && (bool) $plan->trial_enabled
            && (int) $plan->trial_days > 0
            && $this->trialAvailable($company);
    }

    /**
     * A trial is an offer made before an organization commits, so it is gone
     * once the organization has subscribed at all: a paid plan is not turned
     * back into a trial by switching to a plan that advertises one.
     */
    public function trialAvailable(Company $company): bool
    {
        return ! $this->hasUsedTrial($company) && ! $this->hasSubscribed($company);
    }

    public function hasUsedTrial(Company $company): bool
    {
        return $this->subscriptionHistory($company)->whereNotNull('trial_ends_at')->exists();
    }

    /** Any subscription the organization has ever held, current or closed. */
    public function hasSubscribed(Company $company): bool
    {
        return $this->subscriptionHistory($company)->exists();
    }

    private function subscriptionHistory(Company $company): Builder
    {
        if ($company->organization_id !== null) {
            return CompanySubscription::query()->where('organization_id', $company->organization_id);
        }

        return $company->subscriptions()->getQuery();
    }

    /** Start a plan's free trial from inside the app. */
    public function startTrialFor(User $actor, SubscriptionPlan $plan, string $billingCycle): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);
        $company = $this->companyFor($actor);

        abort_unless(
            $this->trialEligible($company, $plan),
            422,
            'A free trial is only available before an organization subscribes.'
        );

        return $this->startTrial(
            $company,
            new SubscribeData($plan->slug, $billingCycle),
            (int) $plan->trial_days,
            ['source' => 'in_app_trial', 'subscribed_by_user_id' => $actor->id],
        );
    }

    public function startTrial(Company $company, SubscribeData $data, int $trialDays, array $metadata = []): CompanySubscription
    {
        return DB::transaction(function () use ($company, $data, $trialDays, $metadata) {
            $this->lockOrganization($company);
            abort_if(
                $this->hasUsedTrial($company),
                422,
                'This organization has already used its free trial.'
            );
            $plan = $this->plans->findActiveBySlug($data->planSlug);
            $this->ensurePlanFits($company, $plan);
            $trialEndsAt = now()->addDays($trialDays);
            $this->projectPlanToCompanies($company, $plan);

            $subscription = $this->subscriptions->replaceActive(
                $company,
                $plan,
                $data->billingCycle,
                array_replace($metadata, ['trial_days' => $trialDays]),
                SubscriptionStatus::Trialing->value,
                $trialEndsAt,
            );

            $this->lifecycle->restoreAccess($subscription);

            return $subscription->refresh()->load('plan.features');
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
        $subscription = $this->subscriptions->current($this->companyFor($actor));
        abort_unless($subscription, 404, 'No active subscription was found.');

        return $subscription;
    }

    private function lockOrganization(Company $company): ?Organization
    {
        if ($company->organization_id === null) {
            Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();

            return null;
        }

        return Organization::query()->whereKey($company->organization_id)->lockForUpdate()->firstOrFail();
    }

    private function projectPlanToCompanies(Company $company, SubscriptionPlan $plan): void
    {
        if ($company->organization_id === null) {
            $company->update(['plan' => $plan->slug]);

            return;
        }

        Company::query()
            ->where('organization_id', $company->organization_id)
            ->update(['plan' => $plan->slug]);
    }
}
