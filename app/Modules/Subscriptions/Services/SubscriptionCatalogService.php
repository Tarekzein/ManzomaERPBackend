<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\Contracts\FeatureRepository;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\DTOs\FeatureData;
use App\Modules\Subscriptions\DTOs\PlanData;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Policies\SubscriptionPolicy;
use Illuminate\Support\Facades\DB;

class SubscriptionCatalogService
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly FeatureRepository $features,
        private readonly SubscriptionPolicy $policy,
    ) {}

    public function plans()
    {
        return $this->plans->activeWithFeatures();
    }

    public function features()
    {
        return $this->features->grouped();
    }

    public function createPlan(User $actor, PlanData $data): SubscriptionPlan
    {
        $this->policy->ensureCanManageCatalog($actor);

        return $this->plans->create($data->attributes);
    }

    public function updatePlan(User $actor, SubscriptionPlan $plan, PlanData $data): SubscriptionPlan
    {
        $this->policy->ensureCanManageCatalog($actor);

        return $this->plans->update($plan, $data->attributes);
    }

    public function assignFeatures(User $actor, SubscriptionPlan $plan, array $features): SubscriptionPlan
    {
        $this->policy->ensureCanManageCatalog($actor);

        $sync = collect($features)->mapWithKeys(fn (array $feature) => [
            $feature['feature_id'] => [
                'enabled' => $feature['enabled'] ?? true,
                'value' => $feature['value'] ?? null,
            ],
        ])->all();

        return DB::transaction(fn () => $this->plans->syncFeatures($plan, $sync));
    }

    public function createFeature(User $actor, FeatureData $data): SubscriptionFeature
    {
        $this->policy->ensureCanManageCatalog($actor);

        return $this->features->create($data->attributes);
    }

    public function updateFeature(User $actor, SubscriptionFeature $feature, FeatureData $data): SubscriptionFeature
    {
        $this->policy->ensureCanManageCatalog($actor);

        return $this->features->update($feature, $data->attributes);
    }
}
