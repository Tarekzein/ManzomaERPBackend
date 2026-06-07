<?php

namespace App\Modules\Subscriptions\Repositories;

use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Collection;

class EloquentPlanRepository implements PlanRepository
{
    public function activeWithFeatures(): Collection
    {
        return SubscriptionPlan::with('features')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function findActiveBySlug(string $slug): SubscriptionPlan
    {
        return SubscriptionPlan::where('slug', $slug)->where('is_active', true)->firstOrFail();
    }

    public function create(array $attributes): SubscriptionPlan
    {
        return SubscriptionPlan::create($attributes);
    }

    public function update(SubscriptionPlan $plan, array $attributes): SubscriptionPlan
    {
        $plan->update($attributes);

        return $plan->refresh();
    }

    public function syncFeatures(SubscriptionPlan $plan, array $features): SubscriptionPlan
    {
        $plan->features()->sync($features);

        return $plan->load('features');
    }

    public function upsertFeature(SubscriptionPlan $plan, SubscriptionFeature $feature, array $attributes): SubscriptionPlan
    {
        $plan->features()->syncWithoutDetaching([$feature->id => $attributes]);

        return $plan->load('features');
    }

    public function removeFeature(SubscriptionPlan $plan, SubscriptionFeature $feature): SubscriptionPlan
    {
        $plan->features()->detach($feature->id);

        return $plan->load('features');
    }
}
