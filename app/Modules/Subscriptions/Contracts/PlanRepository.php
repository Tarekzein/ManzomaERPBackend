<?php

namespace App\Modules\Subscriptions\Contracts;

use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Collection;

interface PlanRepository
{
    public function activeWithFeatures(): Collection;

    public function findActiveBySlug(string $slug): SubscriptionPlan;

    public function create(array $attributes): SubscriptionPlan;

    public function update(SubscriptionPlan $plan, array $attributes): SubscriptionPlan;

    public function syncFeatures(SubscriptionPlan $plan, array $features): SubscriptionPlan;
}
