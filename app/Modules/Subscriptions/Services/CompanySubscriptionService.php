<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Policies\SubscriptionPolicy;
use Illuminate\Support\Facades\DB;

class CompanySubscriptionService
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly CompanySubscriptionRepository $subscriptions,
        private readonly SubscriptionPolicy $policy,
    ) {}

    public function current(User $user): ?CompanySubscription
    {
        return $user->company ? $this->subscriptions->current($user->company) : null;
    }

    public function subscribe(User $actor, SubscribeData $data): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);

        return $this->start($actor->company, $data, ['subscribed_by_user_id' => $actor->id]);
    }

    public function start(Company $company, SubscribeData $data, array $metadata = []): CompanySubscription
    {
        return DB::transaction(function () use ($company, $data, $metadata) {
            $plan = $this->plans->findActiveBySlug($data->planSlug);
            $company->update(['plan' => $plan->slug]);

            return $this->subscriptions->replaceActive($company, $plan, $data->billingCycle, $metadata);
        });
    }

    public function cancel(User $actor): CompanySubscription
    {
        $this->policy->ensureCanSubscribe($actor);
        $subscription = $this->subscriptions->current($actor->company);
        abort_unless($subscription, 404, 'No active subscription was found.');
        $subscription->update(['status' => 'cancelled', 'cancelled_at' => now(), 'ends_at' => now()]);

        return $subscription->refresh()->load('plan.features');
    }
}
