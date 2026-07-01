<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\Contracts\FeatureRepository;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\DTOs\FeatureData;
use App\Modules\Subscriptions\DTOs\PlanData;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Models\SubscriptionPlanPromotion;
use App\Modules\Subscriptions\Policies\SubscriptionPolicy;
use Illuminate\Support\Facades\DB;

class SubscriptionCatalogService
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly FeatureRepository $features,
        private readonly SubscriptionPolicy $policy,
        private readonly PlanPricingService $pricing,
    ) {}

    public function plans()
    {
        return $this->pricing->decorateMany($this->plans->activeWithFeatures());
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

    public function savePlanFeature(
        User $actor,
        SubscriptionPlan $plan,
        SubscriptionFeature $feature,
        array $attributes,
    ): SubscriptionPlan {
        $this->policy->ensureCanManageCatalog($actor);

        return DB::transaction(fn () => $this->plans->upsertFeature($plan, $feature, [
            'enabled' => $attributes['enabled'] ?? true,
            'value' => $attributes['value'] ?? null,
        ]));
    }

    public function removePlanFeature(
        User $actor,
        SubscriptionPlan $plan,
        SubscriptionFeature $feature,
    ): SubscriptionPlan {
        $this->policy->ensureCanManageCatalog($actor);

        return DB::transaction(fn () => $this->plans->removeFeature($plan, $feature));
    }

    public function promotions(User $actor, SubscriptionPlan $plan)
    {
        $this->policy->ensureCanManageCatalog($actor);

        return $plan->promotions()->orderByDesc('starts_at')->get();
    }

    public function createPromotion(User $actor, SubscriptionPlan $plan, array $attributes): SubscriptionPlanPromotion
    {
        $this->policy->ensureCanManageCatalog($actor);

        return $plan->promotions()->create($attributes);
    }

    public function updatePromotion(
        User $actor,
        SubscriptionPlan $plan,
        SubscriptionPlanPromotion $promotion,
        array $attributes,
    ): SubscriptionPlanPromotion {
        $this->policy->ensureCanManageCatalog($actor);

        $promotion->update($attributes);

        return $promotion->refresh();
    }

    public function deletePromotion(
        User $actor,
        SubscriptionPlan $plan,
        SubscriptionPlanPromotion $promotion,
    ): null {
        $this->policy->ensureCanManageCatalog($actor);
        $promotion->delete();

        return null;
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
